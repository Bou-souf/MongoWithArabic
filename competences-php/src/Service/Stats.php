<?php
declare(strict_types=1);

namespace App\Service;

use App\Config;
use App\Db;

/** Tableau de bord d'un contrôle, évolution d'une classe et d'un élève */
final class Stats
{
    public static function pct(float|int $n, float|int $d): float
    {
        return $d ? round($n / $d * 100, 1) : 0.0;
    }

    public static function resumeNotes(array $notes, float $noteSur = 20): array
    {
        $ns = array_values(array_filter($notes, fn ($n) => $n !== null));
        if (!$ns) {
            return ['moyenne' => null, 'min' => null, 'max' => null, 'taux_reussite' => null, 'nb' => 0];
        }
        $seuil = (float) Config::get('seuil_reussite', 10) / 20 * $noteSur;
        return [
            'moyenne' => round(array_sum($ns) / count($ns), 2),
            'min' => min($ns),
            'max' => max($ns),
            'taux_reussite' => self::pct(count(array_filter($ns, fn ($n) => $n >= $seuil)), count($ns)),
            'nb' => count($ns),
        ];
    }

    public static function dashboardControle(array $controle): array
    {
        $evals = Repo::evaluations((int) $controle['id']);
        $evaluees = array_values(array_filter($evals, fn ($e) => $e['evaluee']));
        $competences = $controle['competences'];
        $n = count($evaluees);

        $parCompetence = [];
        $totalMaitrise = 0;
        foreach ($competences as $c) {
            $m = count(array_filter($evaluees, fn ($e) => in_array($c, $e['maitrisees'], true)));
            $totalMaitrise += $m;
            $parCompetence[] = ['competence' => $c, 'maitrise' => $m, 'non_maitrise' => $n - $m, 'pct_maitrise' => self::pct($m, $n), 'pct_lacune' => self::pct($n - $m, $n)];
        }
        $totalCases = $n * count($competences);

        // Groupes de besoin : élèves par compétence non maîtrisée (y compris lacunes libres)
        $groupes = [];
        foreach ($evaluees as $e) {
            foreach (array_unique([...$e['non_maitrisees'], ...$e['lacunes_libres']]) as $c) {
                $groupes[$c] ??= ['competence' => $c, 'libre' => !in_array($c, $competences, true), 'eleves' => []];
                $groupes[$c]['eleves'][] = $e;
            }
        }
        $groupes = array_values($groupes);
        foreach ($groupes as &$g) {
            $g['pct'] = self::pct(count($g['eleves']), $n);
        }
        unset($g);
        usort($groupes, fn ($a, $b) => count($b['eleves']) <=> count($a['eleves']));

        // Profils : élèves ayant exactement les mêmes lacunes
        $profils = [];
        foreach ($evaluees as $e) {
            $lac = array_values(array_unique([...$e['non_maitrisees'], ...$e['lacunes_libres']]));
            sort($lac);
            $cle = implode('|', $lac);
            $profils[$cle] ??= ['lacunes' => $lac, 'eleves' => []];
            $profils[$cle]['eleves'][] = $e;
        }
        $profils = array_values($profils);
        usort($profils, fn ($a, $b) => count($b['eleves']) <=> count($a['eleves']));

        $eleves = array_map(fn ($e) => [
            'eval' => $e,
            'pct_maitrise' => self::pct(count($e['maitrisees']), count($competences)),
            'lacunes' => [...$e['non_maitrisees'], ...$e['lacunes_libres']],
        ], $evaluees);
        usort($eleves, fn ($a, $b) => $b['pct_maitrise'] <=> $a['pct_maitrise']);

        $pctM = self::pct($totalMaitrise, $totalCases);
        return [
            'nb_eleves' => count($evals),
            'nb_evalues' => $n,
            'notes' => self::resumeNotes(array_column($evals, 'note'), $controle['note_sur']),
            'pct_maitrise_global' => $pctM,
            'pct_lacune_global' => $totalCases ? round(100 - $pctM, 1) : 0.0,
            'par_competence' => $parCompetence,
            'groupes' => $groupes,
            'profils' => $profils,
            'eleves' => $eleves,
            'distribution' => self::distribution(array_column($evals, 'note'), $controle['note_sur']),
        ];
    }

