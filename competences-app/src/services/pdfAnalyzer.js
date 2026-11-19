// Analyse d'un contrôle PDF : extraction du texte, découpage en questions et
// détection des compétences évaluées par chaque question.
//
// Deux modes :
//  - "regles" (par défaut, hors-ligne) : découpage par expressions régulières +
//    référentiel de verbes d'action (services/competencesRules.js) ;
//  - "ia" (si ANTHROPIC_API_KEY est défini) : le PDF est envoyé à Claude qui
//    renvoie les questions et compétences en JSON structuré. En cas d'erreur,
//    on revient automatiquement au mode "regles".

const { PDFParse } = require('pdf-parse');
const config = require('../config');
const { competencesPour, normaliser } = require('./competencesRules');

async function extraireTexte(buffer) {
  // pdf.js peut transférer (détacher) le buffer : on lui passe une copie
  const parser = new PDFParse({ data: new Uint8Array(buffer) });
  try {
    const res = await parser.getText();
    return (res.text || '')
      .replace(/\r/g, '')
      .replace(/^-- \d+ of \d+ --$/gm, ''); // séparateurs de pages ajoutés par pdf-parse
  } finally {
    await parser.destroy();
  }
}

// Motifs reconnus comme début de question / d'exercice
const DEBUT_EXERCICE = /^\s*(exercice|exercise|ex\.?|partie|probleme|situation|التمرين|تمرين)\s*([0-9ivxlc]+|[a-z])?\b/i;
const DEBUT_QUESTION = /^\s*(?:(question|q)\s*([0-9]+(?:[.\-][0-9a-z]+)*)|([0-9]{1,2}(?:[.\-][0-9]{1,2})*)\s*[).\-–:]|([a-h])\s*[)\-])\s*(.*)$/i;
const BAREME = /\(?\s*([0-9]+(?:[.,][0-9]+)?)\s*(?:pts?|points?|pt|ن|نقط|نقطة)\s*\)?/i;

function extraireBareme(texte) {
  const m = texte.match(BAREME);
  return m ? parseFloat(m[1].replace(',', '.')) : undefined;
}

// Découpe le texte en questions. Retourne [{numero, enonce, bareme}]
function decouperQuestions(texte) {
  const lignes = texte.split('\n').map(l => l.trim()).filter(Boolean);
  const questions = [];
  let exercice = null;
  let courante = null;
  let contexteExercice = '';

  const pousser = () => {
    if (courante && courante.enonce.trim()) questions.push(courante);
    courante = null;
  };

  for (const ligne of lignes) {
    const sansAccent = normaliser(ligne);
    const mEx = sansAccent.match(DEBUT_EXERCICE);
    if (mEx && ligne.length < 120) {
      pousser();
      exercice = (mEx[2] || String((exercice ? parseInt(exercice, 10) || 0 : 0) + 1)).toUpperCase();
      contexteExercice = ligne;
      continue;
    }
    const mQ = ligne.match(DEBUT_QUESTION);
    if (mQ) {
      pousser();
      const num = mQ[2] || mQ[3] || mQ[4];
      courante = {
        numero: exercice ? `Ex${exercice}-${num}` : String(num),
        enonce: ligne,
        bareme: extraireBareme(ligne),
        contexte: contexteExercice,
      };
      continue;
    }
    if (courante) {
      courante.enonce += ' ' + ligne;
      if (courante.bareme === undefined) courante.bareme = extraireBareme(ligne);
    } else if (exercice) {
      contexteExercice += ' ' + ligne;
    }
  }
  pousser();

  // Aucun découpage possible : on découpe par phrases interrogatives / impératives
  if (questions.length === 0) {
    const phrases = texte.split(/(?<=[?.!])\s+/).map(s => s.trim()).filter(s => s.length > 15);
    phrases.forEach((p, i) => questions.push({ numero: String(i + 1), enonce: p, bareme: extraireBareme(p) }));
  }
  return questions;
}

function analyserParRegles(texte) {
  return decouperQuestions(texte).map(q => ({
    numero: q.numero,
    enonce: q.enonce.slice(0, 2000),
    bareme: q.bareme,
    // L'énoncé de la question prime ; le contexte de l'exercice aide si la question est courte
    competences: (() => {
      const c = competencesPour(q.enonce);
      if (c.length === 1 && c[0].startsWith('Mobiliser') && q.contexte) return competencesPour(q.contexte);
      return c;
    })(),
  }));
}

const SCHEMA_IA = {
  type: 'object',
  additionalProperties: false,
  required: ['questions'],
  properties: {
    questions: {
      type: 'array',
      items: {
        type: 'object',
        additionalProperties: false,
        required: ['numero', 'enonce', 'bareme', 'competences'],
        properties: {
          numero: { type: 'string' },
          enonce: { type: 'string' },
          bareme: { type: ['number', 'null'] },
          competences: { type: 'array', items: { type: 'string' } },
        },
      },
    },
  },
};

async function analyserParIA(buffer, matiere) {
  const Anthropic = require('@anthropic-ai/sdk');
  const client = new Anthropic({ apiKey: config.anthropicApiKey });
  const response = await client.beta.messages.create({
    model: config.claudeModel,
    max_tokens: 16000,
    betas: ['server-side-fallback-2026-07-01'],
    fallbacks: 'default',
    output_config: {
      effort: 'medium',
      format: { type: 'json_schema', schema: SCHEMA_IA },
    },
    messages: [{
      role: 'user',
      content: [
        { type: 'document', source: { type: 'base64', media_type: 'application/pdf', data: buffer.toString('base64') } },
        {
          type: 'text',
          text: `Ce document est un contrôle (devoir surveillé) de la matière « ${matiere} ». `
            + 'Découpe-le en questions (numérote-les comme dans le document, ex. "Ex1-2" pour la question 2 de l\'exercice 1). '
            + 'Pour chaque question, donne l\'énoncé (résumé si très long), le barème si indiqué (sinon null), '
            + 'et 1 à 3 compétences précises évaluées, formulées en français comme dans un référentiel scolaire '
            + '(ex. "Résoudre une équation du premier degré", "Calculer une moyenne", "Analyser un document historique"). '
            + 'Réutilise exactement le même libellé quand une compétence revient dans plusieurs questions.',
        },
      ],
    }],
  });
  if (response.stop_reason === 'refusal' || response.stop_reason === 'max_tokens') {
    throw new Error(`Analyse IA interrompue (${response.stop_reason})`);
  }
  const texte = response.content.filter(b => b.type === 'text').map(b => b.text).join('');
  const json = JSON.parse(texte);
  return json.questions.map(q => ({
    numero: q.numero,
    enonce: q.enonce,
    bareme: q.bareme ?? undefined,
    competences: q.competences.filter(Boolean),
  }));
}

async function analyserControle(buffer, { matiere, utiliserIA = true } = {}) {
  const texte = await extraireTexte(buffer);
  if (utiliserIA && config.anthropicApiKey) {
    try {
      const questions = await analyserParIA(buffer, matiere);
      if (questions.length) return { texte, questions, methode: 'ia' };
    } catch (err) {
      console.warn('[analyse IA] échec, retour au mode règles :', err.message);
    }
  }
  return { texte, questions: analyserParRegles(texte), methode: 'regles' };
}

module.exports = { analyserControle, analyserParRegles, decouperQuestions, extraireTexte };
