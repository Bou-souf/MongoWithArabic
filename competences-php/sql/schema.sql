-- Schéma MySQL / MariaDB (utf8mb4, InnoDB)
CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nom VARCHAR(150) NOT NULL,
  email VARCHAR(190) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('admin','prof') NOT NULL DEFAULT 'prof',
  matieres VARCHAR(500) NOT NULL DEFAULT '',
  actif TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS classes (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nom VARCHAR(100) NOT NULL,
  niveau VARCHAR(100) NOT NULL DEFAULT '',
  annee_scolaire VARCHAR(20) NOT NULL DEFAULT '',
  prof_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_classe (prof_id, nom, annee_scolaire),
  CONSTRAINT fk_classe_prof FOREIGN KEY (prof_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS eleves (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nom VARCHAR(100) NOT NULL,
  prenom VARCHAR(100) NOT NULL DEFAULT '',
  code VARCHAR(50) NULL,
  classe_id INT UNSIGNED NOT NULL,
  cle VARCHAR(220) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_eleve_classe (classe_id, cle),
  CONSTRAINT fk_eleve_classe FOREIGN KEY (classe_id) REFERENCES classes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS controles (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  titre VARCHAR(200) NOT NULL,
  matiere VARCHAR(100) NOT NULL,
  classe_id INT UNSIGNED NOT NULL,
  prof_id INT UNSIGNED NOT NULL,
  date DATE NOT NULL,
  note_sur DECIMAL(6,2) NOT NULL DEFAULT 20,
  fichier_pdf VARCHAR(100) NULL,
  nom_fichier_original VARCHAR(255) NULL,
  texte MEDIUMTEXT NULL,
  methode_analyse ENUM('regles','ia','manuel') NOT NULL DEFAULT 'regles',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_controle_classe (classe_id, matiere, date),
  CONSTRAINT fk_controle_classe FOREIGN KEY (classe_id) REFERENCES classes(id),
  CONSTRAINT fk_controle_prof FOREIGN KEY (prof_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS questions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  controle_id INT UNSIGNED NOT NULL,
  ordre INT NOT NULL,
  numero VARCHAR(30) NOT NULL DEFAULT '',
  enonce TEXT NULL,
  bareme DECIMAL(6,2) NULL,
  CONSTRAINT fk_question_controle FOREIGN KEY (controle_id) REFERENCES controles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS question_competences (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  question_id INT UNSIGNED NOT NULL,
  competence VARCHAR(255) NOT NULL,
  CONSTRAINT fk_qc_question FOREIGN KEY (question_id) REFERENCES questions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS evaluations (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  controle_id INT UNSIGNED NOT NULL,
  eleve_id INT UNSIGNED NOT NULL,
  note DECIMAL(6,2) NULL,
  evaluee TINYINT(1) NOT NULL DEFAULT 0,
  commentaire TEXT NULL,
  UNIQUE KEY uq_eval (controle_id, eleve_id),
  CONSTRAINT fk_eval_controle FOREIGN KEY (controle_id) REFERENCES controles(id) ON DELETE CASCADE,
  CONSTRAINT fk_eval_eleve FOREIGN KEY (eleve_id) REFERENCES eleves(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- statut : maitrisee | non_maitrisee | libre (lacune saisie par le professeur)
CREATE TABLE IF NOT EXISTS evaluation_competences (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  evaluation_id INT UNSIGNED NOT NULL,
  competence VARCHAR(255) NOT NULL,
  statut ENUM('maitrisee','non_maitrisee','libre') NOT NULL,
  CONSTRAINT fk_ec_eval FOREIGN KEY (evaluation_id) REFERENCES evaluations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lacunes (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  eleve_id INT UNSIGNED NOT NULL,
  matiere VARCHAR(100) NOT NULL,
  competence VARCHAR(255) NOT NULL,
  origine ENUM('systeme','professeur') NOT NULL DEFAULT 'systeme',
  controle_origine_id INT UNSIGNED NULL,
  statut ENUM('ouverte','remediee') NOT NULL DEFAULT 'ouverte',
  date_remediation DATE NULL,
  controle_remediation_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_lacune (eleve_id, matiere, statut),
  CONSTRAINT fk_lacune_eleve FOREIGN KEY (eleve_id) REFERENCES eleves(id) ON DELETE CASCADE,
  CONSTRAINT fk_lacune_origine FOREIGN KEY (controle_origine_id) REFERENCES controles(id) ON DELETE CASCADE,
  CONSTRAINT fk_lacune_remediation FOREIGN KEY (controle_remediation_id) REFERENCES controles(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lacune_suivis (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  lacune_id INT UNSIGNED NOT NULL,
  controle_id INT UNSIGNED NULL,
  date DATE NOT NULL,
  remediee TINYINT(1) NOT NULL,
  commentaire VARCHAR(500) NOT NULL DEFAULT '',
  CONSTRAINT fk_suivi_lacune FOREIGN KEY (lacune_id) REFERENCES lacunes(id) ON DELETE CASCADE,
  CONSTRAINT fk_suivi_controle FOREIGN KEY (controle_id) REFERENCES controles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
