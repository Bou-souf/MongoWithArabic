<?php
// Données de démonstration : php bin/seed.php
// Crée admin@ecole.local / admin123, prof@ecole.local / prof123, une classe et deux contrôles évalués.
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';

use App\Config;
use App\Db;
use App\Service\Eleves;
use App\Service\ExcelImport;
use App\Service\Lacunes;
use App\Service\PdfAnalyzer;
use App\Service\Repo;

Db::installerSchema();
if (Db::val("SELECT id FROM users WHERE email = 'prof@ecole.local'")) {
    exit("Données de démonstration déjà présentes.\n");
}
if (!Db::val("SELECT id FROM users WHERE email = 'admin@ecole.local'")) {
    Db::insert('users', ['nom' => 'Administrateur', 'email' => 'admin@ecole.local', 'password_hash' => password_hash('admin123', PASSWORD_DEFAULT), 'role' => 'admin']);
}
$prof = Db::insert('users', ['nom' => 'Mme Benali', 'email' => 'prof@ecole.local', 'password_hash' => password_hash('prof123', PASSWORD_DEFAULT), 'role' => 'prof', 'matieres' => 'Mathématiques']);
$classe = Db::insert('classes', ['nom' => '3APIC-2', 'niveau' => '3e année collège', 'annee_scolaire' => '2026-2027', 'prof_id' => $prof]);

$ex = dirname(__DIR__) . '/exemples/';
$lignes = ExcelImport::lireFichier($ex . 'notes-3APIC-2.xlsx')['eleves'];
$eleves = array_column(Eleves::synchroniser($classe, $lignes)['resultat'], 'eleve_id');

$dossier = Config::get('upload_dir');
@mkdir($dossier, 0750, true);
$sujets = [
    ['controle-maths-1.pdf', 'Contrôle 1 — Calcul et équations', '2026-10-10'],
    ['controle-maths-2.pdf', 'Contrôle 2 — Équations et statistiques', '2026-11-12'],
];
foreach ($sujets as $n => [$fichier, $titre, $date]) {
    $contenu = file_get_contents($ex . $fichier);
    $a = PdfAnalyzer::analyser($contenu, 'Mathématiques', false);
    $nom = 'demo-' . ($n + 1) . '.pdf';
    file_put_contents("$dossier/$nom", $contenu);
    $id = Db::insert('controles', ['titre' => $titre, 'matiere' => 'Mathématiques', 'classe_id' => $classe, 'prof_id' => $prof, 'date' => $date,
        'fichier_pdf' => $nom, 'nom_fichier_original' => $fichier, 'texte' => $a['texte'], 'methode_analyse' => $a['methode']]);
    Repo::enregistrerQuestions($id, $a['questions']);
    $c = Repo::controle($id);
    $comps = $c['competences'];
    $suivis = Lacunes::aSuivre($c, $eleves);
    $reponses = [];
    foreach ($eleves as $i => $eid) {
        $note = $n === 0 ? $lignes[$i]['note'] : min(20, max(0, $lignes[$i]['note'] + (($i % 3) - 1) * 1.5));
        // Plus la note est faible, plus il y a de compétences non maîtrisées (déterministe)
        $nb = (int) round((20 - $note) / 20 * count($comps) * 0.8);
        $ordre = array_keys($comps);
        usort($ordre, fn ($x, $y) => (($x * 7 + $i * 3) % count($comps)) <=> (($y * 7 + $i * 3) % count($comps)));
        $non = array_slice($ordre, 0, $nb);
        Db::insert('evaluations', ['controle_id' => $id, 'eleve_id' => $eid, 'note' => $note, 'evaluee' => 0]);
        Lacunes::enregistrerEvaluation($c, $eid, [
            'maitrisees' => array_values(array_filter($comps, fn ($k) => !in_array($k, $non, true), ARRAY_FILTER_USE_KEY)),
            'lacunes_libres' => $n === 0 && $note < 10 ? 'Priorités opératoires' : '',
        ]);
        foreach ($suivis[$eid] ?? [] as $l) {
            $reponses[$l['id']] = $i % 2 === 0 ? 'oui' : 'non';
        }
    }
    Lacunes::enregistrerSuivis($c, $reponses, $eleves);
}
echo "Démo créée : admin@ecole.local / admin123 — prof@ecole.local / prof123\n";
