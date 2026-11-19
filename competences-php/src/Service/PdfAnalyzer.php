<?php
declare(strict_types=1);

namespace App\Service;

use App\Config;
use Smalot\PdfParser\Parser;

/**
 * Analyse d'un contrôle PDF : extraction du texte, découpage en questions
 * et détection des compétences évaluées par chaque question.
 *  - mode "regles" (par défaut, hors-ligne) : expressions régulières + référentiel ;
 *  - mode "ia" (si une clé Anthropic est configurée) : le PDF est envoyé à Claude ;
 *    en cas d'erreur on revient automatiquement au mode "regles".
 */
final class PdfAnalyzer
{
    private const DEBUT_EXERCICE = '/^\s*(exercice|exercise|ex\.?|partie|probleme|situation|التمرين|تمرين)\s*([0-9ivxlc]+|[a-z])?\b/iu';
    private const DEBUT_QUESTION = '/^\s*(?:(question|q)\s*([0-9]+(?:[.\-][0-9a-z]+)*)|([0-9]{1,2}(?:[.\-][0-9]{1,2})*)\s*[).\-–:]|([a-h])\s*[)\-])\s*(.*)$/iu';
    private const BAREME = '/\(?\s*([0-9]+(?:[.,][0-9]+)?)\s*(?:pts?|points?|pt|ن|نقط|نقطة)\s*\)?/iu';

    public static function extraireTexte(string $contenu): string
    {
        $doc = (new Parser())->parseContent($contenu);
        return str_replace("\r", '', $doc->getText());
    }

    private static function bareme(string $t): ?float
    {
        return preg_match(self::BAREME, $t, $m) ? (float) str_replace(',', '.', $m[1]) : null;
    }

    /** @return array<int, array{numero:string, enonce:string, bareme:?float, contexte:string}> */
    public static function decouperQuestions(string $texte): array
    {
        $lignes = array_values(array_filter(array_map('trim', explode("\n", $texte)), fn ($l) => $l !== ''));
        $questions = [];
        $exercice = null;
        $nbExercices = 0;
        $courante = null;
        $contexte = '';
        $pousser = function () use (&$courante, &$questions) {
            if ($courante && trim($courante['enonce']) !== '') {
                $questions[] = $courante;
            }
            $courante = null;
        };

        foreach ($lignes as $ligne) {
            if (preg_match(self::DEBUT_EXERCICE, Texte::normaliser($ligne), $m) && mb_strlen($ligne) < 120) {
                $pousser();
                $nbExercices++;
                $exercice = mb_strtoupper(($m[2] ?? '') !== '' ? $m[2] : (string) $nbExercices);
                $contexte = $ligne;
                continue;
            }
            if (preg_match(self::DEBUT_QUESTION, $ligne, $m)) {
                $pousser();
                $num = ($m[2] ?? '') !== '' ? $m[2] : ((($m[3] ?? '') !== '') ? $m[3] : $m[4]);
                $courante = [
                    'numero' => $exercice !== null ? "Ex{$exercice}-{$num}" : (string) $num,
                    'enonce' => $ligne,
                    'bareme' => self::bareme($ligne),
                    'contexte' => $contexte,
                ];
                continue;
            }
            if ($courante) {
                $courante['enonce'] .= ' ' . $ligne;
                $courante['bareme'] ??= self::bareme($ligne);
            } elseif ($exercice !== null) {
                $contexte .= ' ' . $ligne;
            }
        }
        $pousser();

        // Aucun découpage possible : découpage par phrases
        if (!$questions) {
            $phrases = preg_split('/(?<=[?.!])\s+/u', $texte);
            $i = 0;
            foreach ($phrases as $p) {
                $p = trim($p);
                if (mb_strlen($p) > 15) {
                    $questions[] = ['numero' => (string) ++$i, 'enonce' => $p, 'bareme' => self::bareme($p), 'contexte' => ''];
                }
            }
        }
        return $questions;
    }

