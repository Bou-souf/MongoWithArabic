// Données de démonstration : comptes, une classe, deux contrôles évalués.
//   npm run seed            (utilise MONGODB_URI)
const fs = require('fs');
const path = require('path');
const mongoose = require('mongoose');
const config = require('../src/config');
const { User, Classe, Eleve, Controle, Evaluation } = require('../src/models');
const { analyserControle } = require('../src/services/pdfAnalyzer');
const { lireFichierNotes } = require('../src/services/excelImport');
const { enregistrerEvaluation, enregistrerSuivis, lacunesASuivre } = require('../src/services/lacunes');

const EX = path.join(__dirname, '..', 'exemples');

async function seedDemo() {
  const admin = await User.findOne({ email: 'admin@ecole.local' })
    || await User.create({ nom: 'Administrateur', email: 'admin@ecole.local', passwordHash: await User.hasher('admin123'), role: 'admin' });
  if (await User.exists({ email: 'prof@ecole.local' })) { console.log('Données de démonstration déjà présentes.'); return; }
  const prof = await User.create({ nom: 'Mme Benali', email: 'prof@ecole.local', passwordHash: await User.hasher('prof123'), role: 'prof', matieres: ['Mathématiques'] });
  const classe = await Classe.create({ nom: '3APIC-2', niveau: '3e année collège', anneeScolaire: '2026-2027', prof: prof._id });

  const { eleves: lignes } = lireFichierNotes(fs.readFileSync(path.join(EX, 'notes-3APIC-2.xlsx')));
  const eleves = [];
  for (const l of lignes) eleves.push(await Eleve.create({ ...l, classe: classe._id }));

  // Profils de maîtrise simulés (indices des compétences NON maîtrisées)
  const sujets = [
    { fichier: 'controle-maths-1.pdf', titre: 'Contrôle 1 — Calcul et équations', date: '2026-10-10' },
    { fichier: 'controle-maths-2.pdf', titre: 'Contrôle 2 — Équations et statistiques', date: '2026-11-12' },
  ];
  fs.mkdirSync(config.uploadDir, { recursive: true });
  for (const [n, s] of sujets.entries()) {
    const buffer = fs.readFileSync(path.join(EX, s.fichier));
    const a = await analyserControle(buffer, { matiere: 'Mathématiques', utiliserIA: false });
    const nomFichier = `demo-${n + 1}.pdf`;
    fs.writeFileSync(path.join(config.uploadDir, nomFichier), buffer);
    const c = await Controle.create({
      titre: s.titre, matiere: 'Mathématiques', classe: classe._id, prof: prof._id, date: new Date(s.date),
      fichierPdf: nomFichier, nomFichierOriginal: s.fichier, texte: a.texte, questions: a.questions, methodeAnalyse: a.methode,
    });
    const comps = c.competences;
    const suivis = await lacunesASuivre(c, eleves.map(e => e._id));
    const reponses = {};
    for (const [i, e] of eleves.entries()) {
      const note = n === 0 ? lignes[i].note : Math.min(20, Math.max(0, lignes[i].note + ((i % 3) - 1) * 1.5));
      // Plus la note est faible, plus il y a de compétences non maîtrisées (déterministe)
      const nbLacunes = Math.round((20 - note) / 20 * comps.length * 0.8);
      const non = new Set(comps.map((_, k) => k).sort((x, y) => ((x * 7 + i * 3) % comps.length) - ((y * 7 + i * 3) % comps.length)).slice(0, nbLacunes));
      await Evaluation.create({ controle: c._id, eleve: e._id, note });
      await enregistrerEvaluation(c, e._id, {
        maitrisees: comps.filter((_, k) => !non.has(k)),
        lacunesLibres: n === 0 && note < 10 ? ['Priorités opératoires'] : [],
      });
      for (const l of suivis.get(String(e._id)) || []) reponses[l._id] = (i % 2 === 0) ? 'oui' : 'non';
    }
    await enregistrerSuivis(c, reponses, eleves.map(e => e._id));
  }
  console.log('Démo créée : admin@ecole.local / admin123 — prof@ecole.local / prof123');
  return { admin, prof };
}

module.exports = { seedDemo };

if (require.main === module) {
  mongoose.connect(config.mongoUri)
    .then(seedDemo)
    .then(() => mongoose.disconnect())
    .catch((e) => { console.error(e); process.exit(1); });
}
