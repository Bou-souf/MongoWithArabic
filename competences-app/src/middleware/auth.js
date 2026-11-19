const crypto = require('crypto');
const { User, Classe } = require('../models');

// Charge l'utilisateur connecté et expose des variables communes aux vues
async function chargerUtilisateur(req, res, next) {
  res.locals.user = null;
  if (req.session.userId) {
    const user = await User.findById(req.session.userId);
    if (user && user.actif) {
      req.user = user;
      res.locals.user = user;
    } else {
      delete req.session.userId;
    }
  }
  if (!req.session.csrf) req.session.csrf = crypto.randomBytes(24).toString('hex');
  res.locals.csrf = req.session.csrf;
  res.locals.flash = req.session.flash || [];
  req.session.flash = [];
  res.locals.path = req.path;
  next();
}

function flash(req, type, message) {
  req.session.flash = req.session.flash || [];
  req.session.flash.push({ type, message });
}

function exigerConnexion(req, res, next) {
  if (!req.user) {
    if (req.method === 'GET') req.session.retour = req.originalUrl;
    return res.redirect('/login');
  }
  next();
}

function exigerRole(...roles) {
  return (req, res, next) => {
    if (!req.user || !roles.includes(req.user.role)) {
      return res.status(403).render('erreur', { titre: 'Accès refusé', message: "Vous n'avez pas les droits nécessaires pour accéder à cette page." });
    }
    next();
  };
}

// Vérification du jeton CSRF (formulaires POST)
function verifierCsrf(req, res, next) {
  const jeton = (req.body && req.body._csrf) || req.get('x-csrf-token');
  if (!jeton || jeton !== req.session.csrf) {
    return res.status(403).render('erreur', { titre: 'Session expirée', message: 'Le formulaire a expiré. Rechargez la page et réessayez.' });
  }
  next();
}

// Contrôle global : on ignore les formulaires multipart, vérifiés après multer dans leurs routes
function csrfGlobal(req, res, next) {
  if (req.method !== 'POST') return next();
  if ((req.get('content-type') || '').startsWith('multipart/form-data')) return next();
  return verifierCsrf(req, res, next);
}

// Filtre MongoDB limitant l'accès d'un professeur à ses propres données
function filtreProf(req) {
  return req.user.role === 'admin' ? {} : { prof: req.user._id };
}

// Retourne la classe si l'utilisateur y a accès, sinon null
async function classeAccessible(req, classeId) {
  if (!require('mongoose').isValidObjectId(classeId)) return null;
  return Classe.findOne({ _id: classeId, ...filtreProf(req) });
}

module.exports = { chargerUtilisateur, flash, exigerConnexion, exigerRole, verifierCsrf, csrfGlobal, filtreProf, classeAccessible };
