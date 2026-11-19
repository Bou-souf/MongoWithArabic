<?php
declare(strict_types=1);

// Serveur PHP intégré (développement) : servir directement les fichiers statiques
if (PHP_SAPI === 'cli-server') {
    $fichier = __DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if (is_file($fichier)) {
        return false;
    }
}

require dirname(__DIR__) . '/vendor/autoload.php';

App\App::creer()->run();
