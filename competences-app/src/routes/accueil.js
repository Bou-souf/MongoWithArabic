const express = require('express');
const { Classe, Controle, Eleve, Lacune, Evaluation } = require('../models');
const { filtreProf } = require('../middleware/auth');
const { evolutionClasse } = require('../services/stats');

const router = express.Router();

async function elevesAccessibles(req) {
  const classes = await Classe.find(filtreProf(req)).select('_id');
  return Eleve.find({ classe: { $in: classes.map(c => c._id) } }).select('_id');
}

router.get('/', async (req, res) => {
  const filtre = filtreProf(req);
  const classes = await Classe.find(filtre).sort({ nom: 1 });
  const controles = await Controle.find(filtre).populate('classe', 'nom').sort({ date: -1 }).limit(8);
  const eleves = await elevesAccessibles(req);
  const ids = eleves.map(e => e._id);
  const lacunes = await Lacune.find({ eleve: { $in: ids } }).select('statut suivis');
  const nbAlertes = lacunes.filter(l => l.niveauAlerte > 0).length;
  const progression = {};
  for (const c of controles) {
    const n = await Evaluation.countDocuments({ controle: c._id, evaluee: true });
    const t = await Evaluation.countDocuments({ controle: c._id });
    progression[c._id] = { n, t };
  }
  res.render('accueil', {
    titre: 'Accueil',
    classes, controles, progression,
    stats: {
      classes: classes.length,
      eleves: ids.length,
      controles: await Controle.countDocuments(filtre),
      lacunesOuvertes: lacunes.filter(l => l.statut === 'ouverte').length,
      lacunesRemediees: lacunes.filter(l => l.statut === 'remediee').length,
      alertes: nbAlertes,
    },
  });
});

// Statistiques par classe et par matière
router.get('/statistiques', async (req, res) => {
  const classes = await Classe.find(filtreProf(req)).sort({ nom: 1 });
  const classe = classes.find(c => String(c._id) === req.query.classe) || classes[0];
  let matieres = [];
  let evolution = null;
  let matiere = req.query.matiere || '';
  if (classe) {
    matieres = await Controle.distinct('matiere', { classe: classe._id });
    if (matiere && !matieres.includes(matiere)) matiere = '';
    if (!matiere && matieres.length) matiere = matieres[0];
    evolution = await evolutionClasse(classe._id, matiere);
  }
  res.render('statistiques', { titre: 'Statistiques', classes, classe, matieres, matiere, evolution });
});

// Alertes : lacunes non remédiées
router.get('/alertes', async (req, res) => {
  const eleves = await elevesAccessibles(req);
  const lacunes = await Lacune.find({ eleve: { $in: eleves.map(e => e._id) }, statut: 'ouverte' })
    .populate({ path: 'eleve', populate: { path: 'classe', select: 'nom' } })
    .populate('controleOrigine', 'titre date')
    .populate('suivis.controle', 'titre date');
  const avecAlerte = lacunes.filter(l => l.niveauAlerte > 0).sort((a, b) => b.niveauAlerte - a.niveauAlerte || b.echecs - a.echecs);
  const sansSuivi = lacunes.filter(l => l.suivis.length === 0);
  // Ancienneté : lacune ouverte depuis plus de 30 jours sans suivi
  const limite = Date.now() - 30 * 24 * 3600 * 1000;
  const anciennes = sansSuivi.filter(l => new Date(l.createdAt).getTime() < limite);
  res.render('alertes', { titre: 'Alertes', avecAlerte, anciennes, nbOuvertes: lacunes.length });
});

module.exports = router;