    /** @return array<int, array{numero:string, enonce:string, bareme:?float, competences:string[]}> */
    public static function analyserParRegles(string $texte): array
    {
        $out = [];
        foreach (self::decouperQuestions($texte) as $q) {
            $c = CompetencesRules::pour($q['enonce']);
            if ($c === [CompetencesRules::PAR_DEFAUT] && $q['contexte'] !== '') {
                $c = CompetencesRules::pour($q['contexte']);
            }
            $out[] = ['numero' => $q['numero'], 'enonce' => mb_substr($q['enonce'], 0, 2000), 'bareme' => $q['bareme'], 'competences' => $c];
        }
        return $out;
    }

    private const SCHEMA_IA = [
        'type' => 'object',
        'additionalProperties' => false,
        'required' => ['questions'],
        'properties' => [
            'questions' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['numero', 'enonce', 'bareme', 'competences'],
                    'properties' => [
                        'numero' => ['type' => 'string'],
                        'enonce' => ['type' => 'string'],
                        'bareme' => ['type' => ['number', 'null']],
                        'competences' => ['type' => 'array', 'items' => ['type' => 'string']],
                    ],
                ],
            ],
        ],
    ];

    public static function analyserParIA(string $contenu, string $matiere): array
    {
        $client = new \Anthropic\Client(apiKey: (string) Config::get('anthropic_api_key'));
        $message = $client->messages->create(
            model: (string) Config::get('claude_model', 'claude-opus-5-5'),
            maxTokens: 16000,
            outputConfig: ['effort' => 'medium', 'format' => ['type' => 'json_schema', 'schema' => self::SCHEMA_IA]],
            messages: [[
                'role' => 'user',
                'content' => [
                    ['type' => 'document', 'source' => ['type' => 'base64', 'mediaType' => 'application/pdf', 'data' => base64_encode($contenu)]],
                    ['type' => 'text', 'text' => "Ce document est un contrôle (devoir surveillé) de la matière « {$matiere} ». "
                        . 'Découpe-le en questions (numérote-les comme dans le document, ex. "Ex1-2" pour la question 2 de l\'exercice 1). '
                        . 'Pour chaque question, donne l\'énoncé (résumé si très long), le barème si indiqué (sinon null), '
                        . 'et 1 à 3 compétences précises évaluées, formulées en français comme dans un référentiel scolaire '
                        . '(ex. "Résoudre une équation du premier degré", "Calculer une moyenne", "Analyser un document historique"). '
                        . 'Réutilise exactement le même libellé quand une compétence revient dans plusieurs questions.'],
                ],
            ]],
        );
        $stop = is_object($message->stopReason) ? $message->stopReason->value : (string) $message->stopReason;
        if ($stop === 'refusal' || $stop === 'max_tokens') {
            throw new \RuntimeException("Analyse IA interrompue ($stop)");
        }
        $json = '';
        foreach ($message->content as $bloc) {
            if ($bloc->type === 'text') {
                $json .= $bloc->text;
            }
        }
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        return array_map(fn ($q) => [
            'numero' => (string) $q['numero'],
            'enonce' => (string) $q['enonce'],
            'bareme' => isset($q['bareme']) ? (float) $q['bareme'] : null,
            'competences' => Texte::liste($q['competences']),
        ], $data['questions']);
    }

    /** @return array{texte:string, questions:array, methode:string} */
    public static function analyser(string $contenu, string $matiere, bool $utiliserIA = true): array
    {
        $texte = self::extraireTexte($contenu);
        if ($utiliserIA && Config::get('anthropic_api_key')) {
            try {
                $questions = self::analyserParIA($contenu, $matiere);
                if ($questions) {
                    return ['texte' => $texte, 'questions' => $questions, 'methode' => 'ia'];
                }
            } catch (\Throwable $e) {
                error_log('[analyse IA] échec, retour au mode règles : ' . $e->getMessage());
            }
        }
        return ['texte' => $texte, 'questions' => self::analyserParRegles($texte), 'methode' => 'regles'];
    }
}
