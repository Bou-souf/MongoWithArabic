const express = require('express');
const mongoose = require('mongoose');
const { User, Classe, Controle } = require('../models');
const { flash } = require('../middleware/auth');

const router = express.Router();

router.get('/utilisateurs', async (req, res) => {
  const users = await User.find().sort({ role: 1, nom: 1 });
  const nbClasses = {};
  for (const c of await Classe.aggregate([{ $group: { _id: '$prof', n: { $sum: 1 } } }])) nbClasses[c._id] = c.n;
  res.render('admin/utilisateurs', { titre: 'Utilisateurs', users, nbClasses });
});

router.post('/utilisateurs', async (req, res) => {
  const { nom, email, password, role, matieres } = req.body;
  if (!nom || !email || !password || password.length < 6) {
    flash(req, 'danger', 'Nom, email et mot de passe (6 caractères min.) sont obligatoires.');
    return res.redirect('/admin/utilisateurs');
  }
  if (await User.exists({ email: String(email).toLowerCase().trim() })) {
    flash(req, 'danger', 'Un compte existe déjà avec cet email.');
    return res.redirect('/admin/utilisateurs');
  }
  await User.create({
    nom, email, role: role === 'admin' ? 'admin' : 'prof',
    passwordHash: await User.hasher(password),
    matieres: String(matieres || '').split(',').map(s => s.trim()).filter(Boolean),
  });
  flash(req, 'success', `Compte « ${nom} » créé.`);
  res.redirect('/admin/utilisateurs');
});

router.post('/utilisateurs/:id', async (req, res) => {
  if (!mongoose.isValidObjectId(req.params.id)) return res.redirect('/admin/utilisateurs');
  const u = await User.findById(req.params.id);
  if (!u) return res.redirect('/admin/utilisateurs');
  const soiMeme = String(u._id) === String(req.user._id);
  if (req.body.action === 'supprimer') {
    if (soiMeme) flash(req, 'danger', 'Vous ne pouvez pas supprimer votre propre compte.');
    else if (await Classe.exists({ prof: u._id })) flash(req, 'danger', 'Ce professeur a des classes : réaffectez-les ou désactivez le compte.');
    else { await u.deleteOne(); flash(req, 'success', 'Compte supprimé.'); }
    return res.redirect('/admin/utilisateurs');
  }
  u.nom = req.body.nom || u.nom;
  u.matieres = String(req.body.matieres || '').split(',').map(s => s.trim()).filter(Boolean);
  if (!soiMeme) {
    u.role = req.body.role === 'admin' ? 'admin' : 'prof';
    u.actif = req.body.actif === 'on';
  }
  if (req.body.password) {
    if (req.body.password.length < 6) { flash(req, 'danger', 'Mot de passe trop court.'); return res.redirect('/admin/utilisateurs'); }
    u.passwordHash = await User.hasher(req.body.password);
  }
  await u.save();
  flash(req, 'success', `Compte « ${u.nom} » mis à jour.`);
  res.redirect('/admin/utilisateurs');
});

// Réaffectation d'une classe à un autre professeur
router.post('/classes/:id/prof', async (req, res) => {
  const classe = mongoose.isValidObjectId(req.params.id) ? await Classe.findById(req.params.id) : null;
  const prof = mongoose.isValidObjectId(req.body.prof) ? await User.findById(req.body.prof) : null;
  if (classe && prof) {
    classe.prof = prof._id;
    await classe.save();
    await Controle.updateMany({ classe: classe._id }, { prof: prof._id });
    flash(req, 'success', `Classe ${classe.nom} affectée à ${prof.nom}.`);
  }
  res.redirect(`/classes/${req.params.id}`);
});

module.exports = router;
