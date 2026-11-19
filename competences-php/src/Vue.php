<?php
declare(strict_types=1);

namespace App;

use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

final class Vue
{
    private static ?Environment $twig = null;

    public static function twig(): Environment
    {
        if (self::$twig === null) {
            $t = new Environment(new FilesystemLoader(dirname(__DIR__) . '/templates'), ['autoescape' => 'html', 'strict_variables' => false]);
            $t->addFilter(new TwigFilter('fmt', fn ($n) => $n === null || $n === '' ? '—' : str_replace('.', ',', (string) (round((float) $n * 100) / 100))));
            $t->addFilter(new TwigFilter('date_fr', fn ($d) => $d ? date('d/m/Y', strtotime((string) $d)) : ''));
            $t->addFunction(new TwigFunction('couleur_pct', fn ($p) => $p >= 75 ? 'success' : ($p >= 50 ? 'warning' : 'danger')));
            self::$twig = $t;
        }
        return self::$twig;
    }

    public static function render(string $gabarit, array $donnees = []): string
    {
        $chemin = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        return self::twig()->render($gabarit, $donnees + [
            'user' => Auth::user(),
            'csrf' => Auth::csrf(),
            'flash' => Auth::prendreFlash(),
            'path' => $chemin,
        ]);
    }
}
