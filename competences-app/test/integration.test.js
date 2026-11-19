// Test de bout en bout : MongoDB en mémoire + requêtes HTTP réelles sur l'application.
const { test, before, after } = require('node:test');
const assert = require('node:assert');
const fs = require('fs');
const os = require('os');
const path = require('path');
const mongoose = require('mongoose');
const { MongoMemoryServer } = require('mongodb-memory-server');

process.env.UPLOAD_DIR = fs.mkdtempSync(path.join(os.tmpdir(), 'competences-test-'));
process.env.ANTHROPIC_API_KEY = ''; // analyse par règles uniquement

const { creerApp } = require('../src/app');
const { User, Lacune, Eleve, Controle, Evaluation } = require('../src/models');

const EX = path.join(__dirname, '..', 'exemples');
let mongod, serveur, base;

before(async () => {
  mongod = await MongoMemoryServer.create();
  await mongoose.connect(mongod.getUri());
  await User.create({ nom: 'Admin', email: 'admin@test.ma', passwordHash: await User.hasher('admin123'), role: 'admin' });
  await User.create({ nom: 'Prof Un', email: 'prof1@test.ma', passwordHash: await User.hasher('prof123'), role: 'prof' });
  await User.create({ nom: 'Prof Deux', email: 'prof2@test.ma', passwordHash: await User.hasher('prof123'), role: 'prof' });
  serveur = creerApp().listen(0);
  base = `http://127.0.0.1:${serveur.address().port}`;
});

after(async () => {
  serveur && serveur.close();
  await mongoose.disconnect();
  await mongod.stop();
  fs.rmSync(process.env.UPLOAD_DIR, { recursive: true, force: true });
});

// Petit client HTTP avec cookies et jeton CSRF
function client() {
  let cookie = '';
  let csrf = '';
  const req = async (methode, url, corps) => {
    const opts = { method: methode, redirect: 'manual', headers: { cookie } };
    if (corps instanceof FormData) opts.body = corps;
    else if (corps) {
      opts.body = new URLSearchParams(corps);
      opts.headers['content-type'] = 'application/x-www-form-urlencoded';
    }
    const res = await fetch(base + url, opts);
    const sc = res.headers.getSetCookie();
    if (sc.length) cookie = sc.map(c => c.split(';')[0]).join('; ');
    const texte = await res.text();
    const m = texte.match(/name="_csrf" value="([^"]+)"/);
    if (m) csrf = m[1];
    return { status: res.status, location: res.headers.get('location'), texte };
  };
  return {
    get: (u) => req('GET', u),
    post: (u, corps = {}) => {
      if (corps instanceof FormData) { corps.append('_csrf', csrf); return req('POST', u, corps); }
      return req('POST', u, { ...corps, _csrf: csrf });
    },
    async login(email, mdp) {
      await req('GET', '/login');
      const r = await this.post('/login', { email, password: mdp });
      assert.strictEqual(r.status, 302, 'connexion');
      await req('GET', '/');
    },
  };
}

function formControle(classeId, titre, pdf, avecExcel) {
  const fd = new FormData();
  fd.append('classe', classeId);
  fd.append('titre', titre);
  fd.append('matiere', 'Mathématiques');
  fd.append('date', titre.includes('2') ? '2026-11-15' : '2026-10-15');
  fd.append('noteSur', '20');
  fd.append('pdf', new Blob([fs.readFileSync(path.join(EX, pdf))], { type: 'application/pdf' }), pdf);
  if (avecExcel) fd.append('excel', new Blob([fs.readFileSync(path.join(EX, 'notes-3APIC-2.xlsx'))]), 'notes.xlsx');
  return fd;
}

