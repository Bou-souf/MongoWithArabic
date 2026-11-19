// Lecture d'un fichier Excel (ou CSV) de notes.
// Colonnes reconnues (insensible à la casse / aux accents) :
//   - Nom            : "nom", "nom de famille", "last name", "الاسم العائلي"
//   - Prénom         : "prenom", "first name", "الاسم الشخصي"
//   - Nom complet    : "nom et prenom", "nom complet", "eleve", "élève", "name"
//   - Code / Massar  : "code", "massar", "matricule", "id", "n°"
//   - Note           : "note", "notes", "moyenne", "resultat", "score", "النقطة"
// La première ligne contenant au moins une colonne "nom" est prise comme en-tête
// (les fichiers Massar contiennent souvent plusieurs lignes de titre).

const XLSX = require('xlsx');

function norm(s) {
  return String(s ?? '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().trim();
}

const COLS = {
  complet: [/^nom (et|&) prenoms?$/, /^nom complet$/, /^eleves?$/, /^nom\s*\/\s*prenom$/, /^name$/, /^full ?name$/, /^student$/, /^الاسم الكامل$/],
  nom: [/^nom$/, /^nom de famille$/, /^last ?name$/, /^surname$/, /^النسب$/, /^الاسم العائلي$/],
  prenom: [/^prenoms?$/, /^first ?name$/, /^الاسم$/, /^الاسم الشخصي$/],
  code: [/^code/, /massar/, /^matricule$/, /^id$/, /^n°$/, /^numero$/, /^رقم/],
  note: [/^notes?\b/, /^moyenne/, /^resultat/, /^score/, /^mark/, /^grade/, /^النقطة/, /^نقطة/, /^المعدل/],
};

function trouverColonnes(entete) {
  const idx = {};
  entete.forEach((cell, i) => {
    const c = norm(cell);
    if (!c) return;
    for (const [cle, motifs] of Object.entries(COLS)) {
      if (idx[cle] === undefined && motifs.some(m => m.test(c))) { idx[cle] = i; break; }
    }
  });
  return idx;
}

function parseNote(v) {
  if (v === null || v === undefined || v === '') return undefined;
  if (typeof v === 'number') return v;
  const n = parseFloat(String(v).replace(',', '.').replace(/[^0-9.\-]/g, ''));
  return Number.isFinite(n) ? n : undefined;
}

/**
 * @returns {{eleves: Array<{nom, prenom, code, note}>, colonnes: object, avertissements: string[]}}
 */
function lireFichierNotes(buffer) {
  const wb = XLSX.read(buffer, { type: 'buffer' });
  const feuille = wb.Sheets[wb.SheetNames[0]];
  const lignes = XLSX.utils.sheet_to_json(feuille, { header: 1, defval: '', raw: true });
  const avertissements = [];

  let iEntete = -1;
  let colonnes = {};
  for (let i = 0; i < Math.min(lignes.length, 30); i++) {
    const c = trouverColonnes(lignes[i]);
    if (c.nom !== undefined || c.complet !== undefined) { iEntete = i; colonnes = c; break; }
  }
  if (iEntete === -1) {
    // Pas d'en-tête reconnu : colonne A = nom complet, dernière colonne numérique = note
    avertissements.push("Aucun en-tête reconnu : la 1re colonne est utilisée comme nom de l'élève et la dernière colonne numérique comme note.");
    colonnes = { complet: 0 };
    iEntete = -1;
  }

  const eleves = [];
  for (let i = iEntete + 1; i < lignes.length; i++) {
    const l = lignes[i];
    let nom = '';
    let prenom = '';
    if (colonnes.nom !== undefined) {
      nom = String(l[colonnes.nom] ?? '').trim();
      prenom = colonnes.prenom !== undefined ? String(l[colonnes.prenom] ?? '').trim() : '';
    } else if (colonnes.complet !== undefined) {
      const complet = String(l[colonnes.complet] ?? '').trim().replace(/\s+/g, ' ');
      const parts = complet.split(' ');
      nom = parts.shift() || '';
      prenom = parts.join(' ');
    }
    if (!nom || /^(total|moyenne|nb|nombre)/i.test(norm(nom))) continue;

    let note;
    if (colonnes.note !== undefined) note = parseNote(l[colonnes.note]);
    else {
      for (let j = l.length - 1; j >= 0; j--) {
        if (j === colonnes.complet || j === colonnes.nom || j === colonnes.prenom || j === colonnes.code) continue;
        const n = parseNote(l[j]);
        if (n !== undefined && typeof l[j] === 'number') { note = n; break; }
      }
    }
    const code = colonnes.code !== undefined ? String(l[colonnes.code] ?? '').trim() : '';
    eleves.push({ nom, prenom, code, note });
  }
  if (colonnes.note === undefined && iEntete !== -1) avertissements.push('Colonne « Note » non trouvée : notes déduites de la dernière colonne numérique.');
  return { eleves, colonnes, avertissements };
}

module.exports = { lireFichierNotes, trouverColonnes };
