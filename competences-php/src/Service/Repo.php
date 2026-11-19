<?php
declare(strict_types=1);

namespace App\Service;

use App\Db;

/** Chargement des entités composées (contrôle + questions, évaluations, lacunes + suivis) */
final class Repo
{
    /** Contrôle avec ses questions, ses compétences (liste unique) et sa classe */
    public static function controle(int $id): ?array
    {
        $c = Db::one('SELECT c.*, cl.nom AS classe_nom FROM controles c JOIN classes cl ON cl.id = c.classe_id WHERE c.id = ?', [$id]);
        return $c ? self::completerControle($c) : null;
    }

    public static function completerControle(array $c): array
    {
        $questions = Db::all('SELECT * FROM questions WHERE controle_id = ? ORDER BY ordre, id', [$c['id']]);
        $comps = [];
        if ($questions) {
            $ids = array_column($questions, 'id');
            foreach (Db::all('SELECT question_id, competence FROM question_competences WHERE question_id IN (' . Db::in($ids) . ') ORDER BY id', $ids) as $r) {
                $comps[$r['question_id']][] = $r['competence'];
            }
        }
        $uniques = [];
        $vues = [];
        foreach ($questions as &$q) {
            $q['competences'] = $comps[$q['id']] ?? [];
            $q['bareme'] = $q['bareme'] !== null ? (float) $q['bareme'] : null;
            foreach ($q['competences'] as $comp) {
                $k = mb_strtolower($comp);
                if (!isset($vues[$k])) {
                    $vues[$k] = true;
                    $uniques[] = $comp;
                }
            }
        }
        unset($q);
        $c['questions'] = $questions;
        $c['competences'] = $uniques;
        $c['note_sur'] = (float) $c['note_sur'];
        return $c;
    }

    /** Remplace les questions d'un contrôle */
    public static function enregistrerQuestions(int $controleId, array $questions): void
    {
        Db::exec('DELETE FROM questions WHERE controle_id = ?', [$controleId]);
        foreach (array_values($questions) as $i => $q) {
            $qid = Db::insert('questions', [
                'controle_id' => $controleId, 'ordre' => $i, 'numero' => mb_substr((string) $q['numero'], 0, 30),
                'enonce' => (string) $q['enonce'], 'bareme' => $q['bareme'],
            ]);
            foreach (Texte::liste($q['competences']) as $comp) {
                Db::insert('question_competences', ['question_id' => $qid, 'competence' => mb_substr($comp, 0, 255)]);
            }
        }
    }

    /** Évaluations d'un contrôle avec l'élève et les listes de compétences */
    public static function evaluations(int $controleId): array
    {
        $evals = Db::all(
            'SELECT e.*, el.nom, el.prenom, el.code FROM evaluations e JOIN eleves el ON el.id = e.eleve_id
             WHERE e.controle_id = ? ORDER BY el.nom, el.prenom', [$controleId]);
        return self::completerEvaluations($evals);
    }

    public static function completerEvaluations(array $evals): array
    {
        $parEval = [];
        if ($evals) {
            $ids = array_column($evals, 'id');
            foreach (Db::all('SELECT evaluation_id, competence, statut FROM evaluation_competences WHERE evaluation_id IN (' . Db::in($ids) . ') ORDER BY id', $ids) as $r) {
                $parEval[$r['evaluation_id']][$r['statut']][] = $r['competence'];
            }
        }
        foreach ($evals as &$e) {
            $e['maitrisees'] = $parEval[$e['id']]['maitrisee'] ?? [];
            $e['non_maitrisees'] = $parEval[$e['id']]['non_maitrisee'] ?? [];
            $e['lacunes_libres'] = $parEval[$e['id']]['libre'] ?? [];
            $e['note'] = $e['note'] !== null ? (float) $e['note'] : null;
            $e['evaluee'] = (bool) $e['evaluee'];
        }
        return $evals;
    }

    /** Ajoute suivis, nombre d'échecs et niveau d'alerte à des lignes de la table lacunes */
    public static function completerLacunes(array $lacunes): array
    {
        $suivis = [];
        if ($lacunes) {
            $ids = array_column($lacunes, 'id');
            foreach (Db::all(
                'SELECT s.*, c.titre AS controle_titre, c.date AS controle_date FROM lacune_suivis s
                 LEFT JOIN controles c ON c.id = s.controle_id WHERE s.lacune_id IN (' . Db::in($ids) . ') ORDER BY s.date, s.id', $ids) as $s) {
                $s['remediee'] = (bool) $s['remediee'];
                $suivis[$s['lacune_id']][] = $s;
            }
        }
        foreach ($lacunes as &$l) {
            $l['suivis'] = $suivis[$l['id']] ?? [];
            $l['echecs'] = count(array_filter($l['suivis'], fn ($s) => !$s['remediee']));
            $l['niveau_alerte'] = $l['statut'] !== 'ouverte' ? 0 : ($l['echecs'] >= 2 ? 2 : ($l['echecs'] === 1 ? 1 : 0));
        }
        return $lacunes;
    }

    /** Lacunes (avec infos élève, classe et contrôle d'origine) selon un filtre SQL */
    public static function lacunes(string $where, array $params, string $ordre = 'l.created_at'): array
    {
        return self::completerLacunes(Db::all(
            "SELECT l.*, el.nom AS eleve_nom, el.prenom AS eleve_prenom, el.classe_id, cl.nom AS classe_nom,
                    co.titre AS origine_titre, co.date AS origine_date, cr.titre AS remediation_titre
             FROM lacunes l
             JOIN eleves el ON el.id = l.eleve_id
             JOIN classes cl ON cl.id = el.classe_id
             LEFT JOIN controles co ON co.id = l.controle_origine_id
             LEFT JOIN controles cr ON cr.id = l.controle_remediation_id
             WHERE $where ORDER BY $ordre", $params));
    }
}
