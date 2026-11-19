<?php
declare(strict_types=1);

namespace App\Service;

use App\Db;

final class Eleves
{
    public static function creer(int $classeId, string $nom, string $prenom = '', ?string $code = null): int
    {
        return Db::insert('eleves', [
            'nom' => mb_substr(trim($nom), 0, 100), 'prenom' => mb_substr(trim($prenom), 0, 100),
            'code' => $code !== null && trim($code) !== '' ? mb_substr(trim($code), 0, 50) : null,
            'classe_id' => $classeId, 'cle' => Texte::cleEleve($nom, $prenom),
        ]);
    }

    /**
     * Rapproche les lignes importées des élèves existants (code puis nom+prénom) et crée les manquants.
     * @return array{crees:int, resultat: array<int, array{eleve_id:int, note:?float}>}
     */
    public static function synchroniser(int $classeId, array $lignes): array
    {
        $existants = Db::all('SELECT id, code, cle FROM eleves WHERE classe_id = ?', [$classeId]);
        $crees = 0;
        $resultat = [];
        foreach ($lignes as $l) {
            $cle = Texte::cleEleve($l['nom'], $l['prenom']);
            $trouve = null;
            foreach ($existants as $e) {
                if ($l['code'] !== '' && $e['code'] === $l['code']) {
                    $trouve = $e;
                    break;
                }
            }
            if (!$trouve) {
                foreach ($existants as $e) {
                    if ($e['cle'] === $cle) {
                        $trouve = $e;
                        break;
                    }
                }
            }
            if (!$trouve) {
                $id = self::creer($classeId, $l['nom'], $l['prenom'], $l['code']);
                $trouve = ['id' => $id, 'code' => $l['code'] ?: null, 'cle' => $cle];
                $existants[] = $trouve;
                $crees++;
            } elseif ($l['code'] !== '' && empty($trouve['code'])) {
                Db::exec('UPDATE eleves SET code = ? WHERE id = ?', [$l['code'], $trouve['id']]);
            }
            $resultat[] = ['eleve_id' => (int) $trouve['id'], 'note' => $l['note']];
        }
        return ['crees' => $crees, 'resultat' => $resultat];
    }
}
