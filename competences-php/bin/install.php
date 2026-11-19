<?php
// Installation en ligne de commande : php bin/install.php admin@ecole.ma MotDePasse "Nom"
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';

use App\Db;

[$_, $email, $mdp, $nom] = $argv + [null, null, null, 'Administrateur'];
Db::installerSchema();
echo "Tables créées (ou déjà présentes).\n";
if ($email && $mdp) {
    if (Db::val('SELECT id FROM users WHERE email = ?', [$email])) {
        echo "Le compte $email existe déjà.\n";
    } else {
        Db::insert('users', ['nom' => $nom, 'email' => strtolower($email), 'password_hash' => password_hash($mdp, PASSWORD_DEFAULT), 'role' => 'admin']);
        echo "Administrateur créé : $email\n";
    }
} elseif (!(int) Db::val('SELECT COUNT(*) FROM users')) {
    echo "Aucun compte : relancez avec un email et un mot de passe, ou ouvrez /install dans le navigateur.\n";
}
