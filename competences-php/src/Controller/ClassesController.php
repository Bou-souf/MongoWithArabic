<?php
declare(strict_types=1);

namespace App\Controller;

use App\Auth;
use App\Db;
use App\Service\Eleves;
use App\Service\ExcelImport;
use App\Service\Repo;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;

final class ClassesController extends Base
{
    public function liste(Request $req, Response $res): Response
    {
        [$w, $p] = Auth::filtreClasse();
        $classes = Db::all("SELECT cl.*, u.nom AS prof_nom, (SELECT COUNT(*) FROM eleves e WHERE e.classe_id = cl.id) AS effectif
                            FROM classes cl JOIN users u ON u.id = cl.prof_id WHERE $w ORDER BY cl.nom", $p);
        $profs = Auth::estAdmin() ? Db::all('SELECT id, nom, role FROM users WHERE actif = 1 ORDER BY nom') : [];
        return $this->vue($res, 'classes/liste.twig', ['titre' => 'Classes', 'classes' => $classes, 'profs' => $profs]);
    }

    public function creer(Request $req, Response $res): Response
    {
        $b = $this->body($req);
        $nom = trim((string) ($b['nom'] ?? ''));
        if ($nom === '') {
            Auth::flash('danger', 'Le nom de la classe est obligatoire.');
            return $this->redirect($res, '/classes');
        }
        $prof = Auth::estAdmin() && !empty($b['prof']) ? (int) $b['prof'] : (int) Auth::user()['id'];
        try {
            $id = Db::insert('classes', ['nom' => $nom, 'niveau' => trim((string) ($b['niveau'] ?? '')), 'annee_scolaire' => trim((string) ($b['annee_scolaire'] ?? '')), 'prof_id' => $prof]);
        } catch (\PDOException $e) {
            Auth::flash('danger', $e->getCode() === '23000' ? 'Cette classe existe déjà.' : 'Création impossible.');
            return $this->redirect($res, '/classes');
        }
        Auth::flash('success', "Classe $nom créée.");
        return $this->redirect($res, "/classes/$id");
    }

    public function detail(Request $req, Response $res, array $args): Response
    {
        $classe = Auth::classe((int) $args['id']);
        if (!$classe) {
            return $this->erreur($res, 404, 'Classe introuvable');
        }
        $eleves = Db::all('SELECT * FROM eleves WHERE classe_id = ? ORDER BY nom, prenom', [$classe['id']]);
        $controles = Db::all('SELECT * FROM controles WHERE classe_id = ? ORDER BY date DESC, id DESC', [$classe['id']]);
        $parEleve = [];
        foreach (Repo::lacunes("el.classe_id = ? AND l.statut = 'ouverte'", [$classe['id']]) as $l) {
            $parEleve[$l['eleve_id']] ??= ['ouvertes' => 0, 'alertes' => 0];
            $parEleve[$l['eleve_id']]['ouvertes']++;
            if ($l['niveau_alerte'] > 0) {
                $parEleve[$l['eleve_id']]['alertes']++;
            }
        }
        $profs = Auth::estAdmin() ? Db::all('SELECT id, nom FROM users WHERE actif = 1 ORDER BY nom') : [];
        return $this->vue($res, 'classes/detail.twig', ['titre' => $classe['nom']] + compact('classe', 'eleves', 'controles', 'profs') + ['par_eleve' => $parEleve]);
    }

    public function ajouterEleve(Request $req, Response $res, array $args): Response
    {
        $classe = Auth::classe((int) $args['id']);
        if (!$classe) {
            return $this->redirect($res, '/classes');
        }
        $b = $this->body($req);
        if (trim((string) ($b['nom'] ?? '')) !== '') {
            Eleves::creer((int) $classe['id'], (string) $b['nom'], (string) ($b['prenom'] ?? ''), (string) ($b['code'] ?? ''));
            Auth::flash('success', 'Élève ajouté.');
        }
        return $this->redirect($res, "/classes/{$classe['id']}");
    }

    public function importer(Request $req, Response $res, array $args): Response
    {
        $classe = Auth::classe((int) $args['id']);
        if (!$classe) {
            return $this->redirect($res, '/classes');
        }
        $f = $req->getUploadedFiles()['fichier'] ?? null;
        if (!$f instanceof UploadedFileInterface || $f->getError() !== UPLOAD_ERR_OK) {
            Auth::flash('danger', 'Aucun fichier reçu.');
        } else {
            try {
                $r = ExcelImport::lireFichier(self::fichierTemporaire($f));
                $s = Eleves::synchroniser((int) $classe['id'], $r['eleves']);
                Auth::flash('success', count($r['eleves']) . " lignes lues, {$s['crees']} nouvel(le)s élève(s) ajouté(e)s.");
            } catch (\Throwable $e) {
                Auth::flash('danger', 'Lecture du fichier impossible : ' . $e->getMessage());
            }
        }
        return $this->redirect($res, "/classes/{$classe['id']}");
    }

    public function supprimerEleve(Request $req, Response $res, array $args): Response
    {
        $classe = Auth::classe((int) $args['id']);
        if ($classe && Db::exec('DELETE FROM eleves WHERE id = ? AND classe_id = ?', [(int) $args['eleve'], $classe['id']])) {
            Auth::flash('success', 'Élève supprimé.');
        }
        return $this->redirect($res, $classe ? "/classes/{$classe['id']}" : '/classes');
    }

    public function supprimer(Request $req, Response $res, array $args): Response
    {
        $classe = Auth::classe((int) $args['id']);
        if (!$classe) {
            return $this->redirect($res, '/classes');
        }
        if (Db::val('SELECT COUNT(*) FROM controles WHERE classe_id = ?', [$classe['id']])) {
            Auth::flash('danger', "Supprimez d'abord les contrôles de cette classe.");
            return $this->redirect($res, "/classes/{$classe['id']}");
        }
        Db::exec('DELETE FROM classes WHERE id = ?', [$classe['id']]);
        Auth::flash('success', 'Classe supprimée.');
        return $this->redirect($res, '/classes');
    }

    /** Copie un fichier envoyé vers un fichier temporaire en conservant l'extension (utile à PhpSpreadsheet) */
    public static function fichierTemporaire(UploadedFileInterface $f): string
    {
        $ext = strtolower(pathinfo((string) $f->getClientFilename(), PATHINFO_EXTENSION));
        $ext = in_array($ext, ['xlsx', 'xls', 'csv', 'ods'], true) ? $ext : 'xlsx';
        $base = tempnam(sys_get_temp_dir(), 'imp');
        $chemin = $base . '.' . $ext;
        $f->moveTo($chemin);
        register_shutdown_function(function () use ($base, $chemin) {
            @unlink($base);
            @unlink($chemin);
        });
        return $chemin;
    }
}
