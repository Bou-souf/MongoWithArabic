<?php
declare(strict_types=1);

namespace App\Controller;

use App\Auth;
use App\Config;
use App\Db;
use App\Service\Eleves;
use App\Service\ExcelImport;
use App\Service\Lacunes;
use App\Service\PdfAnalyzer;
use App\Service\Repo;
use App\Service\Stats;
use App\Service\Texte;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;

final class ControlesController extends Base
{
    /** Contrôle accessible par l'utilisateur, ou null */
    private function charger(array $args): ?array
    {
        $c = Repo::controle((int) ($args['id'] ?? 0));
        if (!$c || !Auth::classe((int) $c['classe_id'])) {
            return null;
        }
        return $c;
    }

    private function introuvable(Response $res): Response
    {
        return $this->erreur($res, 404, 'Contrôle introuvable');
    }

    public function liste(Request $req, Response $res): Response
    {
        [$w, $p] = Auth::filtreClasse();
        $q = $req->getQueryParams();
        $sql = "SELECT c.*, cl.nom AS classe_nom,
                  (SELECT COUNT(*) FROM questions qq WHERE qq.controle_id = c.id) AS nb_questions,
                  (SELECT COUNT(DISTINCT qc.competence) FROM questions qq JOIN question_competences qc ON qc.question_id = qq.id WHERE qq.controle_id = c.id) AS nb_competences
                FROM controles c JOIN classes cl ON cl.id = c.classe_id WHERE $w";
        if (!empty($q['classe'])) {
            $sql .= ' AND c.classe_id = ?';
            $p[] = (int) $q['classe'];
        }
        if (!empty($q['matiere'])) {
            $sql .= ' AND c.matiere = ?';
            $p[] = (string) $q['matiere'];
        }
        [$w2, $p2] = Auth::filtreClasse();
        return $this->vue($res, 'controles/liste.twig', [
            'titre' => 'Contrôles',
            'controles' => Db::all($sql . ' ORDER BY c.date DESC, c.id DESC', $p),
            'classes' => Db::all("SELECT cl.* FROM classes cl WHERE $w2 ORDER BY cl.nom", $p2),
            'matieres' => array_column(Db::all("SELECT DISTINCT c.matiere FROM controles c JOIN classes cl ON cl.id = c.classe_id WHERE $w2 ORDER BY c.matiere", $p2), 'matiere'),
            'q' => $q,
        ]);
    }

    public function nouveau(Request $req, Response $res): Response
    {
        [$w, $p] = Auth::filtreClasse();
        $classes = Db::all("SELECT cl.* FROM classes cl WHERE $w ORDER BY cl.nom", $p);
        if (!$classes) {
            Auth::flash('warning', "Créez d'abord une classe.");
            return $this->redirect($res, '/classes');
        }
        return $this->vue($res, 'controles/nouveau.twig', [
            'titre' => 'Nouveau contrôle', 'classes' => $classes, 'matieres' => Auth::user()['matieres_liste'],
            'ia_disponible' => (bool) Config::get('anthropic_api_key'), 'classe_id' => $req->getQueryParams()['classe'] ?? '',
            'aujourdhui' => date('Y-m-d'),
        ]);
    }

