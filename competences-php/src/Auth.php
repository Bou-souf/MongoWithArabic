<?php
declare(strict_types=1);

namespace App;

/** Session, utilisateur connecté, jeton CSRF et messages flash */
final class Auth
{
    private static ?array $user = null;
    private static bool $charge = false;

    public static function demarrerSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
            session_name('competences_sid');
            session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax', 'secure' => $https]);
            session_start();
        }
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(24));
        }
    }

    public static function user(): ?array
    {
        if (!self::$charge) {
            self::$charge = true;
            if (!empty($_SESSION['user_id'])) {
                $u = Db::one('SELECT * FROM users WHERE id = ? AND actif = 1', [(int) $_SESSION['user_id']]);
                if ($u) {
                    $u['matieres_liste'] = Service\Texte::liste($u['matieres']);
                    self::$user = $u;
                } else {
                    unset($_SESSION['user_id']);
                }
            }
        }
        return self::$user;
    }

    public static function connecter(array $user): void
    {
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['csrf'] = bin2hex(random_bytes(24));
        self::$charge = false;
    }

    public static function deconnecter(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        self::$user = null;
    }

    public static function estAdmin(): bool
    {
        return (self::user()['role'] ?? '') === 'admin';
    }

    public static function csrf(): string
    {
        return $_SESSION['csrf'] ?? '';
    }

    public static function flash(string $type, string $message): void
    {
        $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
    }

    public static function prendreFlash(): array
    {
        $f = $_SESSION['flash'] ?? [];
        $_SESSION['flash'] = [];
        return $f;
    }

    /** Condition SQL limitant un professeur à ses propres classes ("1=1" pour l'admin) */
    public static function filtreClasse(string $alias = 'cl'): array
    {
        return self::estAdmin() ? ['1=1', []] : ["$alias.prof_id = ?", [(int) self::user()['id']]];
    }

    /** Classe accessible par l'utilisateur, ou null */
    public static function classe(int $id): ?array
    {
        [$w, $p] = self::filtreClasse();
        return Db::one("SELECT cl.*, u.nom AS prof_nom FROM classes cl JOIN users u ON u.id = cl.prof_id WHERE cl.id = ? AND $w", [$id, ...$p]);
    }

    /** Identifiants des élèves accessibles */
    public static function eleveIds(): array
    {
        [$w, $p] = self::filtreClasse();
        return array_map('intval', array_column(Db::all("SELECT el.id FROM eleves el JOIN classes cl ON cl.id = el.classe_id WHERE $w", $p), 'id'));
    }
}
