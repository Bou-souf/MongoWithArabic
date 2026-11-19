# Suivi des compétences : application web

Application web pour les enseignants. Elle analyse les contrôles PDF et suit les compétences et les lacunes des élèves d'un contrôle à l'autre.

**Stack :** Node.js 20+, Express 5, MongoDB (Mongoose), EJS, Bootstrap 5, Chart.js. Toutes les bibliothèques sont servies en local et l'application fonctionne sans accès à Internet.

## Fonctionnalités

| # | Fonction | Où |
|---|----------|----|
| 1 | **Analyse d'un contrôle PDF** : extraction du texte, découpage en exercices et questions, lecture du barème. | *Contrôles → Nouveau contrôle* |
| 2 | **Extraction des compétences** de chaque question. Le système s'appuie sur un référentiel de verbes d'action (français + arabe), inspiré de la taxonomie de Bloom. Une analyse IA (Claude) est disponible en option. Le professeur corrige ou complète la liste, avec des suggestions tirées des contrôles précédents. | *Contrôle → Compétences* |
| 3 | **Grille d'évaluation** : la liste des élèves vient de l'Excel des notes. Le professeur coche les compétences maîtrisées (cocher une ligne ou une colonne entière est possible). Une rubrique « Autres lacunes » sert aux lacunes que le système n'a pas proposées. | *Contrôle → Évaluer* |
| 4 | **Tableau de bord** : pourcentage de compétences maîtrisées et de lacunes (global et par compétence), statistiques des notes, **groupes de besoin** (élèves regroupés par compétence non maîtrisée) et **profils** (élèves qui ont les mêmes lacunes). | *Contrôle → Tableau de bord* |
| 5 | **Statistiques par classe et par matière** : évolution de la moyenne, du taux de réussite et du taux de maîtrise d'un contrôle à l'autre, ainsi que la tendance de chaque compétence. Une fiche par élève retrace son évolution. | *Statistiques*, *fiche élève* |
| 6 | **Suivi des lacunes** : à chaque nouveau contrôle de la même matière, la grille affiche les lacunes antérieures de chaque élève. Le professeur confirme pour chacune : **remédiée** (inscrit dans l'évolution de l'élève) ou **non remédiée** (déclenche une **alerte** : « à surveiller » au 1er constat, « urgente » à partir du 2e). | *Évaluer* (lignes « suivi »), *Alertes*, *fiche élève* |

**Authentification obligatoire**, avec deux rôles :
- **Professeur** : il voit et gère uniquement ses classes, contrôles et élèves.
- **Administrateur** : il voit tout, crée et modifie les comptes (rôle, activation, mot de passe, matières) et peut affecter une classe à un autre professeur.

Sécurité : mots de passe hachés (bcrypt), sessions stockées dans MongoDB, cookie `httpOnly`, protection CSRF sur tous les formulaires, cloisonnement des données par professeur.

## Démarrage rapide (démo, sans installer MongoDB)

```bash
cd competences-app
npm install
npm run demo          # base MongoDB temporaire en mémoire + données de démonstration
```
Ouvrez http://localhost:3000. Comptes de démonstration :
- Professeur : `prof@ecole.local` / `prof123`
- Administrateur : `admin@ecole.local` / `admin123`

La base en mémoire est effacée à chaque arrêt.

## Installation avec MongoDB

```bash
cd competences-app
npm install
cp .env.example .env  # renseignez MONGODB_URI, SESSION_SECRET, etc.
npm start
```
Au premier démarrage, l'application crée un compte administrateur avec `ADMIN_EMAIL` / `ADMIN_PASSWORD` (par défaut `admin@ecole.local` / `admin123`). **Changez ce mot de passe** dans *Mon profil*.

`npm run seed` ajoute les données de démonstration à la base configurée.

### Analyse IA (facultatif)
Si `ANTHROPIC_API_KEY` est défini, la case « Analyse avancée par IA » est proposée à la création d'un contrôle. Le PDF est alors envoyé à Claude (modèle `CLAUDE_MODEL`, par défaut `claude-opus-5-5`), qui renvoie les questions et des compétences plus fines (ex. « Résoudre une équation du premier degré »). En cas d'erreur, l'application revient automatiquement à l'analyse par règles. Les PDF scannés sans couche texte ne sont lisibles qu'avec l'analyse IA.

## Format du fichier Excel des notes

La première feuille est lue. Les lignes de titre au-dessus de l'en-tête sont ignorées, ce qui permet d'importer directement les exports Massar. Colonnes reconnues (sans tenir compte des majuscules ni des accents) :

| Donnée | En-têtes acceptés |
|---|---|
| Nom / Prénom | `Nom`, `Prénom` (ou une colonne unique `Nom et prénom`, `Élève`, `Nom complet`) |
| Code | `Code`, `Code Massar`, `Matricule`, `N°` |
| Note | `Note`, `Note /20`, `Moyenne`, `Résultat`, `النقطة` |

Les élèves sont rapprochés par code, puis par nom et prénom. Les élèves inconnus sont ajoutés automatiquement à la classe. Des exemples se trouvent dans `exemples/` (`notes-3APIC-2.xlsx`, `controle-maths-1.pdf`, `controle-maths-2.pdf`).

## Tests

```bash
npm test
```
Les tests unitaires couvrent l'analyse des PDF, la détection des compétences et la lecture Excel. Un test de bout en bout lance une base MongoDB en mémoire et parcourt tout le cycle par HTTP : connexion, création de classe, dépôt d'un PDF et d'un Excel, évaluation, tableau de bord, second contrôle, suivi des lacunes, alertes, cloisonnement entre professeurs, rôle admin, suppression.

## Structure

```
src/
  app.js, server.js, config.js
  models/        User, Classe, Eleve, Controle (questions + compétences), Evaluation, Lacune (+ suivis)
  services/      pdfAnalyzer (règles / IA), competencesRules (référentiel), excelImport, lacunes, stats
  routes/        auth, accueil (accueil, statistiques, alertes), admin, classes, controles, eleves
  views/         pages EJS
scripts/         seed.js, demo-memoire.js, générateurs d'exemples
test/            unit.test.js, integration.test.js
```

## Cycle de vie d'une lacune

1. Une compétence non cochée lors d'un contrôle (ou une lacune saisie par le professeur) crée une lacune **ouverte**.
2. Au contrôle suivant de la même matière, la lacune apparaît dans une ligne « suivi » de la grille. Si la compétence est de nouveau évaluée, la réponse est pré-remplie selon la case cochée ; le professeur confirme.
3. **Remédiée** : la lacune est close et l'évolution de l'élève indique le contrôle de remédiation.
   **Non remédiée** : la lacune reste ouverte et passe en **alerte**.
4. Les alertes sont classées par priorité dans la page *Alertes*. Les lacunes ouvertes depuis plus de 30 jours sans suivi y sont aussi signalées.
