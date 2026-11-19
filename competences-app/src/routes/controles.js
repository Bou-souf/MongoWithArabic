const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const express = require('express');
const multer = require('multer');
const mongoose = require('mongoose');
const config = require('../config');
const { Classe, Controle, Eleve, Evaluation, Lacune } = require('../models');
const { flash, filtreProf, classeAccessible, verifierCsrf } = require('../middleware/auth');
const { analyserControle } = require('../services/pdfAnalyzer');
const { lireFichierNotes } = require('../services/excelImport');
const { dashboardControle } = require('../services/stats');
const { lacunesASuivre, enregistrerEvaluation, enregistrerSuivis, nettoyerListe } = require('../services/lacunes');
const { synchroniserEleves } = require('./classes');

const router = express.Router();

const upload = multer({
  storage: multer.memoryStorage(),
  limits: { fileSize: 25 * 1024 * 1024 },
});

async function chargerControle(req, res, next) {
  if (!mongoose.isValidObjectId(req.params.id)) return res.status(404).render('erreur', { titre: 'Contrôle introuvable', message: '' });
  const controle = await Controle.findOne({ _id: req.params.id, ...filtreProf(req) }).populate('classe');
  if (!controle) return res.status(404).render('erreur', { titre: 'Contrôle introuvable', message: '' });
  req.controle = controle;
  res.locals.controle = controle;
  next();
}

// ---------- Liste ----------
router.get('/', async (req, res) => {
  const filtre = filtreProf(req);
  if (req.query.classe && mongoose.isValidObjectId(req.query.classe)) filtre.classe = req.query.classe;
  if (req.query.matiere) filtre.matiere = req.query.matiere;
  const controles = await Controle.find(filtre).populate('classe', 'nom').sort({ date: -1 });
  const classes = await Classe.find(filtreProf(req)).sort({ nom: 1 });
  const matieres = await Controle.distinct('matiere', filtreProf(req));
  res.render('controles/liste', { titre: 'Contrôles', controles, classes, matieres, q: req.query });
});

// ---------- Création + analyse du PDF ----------
router.get('/nouveau', async (req, res) => {
  const classes = await Classe.find(filtreProf(req)).sort({ nom: 1 });
  if (!classes.length) {
    flash(req, 'warning', "Créez d'abord une classe.");
    return res.redirect('/classes');
  }
  res.render('controles/nouveau', { titre: 'Nouveau contrôle', classes, matieres: req.user.matieres, iaDisponible: !!config.anthropicApiKey, classeId: req.query.classe });
});

router.post('/', upload.fields([{ name: 'pdf', maxCount: 1 }, { name: 'excel', maxCount: 1 }]), verifierCsrf, async (req, res) => {
  const { titre, matiere, date, noteSur } = req.body;
  const classe = await classeAccessible(req, req.body.classe);
  const pdf = req.files && req.files.pdf && req.files.pdf[0];
  if (!classe || !titre || !matiere || !pdf) {
    flash(req, 'danger', 'Classe, titre, matière et fichier PDF sont obligatoires.');
    return res.redirect('/controles/nouveau');
  }
  if (pdf.mimetype !== 'application/pdf' && !pdf.originalname.toLowerCase().endsWith('.pdf')) {
    flash(req, 'danger', 'Le contrôle doit être un fichier PDF.');
    return res.redirect('/controles/nouveau');
  }

  let analyse;
  try {
    analyse = await analyserControle(pdf.buffer, { matiere, utiliserIA: req.body.ia === 'on' });
  } catch (e) {
    flash(req, 'danger', `Le PDF n'a pas pu être lu (${e.message}). Vérifiez qu'il ne s'agit pas d'un scan sans texte.`);
    return res.redirect('/controles/nouveau');
  }

  fs.mkdirSync(config.uploadDir, { recursive: true });
  const nomFichier = `${Date.now()}-${crypto.randomBytes(6).toString('hex')}.pdf`;
  fs.writeFileSync(path.join(config.uploadDir, nomFichier), pdf.buffer);

  const controle = await Controle.create({
    titre, matiere: matiere.trim(), classe: classe._id, prof: classe.prof,
    date: date ? new Date(date) : new Date(),
    noteSur: parseFloat(noteSur) || 20,
    fichierPdf: nomFichier, nomFichierOriginal: pdf.originalname,
    texte: analyse.texte, questions: analyse.questions, methodeAnalyse: analyse.methode,
  });

  // Excel des notes fourni dès la création (facultatif)
  const excel = req.files && req.files.excel && req.files.excel[0];
  if (excel) await importerNotes(req, controle, classe, excel.buffer);

  if (!analyse.questions.length) flash(req, 'warning', "Aucune question détectée automatiquement : ajoutez-les manuellement.");
  else flash(req, 'success', `${analyse.questions.length} question(s) détectée(s) (${analyse.methode === 'ia' ? 'analyse IA' : 'analyse par règles'}). Vérifiez et ajustez les compétences.`);
  res.redirect(`/controles/${controle._id}/competences`);
});