test('parcours complet professeur', async () => {
  const anonyme = client();
  const r0 = await anonyme.get('/controles');
  assert.strictEqual(r0.status, 302);
  assert.strictEqual(r0.location, '/login');

  const prof = client();
  const mauvais = client();
  await mauvais.get('/login');
  assert.strictEqual((await mauvais.post('/login', { email: 'prof1@test.ma', password: 'faux' })).status, 401);

  await prof.login('prof1@test.ma', 'prof123');

  // POST sans jeton CSRF refusé
  const sansCsrf = await fetch(base + '/classes', { method: 'POST', body: new URLSearchParams({ nom: 'X' }), redirect: 'manual' });
  assert.notStrictEqual(sansCsrf.status, 200);

  // 1. Classe
  let r = await prof.post('/classes', { nom: '3APIC-2', niveau: '3e', anneeScolaire: '2026-2027' });
  assert.strictEqual(r.status, 302);
  const classeId = r.location.split('/').pop();

  // 2. Contrôle 1 : PDF + Excel des notes
  r = await prof.post('/controles', formControle(classeId, 'Contrôle 1', 'controle-maths-1.pdf', true));
  assert.strictEqual(r.status, 302, r.texte);
  assert.match(r.location, /\/controles\/[a-f0-9]+\/competences$/);
  const c1 = r.location.split('/')[2];
  r = await prof.get(`/controles/${c1}/competences`);
  assert.match(r.texte, /Résoudre une équation/);
  assert.strictEqual(await Eleve.countDocuments({ classe: classeId }), 8);

  const controle1 = await Controle.findById(c1);
  const comps = controle1.competences;
  const iResoudre = comps.indexOf('Résoudre une équation / un problème');
  assert.ok(iResoudre >= 0);

  // 3. Grille d'évaluation : tous maîtrisent tout sauf Bennani (équations) ; Ghazi a une lacune libre
  r = await prof.get(`/controles/${c1}/evaluation`);
  assert.strictEqual(r.status, 200);
  const eleves = await Eleve.find({ classe: classeId });
  const id = (nom) => String(eleves.find(e => e.nom === nom)._id);
  const corps = {};
  for (const e of eleves) {
    const k = `eval[${e._id}]`;
    corps[`${k}[touche]`] = e.nom === 'Kettani' ? '0' : '1'; // Kettani : pas encore évalué
    comps.forEach((c, i) => {
      if (!(e.nom === 'Bennani' && i === iResoudre)) corps[`${k}[m][${i}]`] = String(i);
    });
    corps[`${k}[libres]`] = e.nom === 'Ghazi' ? 'Tables de multiplication' : '';
  }
  r = await prof.post(`/controles/${c1}/evaluation`, { ...corps, suite: 'dashboard' });
  assert.strictEqual(r.status, 302);
  assert.strictEqual(await Evaluation.countDocuments({ controle: c1, evaluee: true }), 7);
  assert.strictEqual(await Lacune.countDocuments({ eleve: id('Bennani'), statut: 'ouverte' }), 1);
  assert.strictEqual(await Lacune.countDocuments({ eleve: id('Ghazi'), origine: 'professeur' }), 1);
  assert.strictEqual(await Lacune.countDocuments({ eleve: id('Kettani') }), 0);

  // 4. Tableau de bord : groupes de besoin
  r = await prof.get(`/controles/${c1}/dashboard`);
  assert.strictEqual(r.status, 200);
  assert.match(r.texte, /Groupes de besoin/);
  assert.match(r.texte, /Bennani/);
  assert.match(r.texte, /Tables de multiplication/);

  // 5. Contrôle 2 : les lacunes antérieures sont proposées au suivi
  r = await prof.post('/controles', formControle(classeId, 'Contrôle 2', 'controle-maths-2.pdf', true));
  const c2 = r.location.split('/')[2];
  r = await prof.get(`/controles/${c2}/evaluation`);
  assert.match(r.texte, /Lacunes antérieures/);
  const lacB = await Lacune.findOne({ eleve: id('Bennani') });
  const lacG = await Lacune.findOne({ eleve: id('Ghazi') });
  const controle2 = await Controle.findById(c2);
  const corps2 = {};
  for (const e of eleves) {
    corps2[`eval[${e._id}][touche]`] = '1';
    controle2.competences.forEach((c, i) => { corps2[`eval[${e._id}][m][${i}]`] = String(i); });
  }
  corps2[`suivi[${lacB._id}]`] = 'non'; // Bennani : toujours pas remédiée -> alerte
  corps2[`suivi[${lacG._id}]`] = 'oui'; // Ghazi : remédiée
  r = await prof.post(`/controles/${c2}/evaluation`, corps2);
  assert.strictEqual(r.status, 302);
  assert.strictEqual((await Lacune.findById(lacB._id)).niveauAlerte, 1);
  const g = await Lacune.findById(lacG._id);
  assert.strictEqual(g.statut, 'remediee');
  assert.strictEqual(String(g.controleRemediation), c2);

  // 6. Alertes, statistiques, fiche élève
  r = await prof.get('/alertes');
  assert.match(r.texte, /Bennani/);
  assert.doesNotMatch(r.texte, /Ghazi/);
  r = await prof.get(`/statistiques?classe=${classeId}&matiere=Math%C3%A9matiques`);
  assert.strictEqual(r.status, 200);
  assert.match(r.texte, /Contrôle 2/);
  r = await prof.get(`/eleves/${id('Ghazi')}`);
  assert.match(r.texte, /Remédiée le/);
  r = await prof.get('/');
  assert.strictEqual(r.status, 200);

  // 7. Cloisonnement : un autre professeur ne voit pas ces données
  const prof2 = client();
  await prof2.login('prof2@test.ma', 'prof123');
  assert.strictEqual((await prof2.get(`/controles/${c1}/dashboard`)).status, 404);
  assert.strictEqual((await prof2.get(`/eleves/${id('Bennani')}`)).status, 404);
  assert.strictEqual((await prof2.get(`/classes/${classeId}`)).status, 404);
  assert.doesNotMatch((await prof2.get('/alertes')).texte, /Bennani/);
  assert.strictEqual((await prof2.get('/admin/utilisateurs')).status, 403);

  // 8. Administrateur : accès global + gestion des comptes
  const admin = client();
  await admin.login('admin@test.ma', 'admin123');
  assert.strictEqual((await admin.get(`/controles/${c1}/dashboard`)).status, 200);
  r = await admin.post('/admin/utilisateurs', { nom: 'Prof Trois', email: 'prof3@test.ma', password: 'secret1', role: 'prof' });
  assert.strictEqual(r.status, 302);
  assert.ok(await User.exists({ email: 'prof3@test.ma', role: 'prof' }));

  // 9. Suppression du contrôle 2 : la remédiation de Ghazi est annulée
  r = await prof.post(`/controles/${c2}/supprimer`);
  assert.strictEqual(r.status, 302);
  assert.strictEqual((await Lacune.findById(lacG._id)).statut, 'ouverte');
  assert.strictEqual((await Lacune.findById(lacB._id)).niveauAlerte, 0);
});
