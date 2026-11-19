# Suivi des compétences : version PHP (Slim 4 + MySQL)

Cette version de l'application est en **PHP 8.1+ / Slim 4 / Twig / MySQL**. Elle est conçue pour un **hébergement mutualisé OVH** (ou tout hébergeur PHP + MySQL avec Apache). Les fonctionnalités et les adresses des pages sont les mêmes que dans la version Node.js (`../competences-app`).

## Fonctionnalités
1. **Analyse d'un contrôle PDF** : découpage en exercices et questions, lecture du barème.
2. **Compétences par question** : déduites des verbes d'action (français + arabe). Analyse IA facultative avec Claude. Le professeur peut corriger, avec des suggestions tirées des contrôles précédents.
3. **Import Excel des notes** (exports Massar acceptés) et **grille d'évaluation** : cases à cocher pour les compétences maîtrisées, plus une rubrique « Autres lacunes ».
4. **Tableau de bord** : pourcentage de compétences maîtrisées et de lacunes, **groupes de besoin**, **profils** d'élèves, statistiques des notes.
5. **Statistiques** par classe et par matière, et **fiche d'évolution** de chaque élève.
6. **Suivi des lacunes** : au contrôle suivant, le professeur confirme « remédiée » (mentionné dans l'évolution de l'élève) ou « non remédiée » (déclenche une **alerte**).

**Connexion obligatoire**, avec deux rôles : **professeur** (accès à ses propres classes) et **administrateur** (tout, plus la gestion des comptes). Mots de passe hachés, protection CSRF, cookie de session `HttpOnly` (et `Secure` en HTTPS).

---

## Déploiement sur un hébergement mutualisé OVH

### 1. Préparer l'hébergement (espace client OVH)
1. **Version de PHP** : l'application demande PHP 8.1 ou plus. Copiez `ovhconfig.example` sous le nom **`.ovhconfig`** à la racine de votre espace FTP. Il règle PHP en 8.3 ; vous pouvez aussi changer la version dans *Hébergements → Informations générales → Version PHP*.
2. **Base de données** : dans *Hébergements → Bases de données → Créer une base de données*, choisissez MySQL. Notez le **serveur** (du type `xxxxx.mysql.db`), le **nom de la base**, l'**utilisateur** et le **mot de passe**.
3. **HTTPS** : activez le certificat SSL gratuit (Let's Encrypt) dans *Hébergements → Multisite*.

### 2. Construire l'archive (sur votre ordinateur)
Il faut PHP 8.1+ et [Composer](https://getcomposer.org) :
```bash
cd competences-php
bin/package.sh            # crée build/competences-php.zip (environ 10 Mo, dépendances incluses)
```
Sous Windows sans bash :
```bash
composer install --no-dev --optimize-autoloader
```
Puis zippez le dossier, sans `tests/` ni `config.php`.

### 3. Envoyer les fichiers (FTP, par exemple avec FileZilla)
- Décompressez l'archive et envoyez le dossier `competences/` dans votre espace FTP, par exemple dans `www/competences/`.
- **Recommandé** : dans *Multisite*, faites pointer votre domaine ou sous-domaine (ex. `competences.mon-ecole.ma`) sur le dossier **`www/competences/public`**.
- **Sinon** : faites pointer le domaine sur `www/competences/`. Le fichier `.htaccess` fourni redirige alors tout vers `public/`, et `config.php`, `vendor/` et `storage/` restent inaccessibles depuis le web.

> L'application doit être servie à la racine d'un domaine ou d'un sous-domaine, pas dans un sous-dossier d'URL du type `mon-site.ma/competences`.

### 4. Configurer
Copiez `config.example.php` en **`config.php`** (dans `competences/`, à côté de `composer.json`) et renseignez la base :
```php
'db' => [
    'host' => 'xxxxx.mysql.db',
    'port' => 3306,
    'name' => 'nom_de_la_base',
    'user' => 'utilisateur',
    'password' => 'mot_de_passe',
],
```
Vérifiez que le dossier `storage/uploads/` est accessible en écriture (droits 755 ou 775).

### 5. Installer
Ouvrez **`https://votre-domaine/install`**. Les tables sont créées et vous choisissez le compte administrateur. Cette page se désactive automatiquement dès qu'un compte existe.

Si vous avez un accès SSH (offres Pro et Performance), vous pouvez faire à la place :
```bash
php bin/install.php admin@mon-ecole.ma MotDePasseSolide "Nom de l'admin"
php bin/seed.php      # facultatif : données de démonstration
```

### 6. Analyse IA (facultatif)
Renseignez `anthropic_api_key` dans `config.php`. La case « Analyse avancée par IA » apparaît alors à la création d'un contrôle. Si l'appel échoue (clé invalide, connexion sortante bloquée…), l'application utilise automatiquement l'analyse par règles. Seule l'analyse IA peut lire un PDF scanné sans texte.

### Mise à jour
Reconstruisez l'archive, puis renvoyez tous les fichiers **sauf** `config.php` et `storage/uploads/`. Le fichier `sql/schema.sql` utilise `CREATE TABLE IF NOT EXISTS`, ce qui permet de le relancer sans risque.

### Sauvegardes
- La base est sauvegardée automatiquement par OVH ; vous pouvez aussi en exporter une copie depuis l'espace client (phpMyAdmin).
- Pensez aussi à sauvegarder le dossier `storage/uploads/`, qui contient les PDF des contrôles.

---

## Développement local
```bash
composer install
cp config.example.php config.php        # renseignez une base MySQL/MariaDB locale
php bin/install.php admin@ecole.local admin12345
php bin/seed.php                        # facultatif : démo (prof@ecole.local / prof123)
php -S localhost:8000 -t public public/index.php
```

## Tests
```bash
composer test
```
- Les tests unitaires couvrent l'analyse des PDF, la détection des compétences et la lecture Excel.
- Le test de bout en bout installe le schéma dans une base de test, lance le serveur PHP et parcourt tout le cycle par HTTP : installation, connexion, classe, PDF + Excel, évaluation, tableau de bord, second contrôle, suivi des lacunes, alertes, cloisonnement entre professeurs, rôle admin, suppression.
- Base de test : `competences_test` sur `127.0.0.1` (utilisateur et mot de passe `comp`). Pour en changer, utilisez les variables `TEST_DB_HOST`, `TEST_DB_NAME`, `TEST_DB_USER` et `TEST_DB_PASSWORD`.

## Format du fichier Excel
La première feuille est lue, et les lignes de titre au-dessus de l'en-tête sont ignorées (exports Massar). Colonnes reconnues : `Nom` + `Prénom` (ou une colonne unique `Nom et prénom` / `Élève`), `Code` / `Massar` / `Matricule`, et `Note` / `Moyenne` / `النقطة`. Formats acceptés : `.xlsx`, `.xls`, `.ods`, `.csv`.

## Structure
```
public/          index.php, .htaccess, assets (Bootstrap, icônes, Chart.js en local)
src/             App.php (routes), Auth, Db, Middleware, Vue
  Controller/    Auth, Accueil (accueil, statistiques, alertes), Admin, Classes, Controles, Eleves, Install
  Service/       PdfAnalyzer, CompetencesRules, ExcelImport, Lacunes, Stats, Repo, Eleves, Texte
templates/       vues Twig
sql/schema.sql   schéma MySQL
bin/             install.php, seed.php, package.sh
tests/           PHPUnit (unitaires + bout en bout)
```
