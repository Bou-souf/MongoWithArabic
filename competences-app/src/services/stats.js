// Calculs statistiques : tableau de bord d'un contrôle, évolution d'une classe
// dans une matière, évolution d'un élève.

const { Controle, Evaluation, Eleve, Lacune } = require('../models');
const config = require('../config');

const pct = (n, d) => (d ? Math.round((n / d) * 1000) / 10 : 0);
const arrondi = (x) => (x === null || x === undefined ? null : Math.round(x * 100) / 100);

function resumeNotes(notes, noteSur = 20) {
  const ns = notes.filter(n => typeof n === 'number');
  if (!ns.length) return { moyenne: null, min: null, max: null, tauxReussite: null, nb: 0 };
  const seuil = (config.seuilReussite / 20) * noteSur;
  return {
    moyenne: arrondi(ns.reduce((a, b) => a + b, 0) / ns.length),
    min: Math.min(...ns),
    max: Math.max(...ns),
    tauxReussite: pct(ns.filter(n => n >= seuil).length, ns.length),
    nb: ns.length,
  };
}

/** Tableau de bord d'un contrôle */
async function dashboardControle(controle) {
  const evals = await Evaluation.find({ controle: controle._id }).populate('eleve').lean();
  const evaluees = evals.filter(e => e.evaluee && e.eleve);
  const competences = controle.competences;

  // Taux de maîtrise par compétence
  const parCompetence = competences.map(c => {
    const maitrise = evaluees.filter(e => e.maitrisees.includes(c)).length;
    return {
      competence: c,
      maitrise,
      nonMaitrise: evaluees.length - maitrise,
      pctMaitrise: pct(maitrise, evaluees.length),
      pctLacune: pct(evaluees.length - maitrise, evaluees.length),
    };
  });

  // Global : proportion de couples (élève, compétence) maîtrisés
  const totalCases = evaluees.length * competences.length;
  const totalMaitrise = parCompetence.reduce((a, c) => a + c.maitrise, 0);

  // Groupes de besoin : élèves regroupés par compétence non maîtrisée
  // (y compris les lacunes saisies librement par le professeur)
  const groupes = new Map();
  for (const e of evaluees) {
    for (const c of [...e.nonMaitrisees, ...e.lacunesLibres]) {
      if (!groupes.has(c)) groupes.set(c, { competence: c, libre: !competences.includes(c), eleves: [] });
      const g = groupes.get(c);
      if (!g.eleves.some(x => String(x._id) === String(e.eleve._id))) g.eleves.push(e.eleve);
    }
  }
  const groupesTries = [...groupes.values()]
    .map(g => ({ ...g, pct: pct(g.eleves.length, evaluees.length) }))
    .sort((a, b) => b.eleves.length - a.eleves.length);

  // Profils : élèves regroupés par combinaison identique de lacunes
  const profils = new Map();
  for (const e of evaluees) {
    const lac = [...new Set([...e.nonMaitrisees, ...e.lacunesLibres])].sort();
    const cle = lac.join(' | ') || '__aucune__';
    if (!profils.has(cle)) profils.set(cle, { lacunes: lac, eleves: [] });
    profils.get(cle).eleves.push(e.eleve);
  }

  // Classement des élèves par taux de maîtrise
  const eleves = evaluees.map(e => ({
    eleve: e.eleve,
    note: e.note,
    pctMaitrise: pct(e.maitrisees.length, competences.length),
    lacunes: [...e.nonMaitrisees, ...e.lacunesLibres],
  })).sort((a, b) => b.pctMaitrise - a.pctMaitrise);

  return {
    nbEleves: evals.length,
    nbEvalues: evaluees.length,
    notes: resumeNotes(evals.map(e => e.note), controle.noteSur),
    pctMaitriseGlobal: pct(totalMaitrise, totalCases),
    pctLacuneGlobal: totalCases ? Math.round((100 - pct(totalMaitrise, totalCases)) * 10) / 10 : 0,
    parCompetence,
    groupes: groupesTries,
    profils: [...profils.values()].sort((a, b) => b.eleves.length - a.eleves.length),
    eleves,
    distributionNotes: distribution(evals.map(e => e.note), controle.noteSur),
  };
}

function distribution(notes, noteSur = 20) {
  const tranches = [0, 0.25, 0.5, 0.75, 1].map(x => x * noteSur);
  const labels = [];
  const valeurs = [];
  for (let i = 0; i < tranches.length - 1; i++) {
    labels.push(`${tranches[i]}–${tranches[i + 1]}`);
    valeurs.push(notes.filter(n => typeof n === 'number' && n >= tranches[i] && (i === tranches.length - 2 ? n <= tranches[i + 1] : n < tranches[i + 1])).length);
  }
  return { labels, valeurs };
}

/** Évolution d'une classe dans une matière (tous les contrôles, chronologiquement) */
async function evolutionClasse(classeId, matiere) {
  const filtre = { classe: classeId };
  if (matiere) filtre.matiere = matiere;
  const controles = await Controle.find(filtre).sort({ date: 1 });
  const lignes = [];
  const competencesSuivies = new Map(); // compétence -> [{controle, pct}]
  for (const c of controles) {
    const d = await dashboardControle(c);
    lignes.push({ controle: c, ...d.notes, pctMaitrise: d.pctMaitriseGlobal, nbEvalues: d.nbEvalues });
    for (const pc of d.parCompetence) {
      if (!competencesSuivies.has(pc.competence)) competencesSuivies.set(pc.competence, []);
      competencesSuivies.get(pc.competence).push({ controleId: String(c._id), controle: c.titre, date: c.date, pct: pc.pctMaitrise });
    }
  }
  const eleves = await Eleve.find({ classe: classeId }).select('_id');
  const lacFiltre = { eleve: { $in: eleves.map(e => e._id) } };
  if (matiere) lacFiltre.matiere = matiere;
  const lacunes = await Lacune.find(lacFiltre);
  return {
    controles: lignes,
    competences: [...competencesSuivies.entries()].map(([competence, points]) => ({ competence, points })),
    lacunes: {
      total: lacunes.length,
      ouvertes: lacunes.filter(l => l.statut === 'ouverte').length,
      remediees: lacunes.filter(l => l.statut === 'remediee').length,
      alertes: lacunes.filter(l => l.niveauAlerte > 0).length,
    },
  };
}

/** Évolution d'un élève */
async function evolutionEleve(eleveId) {
  const evals = await Evaluation.find({ eleve: eleveId }).populate('controle').lean();
  const lignes = evals
    .filter(e => e.controle)
    .sort((a, b) => new Date(a.controle.date) - new Date(b.controle.date))
    .map(e => {
      const comps = new Set((e.controle.questions || []).flatMap(q => q.competences));
      return {
        controle: e.controle,
        note: e.note,
        evaluee: e.evaluee,
        pctMaitrise: comps.size ? pct(e.maitrisees.length, comps.size) : null,
        maitrisees: e.maitrisees,
        lacunes: [...e.nonMaitrisees, ...e.lacunesLibres],
        commentaire: e.commentaire,
      };
    });
  const lacunes = await Lacune.find({ eleve: eleveId })
    .populate('controleOrigine', 'titre date')
    .populate('controleRemediation', 'titre date')
    .populate('suivis.controle', 'titre date')
    .sort({ statut: 1, updatedAt: -1 });
  return { lignes, lacunes };
}

module.exports = { dashboardControle, evolutionClasse, evolutionEleve, resumeNotes, pct };
