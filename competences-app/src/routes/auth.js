const express = require('express');
const { User } = require('../models');
const { flash, exigerConnexion } = require('../middleware/auth');

const router = express.Router();

router.get('/login', (req, res) => {
  if (req.user) return res.redirect('/');
  res.render('login', { titre: 'Connexion', email: '' });
});

router.post('/login', async (req, res) => {
  const email = String(req.body.email || '').toLowerCase().trim();
  const user = await User.findOne({ email });
  if (!user || !user.actif || !(await user.verifierMotDePasse(String(req.body.password || '')))) {
    res.locals.flash = [{ type: 'danger', message: 'Email ou mot de passe incorrect.' }];
    return res.status(401).render('login', { titre: 'Connexion', email });
  }
  const retour = req.session.retour;
  req.session.regenerate((err) => {
    if (err) throw err;
    req.session.userId = String(user._id);
    res.redirect(retour && retour.startsWith('/') && !retour.startsWith('//') ? retour : '/');
  });
});

router.post('/logout', (req, res) => {
  req.session.destroy(() => res.redirect('/login'));
});

router.get('/profil', exigerConnexion, (req, res) => res.render('profil', { titre: 'Mon profil' }));

router.post('/profil', exigerConnexion, async (req, res) => {
  const { actuel, nouveau, confirmation } = req.body;
  if (!(await req.user.verifierMotDePasse(String(actuel || '')))) flash(req, 'danger', 'Mot de passe actuel incorrect.');
  else if (!nouveau || nouveau.length < 6) flash(req, 'danger', 'Le nouveau mot de passe doit contenir au moins 6 caractères.');
  else if (nouveau !== confirmation) flash(req, 'danger', 'La confirmation ne correspond pas.');
  else {
    req.user.passwordHash = await User.hasher(nouveau);
    await req.user.save();
    flash(req, 'success', 'Mot de passe modifié.');
  }
  res.redirect('/profil');
});

module.exports = router;
