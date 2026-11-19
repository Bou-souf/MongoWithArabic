const { test } = require('node:test');
const assert = require('node:assert');
const fs = require('fs');
const path = require('path');
const XLSX = require('xlsx');
const { competencesPour } = require('../src/services/competencesRules');
const { decouperQuestions, analyserParRegles, extraireTexte } = require('../src/services/pdfAnalyzer');
const { lireFichierNotes } = require('../src/services/excelImport');

test('détection des compétences à partir des verbes d\'action', () => {
  assert.deepStrictEqual(competencesPour('Résoudre l\'équation 2x + 3 = 7'), ['Résoudre une équation / un problème']);
  assert.ok(competencesPour('Montrer que ABC est rectangle').includes('Raisonner et démontrer'));
  assert.ok(competencesPour('Factoriser x² - 9').includes('Développer et factoriser une expression'));
  assert.ok(competencesPour('Rédigez un paragraphe argumentatif').includes('Production écrite / rédaction'));
  assert.ok(competencesPour('احسب العدد A').includes('Calculer et appliquer une formule'));
  assert.ok(competencesPour('أذكر خاصيتين').includes('Restituer des connaissances'));
  assert.deepStrictEqual(competencesPour('Bonne chance'), ['Mobiliser les connaissances du cours']);
});

test('découpage en exercices et questions avec barème', () => {
  const texte = 'Exercice 1 (5 pts)\n1) Calculer A. (2 pts)\n2) Développer B\n(3 pts)\nExercice 2\na) Tracer la courbe. (4 points)';
  const qs = decouperQuestions(texte);
  assert.deepStrictEqual(qs.map(q => q.numero), ['Ex1-1', 'Ex1-2', 'Ex2-a']);
  assert.deepStrictEqual(qs.map(q => q.bareme), [2, 3, 4]);
});

test('analyse du PDF d\'exemple', async () => {
  const texte = await extraireTexte(fs.readFileSync(path.join(__dirname, '..', 'exemples', 'controle-maths-1.pdf')));
  const qs = analyserParRegles(texte);
  assert.strictEqual(qs.length, 8);
  assert.ok(qs.every(q => q.competences.length > 0));
});

test('lecture Excel : en-têtes Massar, lignes de titre ignorées', () => {
  const r = lireFichierNotes(fs.readFileSync(path.join(__dirname, '..', 'exemples', 'notes-3APIC-2.xlsx')));
  assert.strictEqual(r.eleves.length, 8);
  assert.deepStrictEqual(r.eleves[0], { nom: 'Alaoui', prenom: 'Yasmine', code: 'J130000001', note: 15.5 });
});

test('lecture Excel : colonne « Nom et prénom » et notes avec virgule', () => {
  const wb = XLSX.utils.book_new();
  XLSX.utils.book_append_sheet(wb, XLSX.utils.aoa_to_sheet([['Élève', 'Note /20'], ['Dupont Marie Claire', '12,5'], ['Martin Léo', 8]]), 'S');
  const r = lireFichierNotes(XLSX.write(wb, { type: 'buffer', bookType: 'xlsx' }));
  assert.deepStrictEqual(r.eleves, [
    { nom: 'Dupont', prenom: 'Marie Claire', code: '', note: 12.5 },
    { nom: 'Martin', prenom: 'Léo', code: '', note: 8 },
  ]);
});
