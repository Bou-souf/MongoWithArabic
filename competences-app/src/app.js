const path = require('path');
const express = require('express');
const session = require('express-session');
const { MongoStore } = require('connect-mongo');
const mongoose = require('mongoose');
const config = require('./config');
const { chargerUtilisateur, csrfGlobal, exigerConnexion, exigerRole } = require('./middleware/auth');

function creerApp({ sessionStore } = {}) {
  const app = express();
  app.set('view engine', 'ejs');
  app.set('views', path.join(__dirname, 'views'));
  app.disable('x-powered-by');

  app.use(express.urlencoded({ extended: true, parameterLimit: 20000, limit: '5mb' }));
  app.use(express.json());
  app.use('/public', express.static(path.join(__dirname, 'public')));
  // Bibliothèques front servies localement (fonctionne sans accès Internet)
  const nm = path.join(__dirname, '..', 'node_modules');
  app.use('/vendor/bootstrap', express.static(path.join(nm, 'bootstrap', 'dist')));
  app.use('/vendor/bootstrap-icons', express.static(path.join(nm, 'bootstrap-icons', 'font')));
  app.use('/vendor/chart.js', express.static(path.join(nm, 'chart.js', 'dist')));
  app.use(session({
    secret: config.sessionSecret,
    resave: false,
    saveUninitialized: false,
    store: sessionStore || MongoStore.create({ client: mongoose.connection.getClient() }),
    cookie: { httpOnly: true, sameSite: 'lax', maxAge: 1000 * 60 * 60 * 8 },
  }));
  app.use(chargerUtilisateur);
  app.use(csrfGlobal);

  // Helpers disponibles dans toutes les vues
  app.locals.fmtDate = (d) => (d ? new Date(d).toLocaleDateString('fr-FR') : '');
  app.locals.fmt = (n) => (n === null || n === undefined || Number.isNaN(n) ? '—' : String(Math.round(n * 100) / 100).replace('.', ','));
  app.locals.couleurPct = (p) => (p >= 75 ? 'success' : p >= 50 ? 'warning' : 'danger');

  app.use('/', require('./routes/auth'));
  app.use('/', exigerConnexion, require('./routes/accueil'));
  app.use('/admin', exigerConnexion, exigerRole('admin'), require('./routes/admin'));
  app.use('/classes', exigerConnexion, require('./routes/classes'));
  app.use('/controles', exigerConnexion, require('./routes/controles'));
  app.use('/eleves', exigerConnexion, require('./routes/eleves'));

  app.use((req, res) => res.status(404).render('erreur', { titre: 'Page introuvable', message: "La page demandée n'existe pas." }));
  // eslint-disable-next-line no-unused-vars
  app.use((err, req, res, next) => {
    console.error(err);
    const message = err.code === 'LIMIT_FILE_SIZE' ? 'Fichier trop volumineux.' : (err.expose ? err.message : 'Une erreur est survenue.');
    res.status(err.status || 500).render('erreur', { titre: 'Erreur', message });
  });
  return app;
}

module.exports = { creerApp };
