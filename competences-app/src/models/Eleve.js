const mongoose = require('mongoose');

const eleveSchema = new mongoose.Schema({
  nom: { type: String, required: true, trim: true },
  prenom: { type: String, trim: true, default: '' },
  code: { type: String, trim: true }, // code Massar / matricule (optionnel)
  classe: { type: mongoose.Schema.Types.ObjectId, ref: 'Classe', required: true, index: true },
  cle: { type: String, index: true }, // clé normalisée nom+prénom pour le rapprochement lors des imports
}, { timestamps: true });

eleveSchema.virtual('nomComplet').get(function () {
  return `${this.nom} ${this.prenom || ''}`.trim();
});

eleveSchema.statics.cleDe = function (nom, prenom) {
  return `${nom || ''} ${prenom || ''}`
    .normalize('NFD').replace(/[̀-ͯ]/g, '')
    .toLowerCase().replace(/\s+/g, ' ').trim();
};

eleveSchema.pre('save', function () {
  this.cle = this.constructor.cleDe(this.nom, this.prenom);
});

module.exports = mongoose.model('Eleve', eleveSchema);
