// Enregistrement d'une évaluation et gestion du cycle de vie des lacunes :
//   ouverte  --(prof confirme "remédiée" lors d'un contrôle suivant)--> remediee
//   ouverte  --(prof confirme "non remédiée")--> reste ouverte + alerte
const { Evaluation, Lacune } = require('../models');

/** Lacunes antérieures (même matière) à faire confirmer pour un élève lors de ce contrôle */
async function lacunesASuivre(controle, eleveIds) {
  const lacunes = await Lacune.find({
    eleve: { $in: eleveIds },
    matiere: controle.matiere,
    controleOrigine: { $ne: controle._id },
    $or: [{ statut: 'ouverte' }, { controleRemediation: controle._id }],
  }).populate('controleOrigine', 'titre date').sort({ createdAt: 1 });
  const parEleve = new Map();
  for (const l of lacunes) {
    // On ne propose pas une lacune apparue dans un contrôle postérieur
    if (l.controleOrigine && controle.date && l.controleOrigine.date > controle.date) continue;
    const k = String(l.eleve);
    if (!parEleve.has(k)) parEleve.set(k, []);
    parEleve.get(k).push(l);
  }
  return parEleve;
}

function nettoyerListe(v) {
  if (!v) return [];
  const arr = Array.isArray(v) ? v : String(v).split(/[\n;,]+/);
  return [...new Set(arr.map(s => String(s).trim()).filter(Boolean))];
}

/**
 * Enregistre l'évaluation d'un élève pour un contrôle.
 * @param {object} donnees { maitrisees: string[], lacunesLibres: string[], note, commentaire }
 */
async function enregistrerEvaluation(controle, eleveId, donnees) {
  const competences = controle.competences;
  const maitrisees = competences.filter(c => (donnees.maitrisees || []).includes(c));
  const nonMaitrisees = competences.filter(c => !maitrisees.includes(c));
  const lacunesLibres = nettoyerListe(donnees.lacunesLibres).filter(l => !competences.includes(l));

  const maj = { maitrisees, nonMaitrisees, lacunesLibres, evaluee: true };
  if (donnees.commentaire !== undefined) maj.commentaire = String(donnees.commentaire).trim();
  if (donnees.note !== undefined && donnees.note !== '') {
    const n = parseFloat(String(donnees.note).replace(',', '.'));
    if (Number.isFinite(n)) maj.note = n;
  }
  await Evaluation.findOneAndUpdate({ controle: controle._id, eleve: eleveId }, { $set: maj }, { upsert: true });

  // Lacunes issues de ce contrôle
  const attendues = [
    ...nonMaitrisees.map(c => ({ competence: c, origine: 'systeme' })),
    ...lacunesLibres.map(c => ({ competence: c, origine: 'professeur' })),
  ];
  const existantes = await Lacune.find({ eleve: eleveId, controleOrigine: controle._id });
  // Supprime les lacunes de ce contrôle qui ne sont plus d'actualité (correction de saisie)
  for (const l of existantes) {
    if (!attendues.some(a => a.competence === l.competence) && l.suivis.length === 0) await l.deleteOne();
  }
  for (const a of attendues) {
    if (existantes.some(l => l.competence === a.competence)) continue;
    // Une lacune identique déjà ouverte (contrôle antérieur) n'est pas dupliquée :
    // son suivi est géré par la confirmation du professeur.
    const dejaOuverte = await Lacune.exists({ eleve: eleveId, matiere: controle.matiere, competence: a.competence, statut: 'ouverte' });
    if (dejaOuverte) continue;
    await Lacune.create({ eleve: eleveId, matiere: controle.matiere, competence: a.competence, origine: a.origine, controleOrigine: controle._id });
  }
}

/**
 * Applique les réponses du professeur sur les lacunes antérieures.
 * @param {object} reponses { [lacuneId]: 'oui' | 'non' | '' }
 */
async function enregistrerSuivis(controle, reponses, eleveIdsAutorises) {
  const autorises = new Set(eleveIdsAutorises.map(String));
  for (const [id, rep] of Object.entries(reponses || {})) {
    if (rep !== 'oui' && rep !== 'non') continue;
    const l = await Lacune.findById(id).catch(() => null);
    if (!l || !autorises.has(String(l.eleve)) || l.matiere !== controle.matiere) continue;
    l.suivis = l.suivis.filter(s => String(s.controle) !== String(controle._id));
    l.suivis.push({ controle: controle._id, date: controle.date || new Date(), remediee: rep === 'oui' });
    if (rep === 'oui') {
      l.statut = 'remediee';
      l.dateRemediation = controle.date || new Date();
      l.controleRemediation = controle._id;
    } else if (String(l.controleRemediation) === String(controle._id) || l.statut === 'remediee') {
      l.statut = 'ouverte';
      l.dateRemediation = undefined;
      l.controleRemediation = undefined;
    }
    await l.save();
  }
}

module.exports = { lacunesASuivre, enregistrerEvaluation, enregistrerSuivis, nettoyerListe };