// ---------- Détail ----------
router.get('/:id', chargerControle, async (req, res) => {
  const evals = await Evaluation.find({ controle: req.controle._id }).populate('eleve').lean();
  evals.sort((a, b) => (a.eleve?.nom || '').localeCompare(b.eleve?.nom || ''));
  res.render('controles/detail', { titre: req.controle.titre, evals });
});

router.get('/:id/pdf', chargerControle, (req, res) => {
  if (!req.controle.fichierPdf) return res.status(404).end();
  const fichier = path.join(config.uploadDir, path.basename(req.controle.fichierPdf));
  if (!fs.existsSync(fichier)) return res.status(404).render('erreur', { titre: 'Fichier introuvable', message: '' });
  res.type('application/pdf');
  res.set('Content-Disposition', `inline; filename="${encodeURIComponent(req.controle.nomFichierOriginal || 'controle.pdf')}"`);
  res.sendFile(fichier);
});

// ---------- Révision des questions / compétences ----------
router.get('/:id/competences', chargerControle, async (req, res) => {
  // Suggestions : compétences déjà utilisées dans la même matière
  const autres = await Controle.find({ matiere: req.controle.matiere, ...filtreProf(req) }).select('questions.competences');
  const suggestions = [...new Set(autres.flatMap(c => c.questions.flatMap(q => q.competences)))].sort();
  res.render('controles/competences', { titre: `Compétences — ${req.controle.titre}`, suggestions });
});

router.post('/:id/competences', chargerControle, async (req, res) => {
  const qs = Object.values(req.body.questions || {});
  const questions = qs
    .filter(q => q && q.supprimer !== 'on' && (q.enonce || q.competences))
    .map(q => ({
      numero: String(q.numero || '').trim(),
      enonce: String(q.enonce || '').trim(),
      bareme: q.bareme ? parseFloat(String(q.bareme).replace(',', '.')) || undefined : undefined,
      competences: nettoyerListe(String(q.competences || '').split(/[\n;]+/)),
    }));
  const anciennes = req.controle.competences;
  req.controle.questions = questions;
  if (req.body.titre) req.controle.titre = req.body.titre;
  await req.controle.save();
  // Si des compétences ont été renommées / retirées, on garde les évaluations cohérentes
  const nouvelles = req.controle.competences;
  if (anciennes.join('|') !== nouvelles.join('|')) {
    const evals = await Evaluation.find({ controle: req.controle._id, evaluee: true });
    for (const e of evals) {
      await enregistrerEvaluation(req.controle, e.eleve, { maitrisees: e.maitrisees, lacunesLibres: e.lacunesLibres });
    }
  }
  flash(req, 'success', 'Questions et compétences enregistrées.');
  res.redirect(req.body.suite === 'evaluation' ? `/controles/${req.controle._id}/evaluation` : `/controles/${req.controle._id}`);
});

// ---------- Import Excel des notes ----------
async function importerNotes(req, controle, classe, buffer) {
  try {
    const { eleves, avertissements } = lireFichierNotes(buffer);
    if (!eleves.length) { flash(req, 'danger', 'Aucun élève trouvé dans le fichier Excel.'); return; }
    const { crees, resultat } = await synchroniserEleves(classe, eleves);
    for (const r of resultat) {
      const set = {};
      if (typeof r.note === 'number') set.note = r.note;
      await Evaluation.updateOne({ controle: controle._id, eleve: r.eleve._id }, { $set: set, $setOnInsert: { evaluee: false } }, { upsert: true });
    }
    avertissements.forEach(a => flash(req, 'warning', a));
    flash(req, 'success', `${resultat.length} élève(s) importé(s) (${crees} nouveau(x)), ${resultat.filter(r => typeof r.note === 'number').length} note(s).`);
  } catch (e) {
    flash(req, 'danger', `Lecture du fichier Excel impossible : ${e.message}`);
  }
}

