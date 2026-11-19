const express = require('express');
const multer = require('multer');
const { Classe, Eleve, Controle, Evaluation, Lacune, User } = require('../models');
const { flash, filtreProf, classeAccessible, verifierCsrf } = require('../middleware/auth');
const { lireFichierNotes } = require('../services/excelImport');

const router = express.Router();
const uploadExcel = multer({ storage: multer.memoryStorage(), limits: { fileSize: 10 * 1024 * 1024 } });

router.get('/', async (req, res) => {
  const classes = await Classe.find(filtreProf(req)).populate('prof', 'nom').sort({ nom: 1 });
  const effectifs = {};
  for (const e of await Eleve.aggregate([{ $match: { classe: { $in: classes.map(c => c._id) } } }, { $group: { _id: '$classe', n: { $sum: 1 } } }])) effectifs[e._id] = e.n;
  const profs = req.user.role === 'admin' ? await User.find({ actif: true }).sort({ nom: 1 }) : [];
  res.render('classes/liste', { titre: 'Classes', classes, effectifs, profs });
});

router.post('/', async (req, res) => {
  const { nom, niveau, anneeScolaire } = req.body;
  if (!nom) { flash(req, 'danger', 'Le nom de la classe est obligatoire.'); return res.redirect('/classes'); }
  const prof = req.user.role === 'admin' && req.body.prof ? req.body.prof : req.user._id;
  try {
    const c = await Classe.create({ nom, niveau, anneeScolaire, prof });
    flash(req, 'success', `Classe ${c.nom} créée.`);
    return res.redirect(`/classes/${c._id}`);
  } catch (e) {
    flash(req, 'danger', e.code === 11000 ? 'Cette classe existe déjà.' : 'Création impossible.');
    return res.redirect('/classes');
  }
});

router.get('/:id', async (req, res) => {
  const classe = await classeAccessible(req, req.params.id);
  if (!classe) return res.status(404).render('erreur', { titre: 'Classe introuvable', message: '' });
  await classe.populate('prof', 'nom');
  const eleves = await Eleve.find({ classe: classe._id }).sort({ nom: 1, prenom: 1 });
  const controles = await Controle.find({ classe: classe._id }).sort({ date: -1 });
  const lacunes = await Lacune.find({ eleve: { $in: eleves.map(e => e._id) }, statut: 'ouverte' }).select('eleve suivis statut');
  const parEleve = {};
  for (const l of lacunes) {
    const k = String(l.eleve);
    parEleve[k] = parEleve[k] || { ouvertes: 0, alertes: 0 };
    parEleve[k].ouvertes++;
    if (l.niveauAlerte > 0) parEleve[k].alertes++;
  }
  const profs = req.user.role === 'admin' ? await User.find({ actif: true }).sort({ nom: 1 }) : [];
  res.render('classes/detail', { titre: classe.nom, classe, eleves, controles, parEleve, profs });
});

router.post('/:id/eleves', async (req, res) => {
  const classe = await classeAccessible(req, req.params.id);
  if (!classe) return res.redirect('/classes');
  if (req.body.nom) {
    await Eleve.create({ nom: req.body.nom, prenom: req.body.prenom, code: req.body.code, classe: classe._id });
    flash(req, 'success', 'Élève ajouté.');
  }
  res.redirect(`/classes/${classe._id}`);
});

// Import d'une liste d'élèves depuis Excel (sans notes)
router.post('/:id/import', uploadExcel.single('fichier'), verifierCsrf, async (req, res) => {
  const classe = await classeAccessible(req, req.params.id);
  if (!classe) return res.redirect('/classes');
  if (!req.file) { flash(req, 'danger', 'Aucun fichier reçu.'); return res.redirect(`/classes/${classe._id}`); }
  try {
    const { eleves } = lireFichierNotes(req.file.buffer);
    const { crees } = await synchroniserEleves(classe, eleves);
    flash(req, 'success', `${eleves.length} lignes lues, ${crees} nouvel(le)s élève(s) ajouté(e)s.`);
  } catch (e) {
    flash(req, 'danger', `Lecture du fichier impossible : ${e.message}`);
  }
  res.redirect(`/classes/${classe._id}`);
});

router.post('/:id/eleves/:eleveId/supprimer', async (req, res) => {
  const classe = await classeAccessible(req, req.params.id);
  if (!classe) return res.redirect('/classes');
  const eleve = await Eleve.findOne({ _id: req.params.eleveId, classe: classe._id }).catch(() => null);
  if (eleve) {
    await Evaluation.deleteMany({ eleve: eleve._id });
    await Lacune.deleteMany({ eleve: eleve._id });
    await eleve.deleteOne();
    flash(req, 'success', 'Élève supprimé.');
  }
  res.redirect(`/classes/${classe._id}`);
});

router.post('/:id/supprimer', async (req, res) => {
  const classe = await classeAccessible(req, req.params.id);
  if (!classe) return res.redirect('/classes');
  if (await Controle.exists({ classe: classe._id })) {
    flash(req, 'danger', 'Supprimez d\'abord les contrôles de cette classe.');
    return res.redirect(`/classes/${classe._id}`);
  }
  const eleves = await Eleve.find({ classe: classe._id }).select('_id');
  await Lacune.deleteMany({ eleve: { $in: eleves.map(e => e._id) } });
  await Eleve.deleteMany({ classe: classe._id });
  await classe.deleteOne();
  flash(req, 'success', 'Classe supprimée.');
  res.redirect('/classes');
});

/** Rapproche les lignes importées des élèves existants (code puis nom+prénom), crée les manquants */
async function synchroniserEleves(classe, lignes) {
  const existants = await Eleve.find({ classe: classe._id });
  let crees = 0;
  const resultat = [];
  for (const l of lignes) {
    const cle = Eleve.cleDe(l.nom, l.prenom);
    let e = (l.code && existants.find(x => x.code && x.code === l.code)) || existants.find(x => x.cle === cle);
    if (!e) {
      e = await Eleve.create({ nom: l.nom, prenom: l.prenom, code: l.code || undefined, classe: classe._id });
      existants.push(e);
      crees++;
    } else if (l.code && !e.code) {
      e.code = l.code;
      await e.save();
    }
    resultat.push({ eleve: e, note: l.note });
  }
  return { crees, resultat };
}

module.exports = router;
module.exports.synchroniserEleves = synchroniserEleves;
