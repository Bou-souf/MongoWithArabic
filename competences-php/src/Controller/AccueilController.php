<?php
declare(strict_types=1);

namespace App\Controller;

use App\Auth;
use App\Db;
use App\Service\Repo;
use App\Service\Stats;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class AccueilController extends Base
{
    public function accueil(Request $req, Response $res): Response
    {
        [$w, $p] = Auth::filtreClasse();
        $classes = Db::all("SELECT cl.* FROM classes cl WHERE $w ORDER BY cl.nom", $p);
        $controles = Db::all(
            "SELECT c.*, cl.nom AS classe_nom,
                (SELECT COUNT(*) FROM evaluations e WHERE e.controle_id = c.id AND e.evaluee = 1) AS nb_evalues,
                (SELECT COUNT(*) FROM evaluations e WHERE e.controle_id = c.id) AS nb_eleves
             FROM controles c JOIN classes cl ON cl.id = c.classe_id WHERE $w ORDER BY c.date DESC, c.id DESC LIMIT 8", $p);
        $ids = Auth::eleveIds();
        $lacunes = $ids ? Repo::completerLacunes(Db::all('SELECT * FROM lacunes WHERE eleve_id IN (' . Db::in($ids) . ')', $ids)) : [];
        return $this->vue($res, 'accueil.twig', [
            'titre' => 'Accueil',
            'controles' => $controles,
            'stats' => [
                'classes' => count($classes),
                'eleves' => count($ids),
                'controles' => (int) Db::val("SELECT COUNT(*) FROM controles c JOIN classes cl ON cl.id = c.classe_id WHERE $w", $p),
                'ouvertes' => count(array_filter($lacunes, fn ($l) => $l['statut'] === 'ouverte')),
                'remediees' => count(array_filter($lacunes, fn ($l) => $l['statut'] === 'remediee')),
                'alertes' => count(array_filter($lacunes, fn ($l) => $l['niveau_alerte'] > 0)),
            ],
        ]);
    }

    public function statistiques(Request $req, Response $res): Response
    {
        [$w, $p] = Auth::filtreClasse();
        $q = $req->getQueryParams();
        $classes = Db::all("SELECT cl.* FROM classes cl WHERE $w ORDER BY cl.nom", $p);
        $classe = null;
        foreach ($classes as $c) {
            if ((string) $c['id'] === (string) ($q['classe'] ?? '')) {
                $classe = $c;
            }
        }
        $classe ??= $classes[0] ?? null;
        $matieres = [];
        $matiere = (string) ($q['matiere'] ?? '');
        $evolution = null;
        if ($classe) {
            $matieres = array_column(Db::all('SELECT DISTINCT matiere FROM controles WHERE classe_id = ? ORDER BY matiere', [$classe['id']]), 'matiere');
            if (!in_array($matiere, $matieres, true)) {
                $matiere = $matieres[0] ?? '';
            }
            $evolution = Stats::evolutionClasse((int) $classe['id'], $matiere);
        }
        $graph = $evolution ? array_map(fn ($l) => ['t' => $l['controle']['titre'], 'm' => $l['moyenne'], 'r' => $l['taux_reussite'], 'p' => $l['pct_maitrise']], $evolution['controles']) : [];
        return $this->vue($res, 'statistiques.twig', compact('classes', 'classe', 'matieres', 'matiere', 'evolution', 'graph') + ['titre' => 'Statistiques']);
    }

    public function alertes(Request $req, Response $res): Response
    {
        $ids = Auth::eleveIds();
        $lacunes = $ids ? Repo::lacunes("l.eleve_id IN (" . Db::in($ids) . ") AND l.statut = 'ouverte'", $ids) : [];
        $avecAlerte = array_values(array_filter($lacunes, fn ($l) => $l['niveau_alerte'] > 0));
        usort($avecAlerte, fn ($a, $b) => [$b['niveau_alerte'], $b['echecs']] <=> [$a['niveau_alerte'], $a['echecs']]);
        $limite = time() - 30 * 86400;
        $anciennes = array_values(array_filter($lacunes, fn ($l) => !$l['suivis'] && strtotime($l['created_at']) < $limite));
        return $this->vue($res, 'alertes.twig', ['titre' => 'Alertes', 'avec_alerte' => $avecAlerte, 'anciennes' => $anciennes, 'nb_ouvertes' => count($lacunes)]);
    }
}