    public function creer(Request $req, Response $res): Response
    {
        $b = $this->body($req);
        $fichiers = $req->getUploadedFiles();
        $classe = Auth::classe((int) ($b['classe'] ?? 0));
        $titre = trim((string) ($b['titre'] ?? ''));
        $matiere = trim((string) ($b['matiere'] ?? ''));
        $pdf = $fichiers['pdf'] ?? null;
        if (!$classe || $titre === '' || $matiere === '' || !$pdf instanceof UploadedFileInterface || $pdf->getError() !== UPLOAD_ERR_OK) {
            Auth::flash('danger', 'Classe, titre, matière et fichier PDF sont obligatoires.');
            return $this->redirect($res, '/controles/nouveau');
        }
        $contenu = (string) $pdf->getStream();
        if (!str_starts_with($contenu, '%PDF')) {
            Auth::flash('danger', 'Le contrôle doit être un fichier PDF.');
            return $this->redirect($res, '/controles/nouveau');
        }
        try {
            $analyse = PdfAnalyzer::analyser($contenu, $matiere, !empty($b['ia']));
        } catch (\Throwable $e) {
            Auth::flash('danger', "Le PDF n'a pas pu être lu ({$e->getMessage()}). Vérifiez qu'il ne s'agit pas d'un scan sans texte.");
            return $this->redirect($res, '/controles/nouveau');
        }

        $dossier = (string) Config::get('upload_dir');
        if (!is_dir($dossier)) {
            mkdir($dossier, 0750, true);
        }
        $nomFichier = time() . '-' . bin2hex(random_bytes(6)) . '.pdf';
        file_put_contents($dossier . '/' . $nomFichier, $contenu);

        $date = (string) ($b['date'] ?? '');
        $id = Db::insert('controles', [
            'titre' => mb_substr($titre, 0, 200), 'matiere' => mb_substr($matiere, 0, 100), 'classe_id' => $classe['id'], 'prof_id' => $classe['prof_id'],
            'date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : date('Y-m-d'),
            'note_sur' => Texte::nombre($b['note_sur'] ?? null) ?: 20,
            'fichier_pdf' => $nomFichier, 'nom_fichier_original' => mb_substr((string) $pdf->getClientFilename(), 0, 255),
            'texte' => $analyse['texte'], 'methode_analyse' => $analyse['methode'],
        ]);
        Repo::enregistrerQuestions($id, $analyse['questions']);

        $excel = $fichiers['excel'] ?? null;
        if ($excel instanceof UploadedFileInterface && $excel->getError() === UPLOAD_ERR_OK) {
            $this->importerNotes(Repo::controle($id), $excel);
        }
        $n = count($analyse['questions']);
        if ($n === 0) {
            Auth::flash('warning', 'Aucune question détectée automatiquement : ajoutez-les manuellement.');
        } else {
            Auth::flash('success', "$n question(s) détectée(s) (" . ($analyse['methode'] === 'ia' ? 'analyse IA' : 'analyse par règles') . '). Vérifiez et ajustez les compétences.');
        }
        return $this->redirect($res, "/controles/$id/competences");
    }

    public function detail(Request $req, Response $res, array $args): Response
    {
        $c = $this->charger($args);
        if (!$c) {
            return $this->introuvable($res);
        }
        return $this->vue($res, 'controles/detail.twig', ['titre' => $c['titre'], 'controle' => $c, 'evals' => Repo::evaluations((int) $c['id'])]);
    }

    public function pdf(Request $req, Response $res, array $args): Response
    {
        $c = $this->charger($args);
        $fichier = $c && $c['fichier_pdf'] ? Config::get('upload_dir') . '/' . basename($c['fichier_pdf']) : null;
        if (!$fichier || !is_file($fichier)) {
            return $this->erreur($res, 404, 'Fichier introuvable');
        }
        $res->getBody()->write((string) file_get_contents($fichier));
        return $res->withHeader('Content-Type', 'application/pdf')
            ->withHeader('Content-Disposition', 'inline; filename="' . rawurlencode($c['nom_fichier_original'] ?: 'controle.pdf') . '"');
    }

