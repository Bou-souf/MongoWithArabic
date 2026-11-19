const express = require('express');
const mongoose = require('mongoose');
const { Eleve, Lacune } = require('../models');
const { flash, classeAccessible } = require('../middleware/auth');
const { evolutionEleve } = require('../services/stats');

const router = express.Router();

async function chargerEleve(req, res, next) {
  const eleve = mongoose.isValidObjectId(req.params.id) ? await Eleve.findById(req.params.id) : null;
  const classe = eleve && await classeAccessible(req, eleve.classe);
  if (!classe) return res.status(404).render('erreur', { titre: 'Élève introuvable', message: '' });
  req.eleve = eleve;
  req.classe = classe;
  next();
}

router.get('/:id', chargerEleve, async (req, res) => {
  const evo = await evolutionEleve(req.eleve._id);
  const matieres = [...new Set(evo.lignes.map(l => l.controle.matiere))];
  res.render('eleve', { titre: req.eleve.nomComplet, eleve: req.eleve, classe: req.classe, evo, matieres });
});

router.post('/:id', chargerEleve, async (req, res) => {
  const { nom, prenom, code } = req.body;
  if (nom) req.eleve.nom = nom;
  req.eleve.prenom = prenom || '';
  req.eleve.code = code || undefined;
  await req.eleve.save();
  flash(req, 'success', 'Élève mis à jour.');
  res.redirect(`/eleves/${req.eleve._id}`);
});

// Mise à jour manuelle d'une lacune depuis la fiche élève (remédiée / rouverte)
router.post('/:id/lacunes/:lacuneId', chargerEleve, async (req, res) => {
  const l = mongoose.isValidObjectId(req.params.lacuneId) ? await Lacune.findOne({ _id: req.params.lacuneId, eleve: req.eleve._id }) : null;
  if (l) {
    if (req.body.action === 'remediee') {
      l.statut = 'remediee';
      l.dateRemediation = new Date();
      l.suivis.push({ date: new Date(), remediee: true, commentaire: req.body.commentaire || 'Remédiation confirmée manuellement' });
    } else if (req.body.action === 'non_remediee') {
      l.suivis.push({ date: new Date(), remediee: false, commentaire: req.body.commentaire || 'Lacune toujours présente' });
    } else if (req.body.action === 'rouvrir') {
      l.statut = 'ouverte';
      l.dateRemediation = undefined;
      l.controleRemediation = undefined;
    } else if (req.body.action === 'supprimer') {
      await l.deleteOne();
      flash(req, 'success', 'Lacune supprimée.');
      return res.redirect(`/eleves/${req.eleve._id}`);
    }
    await l.save();
    flash(req, 'success', 'Lacune mise à jour.');
  }
  res.redirect(req.body.retour === 'alertes' ? '/alertes' : `/eleves/${req.eleve._id}`);
});

module.exports = router;
