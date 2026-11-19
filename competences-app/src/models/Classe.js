const mongoose = require('mongoose');

const classeSchema = new mongoose.Schema({
  nom: { type: String, required: true, trim: true },
  niveau: { type: String, trim: true },
  anneeScolaire: { type: String, trim: true },
  prof: { type: mongoose.Schema.Types.ObjectId, ref: 'User', required: true },
}, { timestamps: true });

classeSchema.index({ prof: 1, nom: 1, anneeScolaire: 1 }, { unique: true });

module.exports = mongoose.model('Classe', classeSchema);