    public function competences(Request $req, Response $res, array $args): Response
    {
        $c = $this->charger($args);
        if (!$c) {
            return $this->introuvable($res);
        }
        [$w, $p] = Auth::filtreClasse();
        $suggestions = array_column(Db::all(
            "SELECT DISTINCT qc.competence FROM question_competences qc JOIN questions q ON q.id = qc.question_id
             JOIN controles c ON c.id = q.controle_id JOIN classes cl ON cl.id = c.classe_id
             WHERE c.matiere = ? AND $w ORDER BY qc.competence", [$c['matiere'], ...$p]), 'competence');
        return $this->vue($res, 'controles/competences.twig', ['titre' => 'Compétences — ' . $c['titre'], 'controle' => $c, 'suggestions' => $suggestions]);
    }

    public function competencesPost(Request $req, Response $res, array $args): Response
    {
        $c = $this->charger($args);
        if (!$c) {
            return $this->introuvable($res);
        }
        $b = $this->body($req);
        $questions = [];
        foreach ((array) ($b['questions'] ?? []) as $q) {
            if (!is_array($q) || !empty($q['supprimer']) || (trim((string) ($q['enonce'] ?? '')) === '' && trim((string) ($q['competences'] ?? '')) === '')) {
                continue;
            }
            $questions[] = [
                'numero' => trim((string) ($q['numero'] ?? '')),
                'enonce' => trim((string) ($q['enonce'] ?? '')),
                'bareme' => Texte::nombre($q['bareme'] ?? null),
                'competences' => preg_split('/[\n;]+/u', (string) ($q['competences'] ?? '')),
            ];
        }
        $anciennes = $c['competences'];
        if (trim((string) ($b['titre'] ?? '')) !== '') {
            Db::exec('UPDATE controles SET titre = ? WHERE id = ?', [trim((string) $b['titre']), $c['id']]);
        }
        Repo::enregistrerQuestions((int) $c['id'], $questions);
        $c = Repo::controle((int) $c['id']);
        // Compétences renommées / retirées : on remet les évaluations en cohérence
        if ($anciennes !== $c['competences']) {
            foreach (Repo::evaluations((int) $c['id']) as $e) {
                if ($e['evaluee']) {
                    Lacunes::enregistrerEvaluation($c, (int) $e['eleve_id'], ['maitrisees' => $e['maitrisees'], 'lacunes_libres' => $e['lacunes_libres']]);
                }
            }
        }
        Auth::flash('success', 'Questions et compétences enregistrées.');
        return $this->redirect($res, ($b['suite'] ?? '') === 'evaluation' ? "/controles/{$c['id']}/evaluation" : "/controles/{$c['id']}");
    }

    private function importerNotes(array $controle, UploadedFileInterface $f): void
    {
        try {
            $r = ExcelImport::lireFichier(ClassesController::fichierTemporaire($f));
            if (!$r['eleves']) {
                Auth::flash('danger', 'Aucun élève trouvé dans le fichier Excel.');
                return;
            }
            $s = Eleves::synchroniser((int) $controle['classe_id'], $r['eleves']);
            $nbNotes = 0;
            foreach ($s['resultat'] as $l) {
                $existe = Db::val('SELECT id FROM evaluations WHERE controle_id = ? AND eleve_id = ?', [$controle['id'], $l['eleve_id']]);
                if ($existe) {
                    if ($l['note'] !== null) {
                        Db::exec('UPDATE evaluations SET note = ? WHERE id = ?', [$l['note'], $existe]);
                    }
                } else {
                    Db::insert('evaluations', ['controle_id' => $controle['id'], 'eleve_id' => $l['eleve_id'], 'note' => $l['note'], 'evaluee' => 0]);
                }
                $nbNotes += $l['note'] !== null ? 1 : 0;
            }
            foreach ($r['avertissements'] as $a) {
                Auth::flash('warning', $a);
            }
            Auth::flash('success', count($s['resultat']) . " élève(s) importé(s) ({$s['crees']} nouveau(x)), $nbNotes note(s).");
        } catch (\Throwable $e) {
            Auth::flash('danger', 'Lecture du fichier Excel impossible : ' . $e->getMessage());
        }
    }

    public function importer(Request $req, Response $res, array $args): Response
    {
        $c = $this->charger($args);
        if (!$c) {
            return $this->introuvable($res);
        }
        $f = $req->getUploadedFiles()['excel'] ?? null;
        if ($f instanceof UploadedFileInterface && $f->getError() === UPLOAD_ERR_OK) {
            $this->importerNotes($c, $f);
        } else {
            Auth::flash('danger', 'Aucun fichier reçu.');
        }
        return $this->redirect($res, "/controles/{$c['id']}");
    }

    public function ajouterClasse(Request $req, Response $res, array $args): Response
    {
        $c = $this->charger($args);
        if (!$c) {
            return $this->introuvable($res);
        }
        $n = Db::exec('INSERT IGNORE INTO evaluations (controle_id, eleve_id, evaluee) SELECT ?, id, 0 FROM eleves WHERE classe_id = ?', [$c['id'], $c['classe_id']]);
        Auth::flash('success', "$n élève(s) de la classe ajouté(s).");
        return $this->redirect($res, "/controles/{$c['id']}");
    }

    public function evaluation(Request $req, Response $res, array $args): Response
    {
        $c = $this->charger($args);
        if (!$c) {
            return $this->introuvable($res);
        }
        $lignes = Repo::evaluations((int) $c['id']);
        $suivis = Lacunes::aSuivre($c, array_map('intval', array_column($lignes, 'eleve_id')));
        foreach ($suivis as &$liste) {
            foreach ($liste as &$l) {
                $l['reponse'] = '';
                foreach ($l['suivis'] as $s) {
                    if ((int) $s['controle_id'] === (int) $c['id']) {
                        $l['reponse'] = $s['remediee'] ? 'oui' : 'non';
                    }
                }
                $l['col'] = array_search($l['competence'], $c['competences'], true);
                $l['col'] = $l['col'] === false ? -1 : $l['col'];
            }
        }
        unset($liste, $l);
        $questionsParComp = [];
        foreach ($c['questions'] as $q) {
            foreach ($q['competences'] as $comp) {
                $questionsParComp[$comp][] = 'Q' . $q['numero'];
            }
        }
        $libres = array_column(Db::all("SELECT DISTINCT competence FROM lacunes WHERE matiere = ? AND origine = 'professeur' ORDER BY competence", [$c['matiere']]), 'competence');
        return $this->vue($res, 'controles/evaluation.twig', [
            'titre' => 'Évaluation — ' . $c['titre'], 'controle' => $c, 'lignes' => $lignes, 'suivis' => $suivis,
            'libres' => $libres, 'questions_par_comp' => $questionsParComp,
        ]);
    }

    public function evaluationPost(Request $req, Response $res, array $args): Response
    {
        $c = $this->charger($args);
        if (!$c) {
            return $this->introuvable($res);
        }
        $b = $this->body($req);
        $autorises = array_map('intval', array_column(Db::all('SELECT eleve_id FROM evaluations WHERE controle_id = ?', [$c['id']]), 'eleve_id'));
        $donnees = (array) ($b['eval'] ?? []);
        Db::pdo()->beginTransaction();
        try {
            foreach ($autorises as $eleveId) {
                $d = $donnees[$eleveId] ?? null;
                // Lignes non modifiées d'élèves pas encore évalués : pas de lacunes créées
                if (!is_array($d) || ($d['touche'] ?? '') !== '1') {
                    continue;
                }
                $indices = array_map('intval', (array) ($d['m'] ?? []));
                Lacunes::enregistrerEvaluation($c, $eleveId, [
                    'maitrisees' => array_values(array_filter(array_map(fn ($i) => $c['competences'][$i] ?? null, $indices))),
                    'lacunes_libres' => (string) ($d['libres'] ?? ''),
                    'note' => $d['note'] ?? '',
                ]);
            }
            Lacunes::enregistrerSuivis($c, (array) ($b['suivi'] ?? []), $autorises);
            Db::pdo()->commit();
        } catch (\Throwable $e) {
            Db::pdo()->rollBack();
            throw $e;
        }
        Auth::flash('success', 'Évaluations enregistrées.');
        return $this->redirect($res, ($b['suite'] ?? '') === 'dashboard' ? "/controles/{$c['id']}/dashboard" : "/controles/{$c['id']}/evaluation");
    }

    public function dashboard(Request $req, Response $res, array $args): Response
    {
        $c = $this->charger($args);
        if (!$c) {
            return $this->introuvable($res);
        }
        return $this->vue($res, 'controles/dashboard.twig', ['titre' => 'Tableau de bord — ' . $c['titre'], 'controle' => $c, 'd' => Stats::dashboardControle($c)]);
    }

    public function supprimer(Request $req, Response $res, array $args): Response
    {
        $c = $this->charger($args);
        if (!$c) {
            return $this->introuvable($res);
        }
        Lacunes::avantSuppressionControle((int) $c['id']);
        Db::exec('DELETE FROM controles WHERE id = ?', [$c['id']]); // cascade : questions, évaluations, lacunes d'origine, suivis
        if ($c['fichier_pdf']) {
            @unlink(Config::get('upload_dir') . '/' . basename($c['fichier_pdf']));
        }
        Auth::flash('success', 'Contrôle supprimé.');
        return $this->redirect($res, '/controles');
    }
}
