const fs = require('fs');
const mongoose = require('mongoose');
const config = require('./config');
const { creerApp } = require('./app');
const { User } = require('./models');

async function demarrer() {
  fs.mkdirSync(config.uploadDir, { recursive: true });
  await mongoose.connect(config.mongoUri);
  console.log(`MongoDB connecté : ${config.mongoUri.replace(/\/\/[^@]*@/, '//***@')}`);

  // Premier démarrage : création d'un compte administrateur
  if (!(await User.exists({ role: 'admin' }))) {
    const email = process.env.ADMIN_EMAIL || 'admin@ecole.local';
    const mdp = process.env.ADMIN_PASSWORD || 'admin123';
    await User.create({ nom: 'Administrateur', email, passwordHash: await User.hasher(mdp), role: 'admin' });
    console.log(`Compte administrateur créé : ${email} / ${mdp}  (changez ce mot de passe !)`);
  }

  const app = creerApp();
  app.listen(config.port, () => console.log(`Application disponible sur http://localhost:${config.port}`));
}

demarrer().catch((err) => {
  console.error('Démarrage impossible :', err.message);
  process.exit(1);
});
