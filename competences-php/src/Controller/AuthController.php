<?php
declare(strict_types=1);

namespace App\Controller;

use App\Auth;
use App\Db;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class AuthController extends Base
{
    public function loginForm(Request $req, Response $res): Response
    {
        if (Auth::user()) {
            return $this->redirect($res, '/');
        }
        return $this->vue($res, 'login.twig', ['titre' => 'Connexion', 'email' => '']);
    }

    public function login(Request $req, Response $res): Response
    {
        $b = $this->body($req);
        $email = mb_strtolower(trim((string) ($b['email'] ?? '')));
        $u = Db::one('SELECT * FROM users WHERE email = ?', [$email]);
        if (!$u || !$u['actif'] || !password_verify((string) ($b['password'] ?? ''), $u['password_hash'])) {
            Auth::flash('danger', 'Email ou mot de passe incorrect.');
            return $this->vue($res, 'login.twig', ['titre' => 'Connexion', 'email' => $email], 401);
        }
        $retour = $_SESSION['retour'] ?? '/';
        Auth::connecter($u);
        unset($_SESSION['retour']);
        return $this->redirect($res, is_string($retour) && str_starts_with($retour, '/') && !str_starts_with($retour, '//') ? $retour : '/');
    }

    public function logout(Request $req, Response $res): Response
    {
        Auth::deconnecter();
        return $this->redirect($res, '/login');
    }

    public function profil(Request $req, Response $res): Response
    {
        return $this->vue($res, 'profil.twig', ['titre' => 'Mon profil']);
    }

    public function profilPost(Request $req, Response $res): Response
    {
        $b = $this->body($req);
        $u = Auth::user();
        $nouveau = (string) ($b['nouveau'] ?? '');
        if (!password_verify((string) ($b['actuel'] ?? ''), $u['password_hash'])) {
            Auth::flash('danger', 'Mot de passe actuel incorrect.');
        } elseif (mb_strlen($nouveau) < 6) {
            Auth::flash('danger', 'Le nouveau mot de passe doit contenir au moins 6 caractères.');
        } elseif ($nouveau !== ($b['confirmation'] ?? '')) {
            Auth::flash('danger', 'La confirmation ne correspond pas.');
        } else {
            Db::exec('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($nouveau, PASSWORD_DEFAULT), $u['id']]);
            Auth::flash('success', 'Mot de passe modifié.');
        }
        return $this->redirect($res, '/profil');
    }
}
