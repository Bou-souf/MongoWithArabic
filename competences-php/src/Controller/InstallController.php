<?php
declare(strict_types=1);

namespace App\Controller;

use App\Auth;
use App\Db;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Installation par le navigateur (pratique sur un hébergement mutualisé sans SSH).
 * Disponible uniquement tant qu'aucun compte n'existe.
 */
final class InstallController extends Base
{
    private function dejaInstalle(): bool
    {
        return Db::tablesExistent() && (int) Db::val('SELECT COUNT(*) FROM users') > 0;
    }

    public function form(Request $req, Response $res): Response
    {
        try {
            if ($this->dejaInstalle()) {
                return $this->redirect($res, '/login');
            }
        } catch (\PDOException $e) {
            return $this->erreur($res, 500, 'Connexion à la base impossible', 'Vérifiez les paramètres « db » du fichier config.php. (' . $e->getMessage() . ')');
        }
        return $this->vue($res, 'install.twig', ['titre' => 'Installation']);
    }

    public function installer(Request $req, Response $res): Response
    {
        if ($this->dejaInstalle()) {
            return $this->redirect($res, '/login');
        }
        $b = $this->body($req);
        $email = mb_strtolower(trim((string) ($b['email'] ?? '')));
        $mdp = (string) ($b['password'] ?? '');
        $nom = trim((string) ($b['nom'] ?? '')) ?: 'Administrateur';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($mdp) < 8) {
            Auth::flash('danger', 'Email valide et mot de passe de 8 caractères minimum requis.');
            return $this->redirect($res, '/install');
        }
        Db::installerSchema();
        Db::insert('users', ['nom' => $nom, 'email' => $email, 'password_hash' => password_hash($mdp, PASSWORD_DEFAULT), 'role' => 'admin']);
        Auth::flash('success', 'Installation terminée. Connectez-vous avec le compte administrateur.');
        return $this->redirect($res, '/login');
    }
}
