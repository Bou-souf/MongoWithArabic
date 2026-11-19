<?php
declare(strict_types=1);

namespace App;

use App\Controller\AccueilController;
use App\Controller\AdminController;
use App\Controller\AuthController;
use App\Controller\ClassesController;
use App\Controller\ControlesController;
use App\Controller\ElevesController;
use App\Controller\InstallController;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App as SlimApp;
use Slim\Exception\HttpMethodNotAllowedException;
use Slim\Exception\HttpNotFoundException;
use Slim\Factory\AppFactory;
use Slim\Routing\RouteCollectorProxy;

final class App
{
    public static function creer(): SlimApp
    {
        $app = AppFactory::create();
        $app->addRoutingMiddleware();

        $auth = new AuthController();
        $accueil = new AccueilController();
        $admin = new AdminController();
        $classes = new ClassesController();
        $controles = new ControlesController();
        $eleves = new ElevesController();
        $install = new InstallController();

        $app->get('/install', [$install, 'form']);
        $app->post('/install', [$install, 'installer']);
        $app->get('/login', [$auth, 'loginForm']);
        $app->post('/login', [$auth, 'login']);
        $app->post('/logout', [$auth, 'logout']);

        $app->group('', function (RouteCollectorProxy $g) use ($auth, $accueil, $admin, $classes, $controles, $eleves) {
            $g->get('/', [$accueil, 'accueil']);
            $g->get('/statistiques', [$accueil, 'statistiques']);
            $g->get('/alertes', [$accueil, 'alertes']);
            $g->get('/profil', [$auth, 'profil']);
            $g->post('/profil', [$auth, 'profilPost']);

            $g->get('/classes', [$classes, 'liste']);
            $g->post('/classes', [$classes, 'creer']);
            $g->get('/classes/{id:\d+}', [$classes, 'detail']);
            $g->post('/classes/{id:\d+}/eleves', [$classes, 'ajouterEleve']);
            $g->post('/classes/{id:\d+}/import', [$classes, 'importer']);
            $g->post('/classes/{id:\d+}/eleves/{eleve:\d+}/supprimer', [$classes, 'supprimerEleve']);
            $g->post('/classes/{id:\d+}/supprimer', [$classes, 'supprimer']);

            $g->get('/controles', [$controles, 'liste']);
            $g->get('/controles/nouveau', [$controles, 'nouveau']);
            $g->post('/controles', [$controles, 'creer']);
            $g->get('/controles/{id:\d+}', [$controles, 'detail']);
            $g->get('/controles/{id:\d+}/pdf', [$controles, 'pdf']);
            $g->get('/controles/{id:\d+}/competences', [$controles, 'competences']);
            $g->post('/controles/{id:\d+}/competences', [$controles, 'competencesPost']);
            $g->post('/controles/{id:\d+}/import', [$controles, 'importer']);
            $g->post('/controles/{id:\d+}/ajouter-classe', [$controles, 'ajouterClasse']);
            $g->get('/controles/{id:\d+}/evaluation', [$controles, 'evaluation']);
            $g->post('/controles/{id:\d+}/evaluation', [$controles, 'evaluationPost']);
            $g->get('/controles/{id:\d+}/dashboard', [$controles, 'dashboard']);
            $g->post('/controles/{id:\d+}/supprimer', [$controles, 'supprimer']);

            $g->get('/eleves/{id:\d+}', [$eleves, 'fiche']);
            $g->post('/eleves/{id:\d+}', [$eleves, 'modifier']);
            $g->post('/eleves/{id:\d+}/lacunes/{lacune:\d+}', [$eleves, 'lacune']);

            $g->group('/admin', function (RouteCollectorProxy $a) use ($admin) {
                $a->get('/utilisateurs', [$admin, 'utilisateurs']);
                $a->post('/utilisateurs', [$admin, 'creer']);
                $a->post('/utilisateurs/{id:\d+}', [$admin, 'modifier']);
                $a->post('/classes/{id:\d+}/prof', [$admin, 'affecterClasse']);
            })->add([Middleware::class, 'admin']);
        })->add([Middleware::class, 'connexion']);

        // Session + CSRF : ajouté en dernier = exécuté en premier
        $app->add([Middleware::class, 'session']);

        $erreurs = $app->addErrorMiddleware((bool) Config::get('debug'), true, true);
        $erreurs->setDefaultErrorHandler(function (Request $req, \Throwable $e, bool $details) use ($app) {
            $res = $app->getResponseFactory()->createResponse();
            if ($e instanceof HttpNotFoundException || $e instanceof HttpMethodNotAllowedException) {
                $statut = 404;
                $titre = 'Page introuvable';
                $message = "La page demandée n'existe pas.";
            } else {
                error_log((string) $e);
                $statut = 500;
                $titre = 'Erreur';
                $message = $details ? $e->getMessage() : 'Une erreur est survenue.';
            }
            if (session_status() === PHP_SESSION_NONE) {
                Auth::demarrerSession();
            }
            $res->getBody()->write(Vue::render('erreur.twig', ['titre' => $titre, 'message' => $message]));
            return $res->withStatus($statut)->withHeader('Content-Type', 'text/html; charset=utf-8');
        });
        return $app;
    }
}
