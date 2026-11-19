<?php
declare(strict_types=1);

namespace App\Service;

use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Lecture d'un fichier Excel / CSV / ODS de notes.
 * La première ligne contenant une colonne « nom » est prise comme en-tête
 * (les exports Massar contiennent souvent plusieurs lignes de titre).
 */
final class ExcelImport
{
    private const COLS = [
        'complet' => ['/^nom (et|&) prenoms?$/u', '/^nom complet$/u', '/^eleves?$/u', '/^nom\s*\/\s*prenom$/u', '/^name$/u', '/^full ?name$/u', '/^student$/u', '/^الاسم الكامل$/u'],
        'nom' => ['/^nom$/u', '/^nom de famille$/u', '/^last ?name$/u', '/^surname$/u', '/^النسب$/u', '/^الاسم العائلي$/u'],
        'prenom' => ['/^prenoms?$/u', '/^first ?name$/u', '/^الاسم$/u', '/^الاسم الشخصي$/u'],
        'code' => ['/^code/u', '/massar/u', '/^matricule$/u', '/^id$/u', '/^n°$/u', '/^numero$/u', '/^رقم/u'],
        'note' => ['/^notes?\b/u', '/^moyenne/u', '/^resultat/u', '/^score/u', '/^mark/u', '/^grade/u', '/^النقطة/u', '/^نقطة/u', '/^المعدل/u'],
    ];

    public static function trouverColonnes(array $entete): array
    {
        $idx = [];
        foreach ($entete as $i => $cell) {
            $c = trim(Texte::normaliser((string) $cell));
            if ($c === '') {
                continue;
            }
            foreach (self::COLS as $cle => $motifs) {
                if (isset($idx[$cle])) {
                    continue;
                }
                foreach ($motifs as $m) {
                    if (preg_match($m, $c)) {
                        $idx[$cle] = $i;
                        continue 3;
                    }
                }
            }
        }
        return $idx;
    }

    /** @return array{eleves: array<int, array{nom:string, prenom:string, code:string, note:?float}>, colonnes: array, avertissements: string[]} */
    public static function lireFichier(string $chemin): array
    {
        $classeur = IOFactory::load($chemin);
        $lignes = $classeur->getSheet(0)->toArray(null, true, false, false);
        $avert = [];

        $iEntete = -1;
        $col = [];
        for ($i = 0; $i < min(count($lignes), 30); $i++) {
            $c = self::trouverColonnes($lignes[$i]);
            if (isset($c['nom']) || isset($c['complet'])) {
                $iEntete = $i;
                $col = $c;
                break;
            }
        }
        if ($iEntete === -1) {
            $avert[] = "Aucun en-tête reconnu : la 1re colonne est utilisée comme nom de l'élève et la dernière colonne numérique comme note.";
            $col = ['complet' => 0];
        }

        $eleves = [];
        for ($i = $iEntete + 1, $n = count($lignes); $i < $n; $i++) {
            $l = $lignes[$i];
            $nom = $prenom = '';
            if (isset($col['nom'])) {
                $nom = trim((string) ($l[$col['nom']] ?? ''));
                $prenom = isset($col['prenom']) ? trim((string) ($l[$col['prenom']] ?? '')) : '';
            } else {
                $parts = explode(' ', trim(preg_replace('/\s+/u', ' ', (string) ($l[$col['complet']] ?? ''))));
                $nom = array_shift($parts) ?? '';
                $prenom = implode(' ', $parts);
            }
            if ($nom === '' || preg_match('/^(total|moyenne|nb|nombre)/', Texte::normaliser($nom))) {
                continue;
            }
            $note = null;
            if (isset($col['note'])) {
                $note = Texte::nombre($l[$col['note']] ?? null);
            } else {
                for ($j = count($l) - 1; $j >= 0; $j--) {
                    if (in_array($j, array_intersect_key($col, array_flip(['complet', 'nom', 'prenom', 'code'])), true)) {
                        continue;
                    }
                    if (is_int($l[$j]) || is_float($l[$j])) {
                        $note = (float) $l[$j];
                        break;
                    }
                }
            }
            $code = isset($col['code']) ? trim((string) ($l[$col['code']] ?? '')) : '';
            $eleves[] = ['nom' => $nom, 'prenom' => $prenom, 'code' => $code, 'note' => $note];
        }
        if (!isset($col['note']) && $iEntete !== -1) {
            $avert[] = 'Colonne « Note » non trouvée : notes déduites de la dernière colonne numérique.';
        }
        return ['eleves' => $eleves, 'colonnes' => $col, 'avertissements' => $avert];
    }
}
