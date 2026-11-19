const mongoose = require('mongoose');

const questionSchema = new mongoose.Schema({
  numero: { type: String, trim: true },
  enonce: { type: String, default: '' },
  bareme: { type: Number },
  competences: [{ type: String, trim: true }],
}, { _id: true });

const controleSchema = new mongoose.Schema({
  titre: { type: String, required: true, trim: true },
  matiere: { type: String, required: true, trim: true },
  classe: { type: mongoose.Schema.Types.ObjectId, ref: 'Classe', required: true, index: true },
  prof: { type: mongoose.Schema.Types.ObjectId, ref: 'User', required: true, index: true },
  date: { type: Date, default: Date.now },
  noteSur: { type: Number, default: 20 },
  fichierPdf: { type: String },
  nomFichierOriginal: { type: String },
  texte: { type: String, default: '' },
  questions: [questionSchema],
  methodeAnalyse: { type: String, enum: ['regles', 'ia', 'manuel'], default: 'regles' },
}, { timestamps: true });

// Liste unique des compétences du contrôle (ordre d'apparition)
controleSchema.virtual('competences').get(function () {
  const vues = new Set();
  const out = [];
  for (const q of this.questions || []) {
    for (const c of q.competences || []) {
      const k = c.toLowerCase();
      if (!vues.has(k)) { vues.add(k); out.push(c); }
    }
  }
  return out;
});

module.exports = mongoose.model('Controle', controleSchema);
