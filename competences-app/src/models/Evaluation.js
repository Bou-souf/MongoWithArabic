const mongoose = require('mongoose');

const evaluationSchema = new mongoose.Schema({
  controle: { type: mongoose.Schema.Types.ObjectId, ref: 'Controle', required: true, index: true },
  eleve: { type: mongoose.Schema.Types.ObjectId, ref: 'Eleve', required: true, index: true },
  note: { type: Number },
  maitrisees: [{ type: String }],
  nonMaitrisees: [{ type: String }],
  // Lacunes saisies par le professeur, non proposées par le système
  lacunesLibres: [{ type: String }],
  commentaire: { type: String, default: '' },
  evaluee: { type: Boolean, default: false },
}, { timestamps: true });

evaluationSchema.index({ controle: 1, eleve: 1 }, { unique: true });

module.exports = mongoose.model('Evaluation', evaluationSchema);
