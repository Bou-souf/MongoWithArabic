<?php
declare(strict_types=1);

namespace App\Service;

/**
 * Référentiel de règles : verbes / mots-clés d'action -> compétence évaluée.
 * Inspiré de la taxonomie de Bloom et des référentiels usuels (collège / lycée).
 * Les motifs s'appliquent sur l'énoncé normalisé (minuscules, sans accents).
 */
final class CompetencesRules
{
    public const PAR_DEFAUT = 'Mobiliser les connaissances du cours';

    public const REGLES = [
        ['Restituer des connaissances', ['\bdefini[rst]?\b', '\bdefinition\b', '\bciter?\b', '\bcitez\b', '\brappeler?\b', '\brappelez\b', '\bnommer?\b', '\bnommez\b', '\benoncer?\b', '\benoncez\b', 'qu.est.ce qu', '\brecopier?\b', '\bcompleter? (le|la|les) (texte|phrase|tableau)', '\bvrai ou faux\b', '\bqcm\b', '\bcochez?\b', 'عرف', 'اذكر', 'أذكر']],
        ['Comprendre et expliquer', ['\bexpliquer?\b', '\bexpliquez\b', '\bpourquoi\b', '\bjustifie[rz]?\b', '\binterpreter?\b', '\binterpretez\b', '\bdecrire\b', '\bdecrivez\b', '\bcommenter?\b', '\bcommentez\b', '\breformuler?\b', 'اشرح', 'علل', 'فسر']],
        ['Calculer et appliquer une formule', ['\bcalcule[rz]?\b', '\bcalcul\b', '\bevaluer? l.expression\b', '\bsimplifier?\b', '\bsimplifiez\b', '\bdeterminer? la valeur\b', '\bcombien\b', '\bconvertir?\b', '\bconvertissez\b', 'احسب', 'أحسب', 'بسط']],
        ['Développer et factoriser une expression', ['\bdevelopper?\b', '\bdeveloppez\b', '\bfactoriser?\b', '\bfactorisez\b', '\bidentites? remarquables?\b', 'انشر']],
        ['Exploiter des données statistiques', ['\bmoyenne\b', '\bmediane\b', '\bstatistiques?\b', '\beffectifs?\b', '\bfrequences?\b', '\bdiagramme\b', '\bpourcentage\b']],
        ['Résoudre une équation / un problème', ['\bresoudre\b', '\bresolvez\b', '\bequation\b', '\binequation\b', '\bsysteme\b', '\bprobleme\b', 'حل المعادلة', 'حل النظمة']],
        ['Raisonner et démontrer', ['\bdemontrer?\b', '\bdemontrez\b', '\bmontrer? que\b', '\bmontrez que\b', '\bprouver?\b', '\bprouvez\b', '\ben deduire\b', '\bdeduire\b', '\bdeduisez\b', '\braisonnement\b', '\brecurrence\b', 'بين أن', 'برهن', 'استنتج']],
        ['Représenter graphiquement / construire', ['\btracer?\b', '\btracez\b', '\brepresenter?\b', '\brepresentez\b', '\bconstruire\b', '\bconstruisez\b', '\bgraphique\b', '\bcourbe\b', '\bschema\b', '\bschematiser?\b', '\bdessiner?\b', '\bdessinez\b', '\brepere\b', '\bplacer? (le|les) points?\b', 'أنشئ', 'ارسم', 'مثل']],
        ['Analyser des données / un document', ['\banalyser?\b', '\banalysez\b', '\bexploiter?\b', '\bexploitez\b', '\ba partir (du|de la|des) (document|tableau|graphique|texte|courbe)', '\blire (le|la|les)\b', '\brelever?\b', '\brelevez\b', '\bextraire\b', '\bidentifier?\b', '\bidentifiez\b', '\bdocument\b', 'حلل', 'استخرج']],
        ['Comparer et classer', ['\bcomparer?\b', '\bcomparez\b', '\bclasser?\b', '\bclassez\b', '\bordonner?\b', '\bordonnez\b', '\branger?\b', '\brangez\b', '\bdistinguer?\b', 'قارن', 'رتب']],
        ['Modéliser une situation', ['\bmodeliser?\b', '\bmodelisez\b', '\bmettre en equation\b', '\bexprimer? .* en fonction de\b', '\bexprimez\b', 'عبر عن']],
        ['Démarche expérimentale / scientifique', ['\bexperience\b', '\bexperimental', '\bhypothese\b', '\bprotocole\b', '\bmesurer?\b', '\bmesurez\b', '\bobserver?\b', '\bobservez\b', '\bmanipulation\b', 'فرضية', 'تجربة']],
        ['Conclure / porter un jugement critique', ['\bconclure\b', '\bconcluez\b', '\bconclusion\b', '\bvalider?\b', '\bvalidez\b', '\bverifier?\b', '\bverifiez\b', '\bcritiquer?\b', '\bdonner? (votre|ton) avis\b', '\bdiscuter?\b', '\bdiscutez\b', '\bargumenter?\b', '\bargumentez\b', 'تحقق', 'ناقش']],
        ['Compréhension de l\'écrit', ['\blisez le texte\b', '\bd.apres le texte\b', '\bselon le texte\b', '\bdans le texte\b', '\bread the text\b', '\baccording to the text\b', '\bcomprehension\b', 'حسب النص', 'من النص']],
        ['Maîtrise de la langue (grammaire, conjugaison, vocabulaire)', ['\bconjuguer?\b', '\bconjuguez\b', '\bgrammaire\b', '\bsynonyme\b', '\bantonyme\b', '\btransformer? (la|les) phrases?\b', '\btransformez\b', '\bmettez au\b', '\bmettre au (pluriel|passe|futur|feminin)', '\bfill in\b', '\bvocabulaire\b', '\btemps verba', 'أعرب', 'صرف']],
        ['Production écrite / rédaction', ['\bredige[rz]?\b', '\bredaction\b', '\bproduction ecrite\b', '\becrire un (texte|paragraphe|recit)\b', '\bwrite (a|an) (paragraph|essay|letter)\b', '\bparagraphe argumentatif\b', '\ben (\d+|quelques) lignes\b', 'حرر', 'اكتب', 'أكتب']],
        ['Se repérer dans le temps et l\'espace', ['\bchronolog', '\bfrise\b', '\bsituer?\b', '\bsituez\b', '\blocaliser?\b', '\blocalisez\b', '\bcarte\b', 'حدد موقع']],
        ['Programmer / concevoir un algorithme', ['\balgorithme\b', '\bprogramme[rz]?\b', '\bpseudo.?code\b', '\bboucle\b', '\bpython\b', '\bscratch\b', 'خوارزمية']],
    ];

    /** @return string[] */
    public static function pour(?string $enonce): array
    {
        $t = Texte::normaliser($enonce);
        $trouvees = [];
        foreach (self::REGLES as [$competence, $motifs]) {
            foreach ($motifs as $m) {
                if (preg_match('/' . str_replace('/', '\/', $m) . '/u', $t)) {
                    $trouvees[] = $competence;
                    break;
                }
            }
        }
        return $trouvees ?: [self::PAR_DEFAUT];
    }
}
