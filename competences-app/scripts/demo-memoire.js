// Lance l'application avec une base MongoDB temporaire en mémoire et des données
// de démonstration (aucune installation de MongoDB nécessaire).
//   npm run demo      puis ouvrir http://localhost:3000
// Toutes les données sont perdues à l'arrêt.
const fs = require('fs');
const os = require('os');
const path = require('path');

process.env.UPLOAD_DIR = process.env.UPLOAD_DIR || fs.mkdtempSync(path.join(os.tmpdir(), 'competences-demo-'));

(async () => {
  const { MongoMemoryServer } = require('mongodb-memory-server');
  const mongoose = require('mongoose');
  const mongod = await MongoMemoryServer.create();
  await mongoose.connect(mongod.getUri());
  const { seedDemo } = require('./seed');
  await seedDemo();
  const { creerApp } = require('../src/app');
  const config = require('../src/config');
  creerApp().listen(config.port, () => console.log(`Démo disponible sur http://localhost:${config.port}`));
})().catch((e) => { console.error(e); process.exit(1); });
