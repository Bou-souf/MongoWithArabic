// Génère un fichier Excel de notes d'exemple : node scripts/generer-exemple-excel.js
const path = require('path');
const XLSX = require('xlsx');

const eleves = [
  ['Alaoui', 'Yasmine', 'J130000001', 15.5], ['Bennani', 'Omar', 'J130000002', 9],
  ['Chraibi', 'Salma', 'J130000003', 12.25], ['El Idrissi', 'Mehdi', 'J130000004', 6.5],
  ['Fassi', 'Imane', 'J130000005', 17], ['Ghazi', 'Youssef', 'J130000006', 11],
  ['Haddad', 'Nora', 'J130000007', 8], ['Kettani', 'Adam', 'J130000008', 13.75],
];
const lignes = [
  ['Lycée Exemple — Relevé de notes'],
  ['Classe : 3APIC-2', '', '', ''],
  ['Code Massar', 'Nom', 'Prénom', 'Note'],
  ...eleves.map(([n, p, c, note]) => [c, n, p, note]),
];
const wb = XLSX.utils.book_new();
XLSX.utils.book_append_sheet(wb, XLSX.utils.aoa_to_sheet(lignes), 'Notes');
const out = path.join(__dirname, '..', 'exemples', 'notes-3APIC-2.xlsx');
XLSX.writeFile(wb, out);
console.log('créé :', out);
