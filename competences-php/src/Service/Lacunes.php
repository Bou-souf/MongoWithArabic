<?php
declare(strict_types=1);

namespace App\Service;

use App\Db;

/**
 * Enregistrement d'une évaluation et cycle de vie des lacunes :
 *   ouverte --(prof confirme « remédiée » lors d'un contrôle suivant)--> remediee
 *   ouverte --(prof confirme « non remédiée »)--> reste ouverte + alerte
 */
final class Lacunes
{
    /** Lacunes antérieures (même matière) à faire confirmer, groupées par élève */
    public static function aSuivre(array $controle, array $eleveIds): array
    {
        if (!$eleveIds) {
            return [];
        }
        $lacunes = Repo::lacunes(
            'l.eleve_id IN (' . Db::in($eleveIds) . ') AND l.matiere = ?
             AND (l.controle_origine_id IS NULL OR l.controle_origine_id <> ?)
             AND (l.statut = \'ouverte\' OR l.controle_remediation_id = ?)
             AND (co.date IS NULL OR co.date <= ?)',
            [...$eleveIds, $controle['matiere'], $controle['id'], $controle['id'], $controle['date']],
            'l.created_at, l.id'
        );
        $parEleve = [];
        foreach ($lacunes as $l) {
            $parEleve[$l['eleve_id']][] = $l;
        }
        return $parEleve;
    }

    /**
     * Enregistre l'évaluation d'un élève.
     * @param array{maitrisees?:string[], lacunes_libres?:mixed, note?:mixed, commentaire?:string} $donnees
     */
    public static function enregistrerEvaluation(array $controle, int $eleveId, array $donnees): void
    {
        $competences = $controle['competences'];
        $maitrisees = array_values(array_filter($competences, fn ($c) => in_array($c, $donnees['maitrisees'] ?? [], true)));
        $nonMaitrisees = array_values(array_diff($competences, $maitrisees));
        $libres = array_values(array_filter(Texte::liste($donnees['lacunes_libres'] ?? []), fn ($l) => !in_array($l, $competences, true)));

        $eval = Db::one('SELECT * FROM evaluations WHERE controle_id = ? AND eleve_id = ?', [$controle['id'], $eleveId]);
        $note = array_key_exists('note', $donnees) ? Texte::nombre($donnees['note']) : ($eval['note'] ?? null);
        if (array_key_exists('note', $donnees) && ($donnees['note'] === '' || $donnees['note'] === null)) {
            $note = $eval['note'] ?? null; // une case vide ne supprime pas une note importée
        }
        if ($eval) {
            Db::exec('UPDATE evaluations SET evaluee = 1, note = ?, commentaire = COALESCE(?, commentaire) WHERE id = ?',
                [$note, $donnees['commentaire'] ?? null, $eval['id']]);
            $evalId = (int) $eval['id'];
        } else {
            $evalId = Db::insert('evaluations', ['controle_id' => $controle['id'], 'eleve_id' => $eleveId, 'note' => $note, 'evaluee' => 1, 'commentaire' => $donnees['commentaire'] ?? null]);
        }
        Db::exec('DELETE FROM evaluation_competences WHERE evaluation_id = ?', [$evalId]);
        foreach ([['maitrisee', $maitrisees], ['non_maitrisee', $nonMaitrisees], ['libre', $libres]] as [$statut, $liste]) {
            foreach ($liste as $c) {
                Db::insert('evaluation_competences', ['evaluation_id' => $evalId, 'competence' => $c, 'statut' => $statut]);
            }
        }

        // Lacunes issues de ce contrôle
        $attendues = [];
        foreach ($nonMaitrisees as $c) {
            $attendues[$c] = 'systeme';
        }
        foreach ($libres as $c) {
            $attendues[$c] = 'professeur';
        }
        $existantes = Repo::completerLacunes(Db::all('SELECT * FROM lacunes WHERE eleve_id = ? AND controle_origine_id = ?', [$eleveId, $controle['id']]));
        $dejaLa = [];
        foreach ($existantes as $l) {
            if (!isset($attendues[$l['competence']]) && !$l['suivis']) {
                Db::exec('DELETE FROM lacunes WHERE id = ?', [$l['id']]); // correction de saisie
            } else {
                $dejaLa[$l['competence']] = true;
            }
        }
        foreach ($attendues as $c => $origine) {
            if (isset($dejaLa[$c])) {
                continue;
            }
            // Une lacune identique déjà ouverte n'est pas dupliquée : son suivi passe par la confirmation du professeur
            $ouverte = Db::val("SELECT id FROM lacunes WHERE eleve_id = ? AND matiere = ? AND competence = ? AND statut = 'ouverte' LIMIT 1",
                [$eleveId, $controle['matiere'], $c]);
            if ($ouverte) {
                continue;
            }
            Db::insert('lacunes', ['eleve_id' => $eleveId, 'matiere' => $controle['matiere'], 'competence' => $c, 'origine' => $origine, 'controle_origine_id' => $controle['id']]);
        }
    }

    /** Réponses du professeur sur les lacunes antérieures : [lacuneId => 'oui'|'non'|''] */
    public static function enregistrerSuivis(array $controle, array $reponses, array $eleveIdsAutorises): void
    {
        $autorises = array_map('intval', $eleveIdsAutorises);
        foreach ($reponses as $id => $rep) {
            if ($rep !== 'oui' && $rep !== 'non') {
                continue;
            }
            $l = Db::one('SELECT * FROM lacunes WHERE id = ?', [(int) $id]);
            if (!$l || !in_array((int) $l['eleve_id'], $autorises, true) || $l['matiere'] !== $controle['matiere']) {
                continue;
            }
            Db::exec('DELETE FROM lacune_suivis WHERE lacune_id = ? AND controle_id = ?', [$l['id'], $controle['id']]);
            Db::insert('lacune_suivis', ['lacune_id' => $l['id'], 'controle_id' => $controle['id'], 'date' => $controle['date'], 'remediee' => $rep === 'oui' ? 1 : 0]);
            if ($rep === 'oui') {
                Db::exec("UPDATE lacunes SET statut = 'remediee', date_remediation = ?, controle_remediation_id = ? WHERE id = ?", [$controle['date'], $controle['id'], $l['id']]);
            } elseif ($l['statut'] === 'remediee') {
                Db::exec("UPDATE lacunes SET statut = 'ouverte', date_remediation = NULL, controle_remediation_id = NULL WHERE id = ?", [$l['id']]);
            }
        }
    }

    /** Avant suppression d'un contrôle : rouvre les lacunes qu'il avait remédiées */
    public static function avantSuppressionControle(int $controleId): void
    {
        Db::exec("UPDATE lacunes SET statut = 'ouverte', date_remediation = NULL, controle_remediation_id = NULL WHERE controle_remediation_id = ?", [$controleId]);
    }
}
