<?php
declare(strict_types=1);

namespace App;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Psr7\Response as SlimResponse;

final class Middleware
{
    /** Session + vérification CSRF des requêtes POST */
    public static function session(Request $req, Handler $h): Response
    {
        Auth::demarrerSession();
        if ($req->getMethod() === 'POST') {
            $b = $req->getParsedBody();
            $jeton = (is_array($b) ? ($b['_csrf'] ?? '') : '') ?: $req->getHeaderLine('X-CSRF-Token');
            if (!is_string($jeton) || !hash_equals(Auth::csrf(), $jeton)) {
                $r = new SlimResponse(403);
                $r->getBody()->write(Vue::render('erreur.twig', ['titre' => 'Session expirée', 'message' => 'Le formulaire a expiré. Rechargez la page et réessayez.']));
                return $r->withHeader('Content-Type', 'text/html; charset=utf-8');
            }
        }
        return $h->handle($req);
    }

    public static function connexion(Request $req, Handler $h): Response
    {
        if (!Auth::user()) {
            if ($req->getMethod() === 'GET') {
                $_SESSION['retour'] = $req->getUri()->getPath() . ($req->getUri()->getQuery() ? '?' . $req->getUri()->getQuery() : '');
            }
            return (new SlimResponse(302))->withHeader('Location', '/login');
        }
        return $h->handle($req);
    }

    public static function admin(Request $req, Handler $h): Response
    {
        if (!Auth::estAdmin()) {
            $r = new SlimResponse(403);
            $r->getBody()->write(Vue::render('erreur.twig', ['titre' => 'Accès refusé', 'message' => "Vous n'avez pas les droits nécessaires pour accéder à cette page."]));
            return $r->withHeader('Content-Type', 'text/html; charset=utf-8');
        }
        return $h->handle($req);
    }
}
