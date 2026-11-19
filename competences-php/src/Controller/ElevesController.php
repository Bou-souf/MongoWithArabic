<?php
declare(strict_types=1);

namespace App\Controller;

use App\Auth;
use App\Db;
use App\Service\Stats;
use App\Service\Texte;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class ElevesController extends Base
{
    private function charger(array $args): ?array
    {
        $e = Db::one('SELECT * FROM eleves WHERE id = ?', [(int) ($args['id'] ?? 0)]);
        if (!$e) {
            return null;
        }
        $classe = Auth::classe((int) $e['classe_id']);
        return $classe ? $e + ['classe' => $classe] : null;
    }

    public function fiche(Request $req, Response $res, array $args): Response
    {
        $e = $this->charger($args);
        if (!$e) {
            return $this->erreur($res, 404, 'Élève introuvable');
        }
        $evo = Stats::evolutionEleve((int) $e['id']);
        $graph = array_map(fn ($l) => ['t' => $l['controle']['titre'], 'n' => $l['note20'], 'p' => $l['evaluee'] ? $l['pct_maitrise'] : null], $evo['lignes']);
        return $this->vue($res, 'eleve.twig', ['titre' => trim($e['nom'] . ' ' . $e['prenom']), 'eleve' => $e, 'evo' => $evo, 'graph' => $graph]);
    }

    public function modifier(Request $req, Response $res, array $args): Response
    {
        $e = $this->charger($args);
        if (!$e) {
            return $this->erreur($res, 404, 'Élève introuvable');
        }
        $b = $this->body($req);
        $nom = trim((string) ($b['nom'] ?? '')) ?: $e['nom'];
        $prenom = trim((string) ($b['prenom'] ?? ''));
        $code = trim((string) ($b['code'] ?? ''));
        Db::exec('UPDATE eleves SET nom = ?, prenom = ?, code = ?, cle = ? WHERE id = ?', [$nom, $prenom, $code !== '' ? $code : null, Texte::cleEleve($nom, $prenom), $e['id']]);
        Auth::flash('success', 'Élève mis à jour.');
        return $this->redirect($res, "/eleves/{$e['id']}");
    }

    /** Mise à jour manuelle d'une lacune (remédiée / non remédiée / rouvrir / supprimer) */
    public function lacune(Request $req, Response $res, array $args): Response
    {
        $e = $this->charger($args);
        if (!$e) {
            return $this->erreur($res, 404, 'Élève introuvable');
        }
        $b = $this->body($req);
        $l = Db::one('SELECT * FROM lacunes WHERE id = ? AND eleve_id = ?', [(int) $args['lacune'], $e['id']]);
        if ($l) {
            $aujourdhui = date('Y-m-d');
            switch ($b['action'] ?? '') {
                case 'remediee':
                    Db::exec("UPDATE lacunes SET statut = 'remediee', date_remediation = ? WHERE id = ?", [$aujourdhui, $l['id']]);
                    Db::insert('lacune_suivis', ['lacune_id' => $l['id'], 'date' => $aujourdhui, 'remediee' => 1, 'commentaire' => 'Remédiation confirmée manuellement']);
                    break;
                case 'non_remediee':
                    Db::insert('lacune_suivis', ['lacune_id' => $l['id'], 'date' => $aujourdhui, 'remediee' => 0, 'commentaire' => 'Lacune toujours présente']);
                    break;
                case 'rouvrir':
                    Db::exec("UPDATE lacunes SET statut = 'ouverte', date_remediation = NULL, controle_remediation_id = NULL WHERE id = ?", [$l['id']]);
                    break;
                case 'supprimer':
                    Db::exec('DELETE FROM lacunes WHERE id = ?', [$l['id']]);
                    break;
            }
            Auth::flash('success', 'Lacune mise à jour.');
        }
        return $this->redirect($res, ($b['retour'] ?? '') === 'alertes' ? '/alertes' : "/eleves/{$e['id']}");
    }
}
