<?php
declare(strict_types=1);

namespace App\Controller;

use App\Auth;
use App\Db;
use App\Service\Texte;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class AdminController extends Base
{
    public function utilisateurs(Request $req, Response $res): Response
    {
        $users = Db::all('SELECT u.*, (SELECT COUNT(*) FROM classes c WHERE c.prof_id = u.id) AS nb_classes FROM users u ORDER BY u.role, u.nom');
        return $this->vue($res, 'admin/utilisateurs.twig', ['titre' => 'Utilisateurs', 'users' => $users]);
    }

    public function creer(Request $req, Response $res): Response
    {
        $b = $this->body($req);
        $nom = trim((string) ($b['nom'] ?? ''));
        $email = mb_strtolower(trim((string) ($b['email'] ?? '')));
        $mdp = (string) ($b['password'] ?? '');
        if ($nom === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($mdp) < 6) {
            Auth::flash('danger', 'Nom, email valide et mot de passe (6 caractères min.) sont obligatoires.');
        } elseif (Db::val('SELECT id FROM users WHERE email = ?', [$email])) {
            Auth::flash('danger', 'Un compte existe déjà avec cet email.');
        } else {
            Db::insert('users', [
                'nom' => $nom, 'email' => $email, 'password_hash' => password_hash($mdp, PASSWORD_DEFAULT),
                'role' => ($b['role'] ?? '') === 'admin' ? 'admin' : 'prof',
                'matieres' => implode(', ', Texte::liste($b['matieres'] ?? '')),
            ]);
            Auth::flash('success', "Compte « $nom » créé.");
        }
        return $this->redirect($res, '/admin/utilisateurs');
    }

    public function modifier(Request $req, Response $res, array $args): Response
    {
        $b = $this->body($req);
        $u = Db::one('SELECT * FROM users WHERE id = ?', [(int) $args['id']]);
        if (!$u) {
            return $this->redirect($res, '/admin/utilisateurs');
        }
        $soiMeme = (int) $u['id'] === (int) Auth::user()['id'];
        if (($b['action'] ?? '') === 'supprimer') {
            if ($soiMeme) {
                Auth::flash('danger', 'Vous ne pouvez pas supprimer votre propre compte.');
            } elseif (Db::val('SELECT COUNT(*) FROM classes WHERE prof_id = ?', [$u['id']])) {
                Auth::flash('danger', 'Ce professeur a des classes : réaffectez-les ou désactivez le compte.');
            } else {
                Db::exec('DELETE FROM users WHERE id = ?', [$u['id']]);
                Auth::flash('success', 'Compte supprimé.');
            }
            return $this->redirect($res, '/admin/utilisateurs');
        }
        $maj = [
            'nom' => trim((string) ($b['nom'] ?? '')) ?: $u['nom'],
            'matieres' => implode(', ', Texte::liste($b['matieres'] ?? '')),
            'role' => $soiMeme ? $u['role'] : (($b['role'] ?? '') === 'admin' ? 'admin' : 'prof'),
            'actif' => $soiMeme ? 1 : (isset($b['actif']) ? 1 : 0),
        ];
        if (!empty($b['password'])) {
            if (mb_strlen((string) $b['password']) < 6) {
                Auth::flash('danger', 'Mot de passe trop court.');
                return $this->redirect($res, '/admin/utilisateurs');
            }
            Db::exec('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash((string) $b['password'], PASSWORD_DEFAULT), $u['id']]);
        }
        Db::exec('UPDATE users SET nom = ?, matieres = ?, role = ?, actif = ? WHERE id = ?', [...array_values($maj), $u['id']]);
        Auth::flash('success', "Compte « {$maj['nom']} » mis à jour.");
        return $this->redirect($res, '/admin/utilisateurs');
    }

    public function affecterClasse(Request $req, Response $res, array $args): Response
    {
        $profId = (int) ($this->body($req)['prof'] ?? 0);
        $classe = Db::one('SELECT * FROM classes WHERE id = ?', [(int) $args['id']]);
        $prof = Db::one('SELECT * FROM users WHERE id = ?', [$profId]);
        if ($classe && $prof) {
            Db::exec('UPDATE classes SET prof_id = ? WHERE id = ?', [$prof['id'], $classe['id']]);
            Db::exec('UPDATE controles SET prof_id = ? WHERE classe_id = ?', [$prof['id'], $classe['id']]);
            Auth::flash('success', "Classe {$classe['nom']} affectée à {$prof['nom']}.");
        }
        return $this->redirect($res, '/classes/' . (int) $args['id']);
    }
}
