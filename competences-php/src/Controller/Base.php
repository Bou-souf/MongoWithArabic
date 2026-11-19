<?php
declare(strict_types=1);

namespace App\Controller;

use App\Vue;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

abstract class Base
{
    protected function vue(Response $res, string $gabarit, array $donnees = [], int $statut = 200): Response
    {
        $res->getBody()->write(Vue::render($gabarit, $donnees));
        return $res->withStatus($statut)->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    protected function redirect(Response $res, string $url): Response
    {
        return $res->withStatus(302)->withHeader('Location', $url);
    }

    protected function erreur(Response $res, int $statut, string $titre, string $message = ''): Response
    {
        return $this->vue($res, 'erreur.twig', ['titre' => $titre, 'message' => $message], $statut);
    }

    protected function body(Request $req): array
    {
        $b = $req->getParsedBody();
        return is_array($b) ? $b : [];
    }
}