router.post('/:id/import', upload.single('excel'), verifierCsrf, chargerControle, async (req, res) => {
  if (!req.file) flash(req, 'danger', 'Aucun fichier reçu.');
  else await importerNotes(req, req.controle, req.controle.classe, req.file.buffer);
  res.redirect(`/controles/${req.controle._id}`);
});

// Ajoute toute la liste de la classe (sans Excel)
router.post('/:id/ajouter-classe', chargerControle, async (req, res) => {
  const eleves = await Eleve.find({ classe: req.controle.classe._id });
  for (const e of eleves) {
    await Evaluation.updateOne({ controle: req.controle._id, eleve: e._id }, { $setOnInsert: { evaluee: false } }, { upsert: true });
  }
  flash(req, 'success', `${eleves.length} élève(s) de la classe ajouté(s).`);
  res.redirect(`/controles/${req.controle._id}`);
});

// ---------- Grille d'évaluation des compétences ----------
router.get('/:id/evaluation', chargerControle, async (req, res) => {
  const evals = await Evaluation.find({ controle: req.controle._id }).populate('eleve').lean();
  const lignes = evals.filter(e => e.eleve).sort((a, b) => a.eleve.nom.localeCompare(b.eleve.nom) || (a.eleve.prenom || '').localeCompare(b.eleve.prenom || ''));
  const suivis = await lacunesASuivre(req.controle, lignes.map(l => l.eleve._id));
  // Toutes les lacunes libres déjà utilisées dans la matière (aide à la saisie)
  const libres = await Lacune.distinct('competence', { matiere: req.controle.matiere, origine: 'professeur' });
  res.render('controles/evaluation', { titre: `Évaluation — ${req.controle.titre}`, lignes, suivis, libres });
});

router.post('/:id/evaluation', chargerControle, async (req, res) => {
  const competences = req.controle.competences;
  const evals = await Evaluation.find({ controle: req.controle._id }).select('eleve');
  const autorises = evals.map(e => String(e.eleve));
  const donnees = req.body.eval || {};
  for (const eleveId of autorises) {
    const d = donnees[eleveId];
    // Lignes non modifiées d'élèves pas encore évalués : on ne crée pas de lacunes
    if (!d || d.touche !== '1') continue;
    const indices = [].concat(d.m || []).map(Number);
    await enregistrerEvaluation(req.controle, eleveId, {
      maitrisees: indices.map(i => competences[i]).filter(Boolean),
      lacunesLibres: String(d.libres || '').split(/[\n;,]+/),
      note: d.note,
      commentaire: d.commentaire,
    });
  }
  await enregistrerSuivis(req.controle, req.body.suivi, autorises);
  flash(req, 'success', 'Évaluations enregistrées.');
  res.redirect(req.body.suite === 'dashboard' ? `/controles/${req.controle._id}/dashboard` : `/controles/${req.controle._id}/evaluation`);
});

// ---------- Tableau de bord ----------
router.get('/:id/dashboard', chargerControle, async (req, res) => {
  const d = await dashboardControle(req.controle);
  res.render('controles/dashboard', { titre: `Tableau de bord — ${req.controle.titre}`, d });
});

// ---------- Suppression ----------
router.post('/:id/supprimer', chargerControle, async (req, res) => {
  const c = req.controle;
  await Evaluation.deleteMany({ controle: c._id });
  // Les lacunes nées de ce contrôle disparaissent ; les suivis qui y font référence sont retirés
  await Lacune.deleteMany({ controleOrigine: c._id });
  const touchees = await Lacune.find({ 'suivis.controle': c._id });
  for (const l of touchees) {
    l.suivis = l.suivis.filter(s => String(s.controle) !== String(c._id));
    if (String(l.controleRemediation) === String(c._id)) {
      l.statut = 'ouverte'; l.controleRemediation = undefined; l.dateRemediation = undefined;
    }
    await l.save();
  }
  if (c.fichierPdf) fs.rm(path.join(config.uploadDir, path.basename(c.fichierPdf)), { force: true }, () => {});
  await c.deleteOne();
  flash(req, 'success', 'Contrôle supprimé.');
  res.redirect('/controles');
});

module.exports = router;