    public static function distribution(array $notes, float $noteSur = 20): array
    {
        $t = array_map(fn ($x) => $x * $noteSur, [0, 0.25, 0.5, 0.75, 1]);
        $labels = $valeurs = [];
        for ($i = 0; $i < 4; $i++) {
            $labels[] = self::fmt($t[$i]) . '–' . self::fmt($t[$i + 1]);
            $valeurs[] = count(array_filter($notes, fn ($n) => $n !== null && $n >= $t[$i] && ($i === 3 ? $n <= $t[$i + 1] : $n < $t[$i + 1])));
        }
        return ['labels' => $labels, 'valeurs' => $valeurs];
    }

    private static function fmt(float $x): string
    {
        return rtrim(rtrim(number_format($x, 2, '.', ''), '0'), '.');
    }

    public static function evolutionClasse(int $classeId, string $matiere): array
    {
        $controles = Db::all('SELECT c.*, cl.nom AS classe_nom FROM controles c JOIN classes cl ON cl.id = c.classe_id WHERE c.classe_id = ? AND c.matiere = ? ORDER BY c.date, c.id', [$classeId, $matiere]);
        $lignes = [];
        $suivies = [];
        foreach ($controles as $c) {
            $c = Repo::completerControle($c);
            $d = self::dashboardControle($c);
            $lignes[] = ['controle' => $c, 'pct_maitrise' => $d['pct_maitrise_global'], 'nb_evalues' => $d['nb_evalues']] + $d['notes'];
            foreach ($d['par_competence'] as $pc) {
                $suivies[$pc['competence']][$c['id']] = $pc['pct_maitrise'];
            }
        }
        $comps = [];
        foreach ($suivies as $competence => $points) {
            $vals = array_values($points);
            $comps[] = ['competence' => $competence, 'points' => $points, 'tendance' => count($vals) > 1 ? round(end($vals) - $vals[0], 1) : null];
        }
        $lac = Repo::lacunes('el.classe_id = ? AND l.matiere = ?', [$classeId, $matiere]);
        return [
            'controles' => $lignes,
            'competences' => $comps,
            'lacunes' => [
                'ouvertes' => count(array_filter($lac, fn ($l) => $l['statut'] === 'ouverte')),
                'remediees' => count(array_filter($lac, fn ($l) => $l['statut'] === 'remediee')),
                'alertes' => count(array_filter($lac, fn ($l) => $l['niveau_alerte'] > 0)),
            ],
        ];
    }

    public static function evolutionEleve(int $eleveId): array
    {
        $evals = Repo::completerEvaluations(Db::all('SELECT e.* FROM evaluations e JOIN controles c ON c.id = e.controle_id WHERE e.eleve_id = ? ORDER BY c.date, c.id', [$eleveId]));
        $lignes = [];
        foreach ($evals as $e) {
            $c = Repo::controle((int) $e['controle_id']);
            $nb = count($c['competences']);
            $lignes[] = [
                'controle' => $c, 'note' => $e['note'], 'evaluee' => $e['evaluee'],
                'pct_maitrise' => $nb ? self::pct(count($e['maitrisees']), $nb) : null,
                'lacunes' => [...$e['non_maitrisees'], ...$e['lacunes_libres']],
                'note20' => $e['note'] !== null ? round($e['note'] / ($c['note_sur'] ?: 20) * 20, 2) : null,
            ];
        }
        $lacunes = Repo::lacunes('l.eleve_id = ?', [$eleveId], "l.statut = 'remediee', l.created_at DESC");
        return ['lignes' => $lignes, 'lacunes' => $lacunes];
    }
}
