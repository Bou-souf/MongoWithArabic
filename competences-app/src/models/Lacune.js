const mongoose = require('mongoose');

const suiviSchema = new mongoose.Schema({
  controle: { type: mongoose.Schema.Types.ObjectId, ref: 'Controle' },
  date: { type: Date, default: Date.now },
  remediee: { type: Boolean, required: true },
  commentaire: { type: String, default: '' },
}, { _id: false });

const lacuneSchema = new mongoose.Schema({
  eleve: { type: mongoose.Schema.Types.ObjectId, ref: 'Eleve', required: true, index: true },
  matiere: { type: String, required: true, trim: true },
  competence: { type: String, required: true, trim: true },
  origine: { type: String, enum: ['systeme', 'professeur'], default: 'systeme' },
  controleOrigine: { type: mongoose.Schema.Types.ObjectId, ref: 'Controle' },
  statut: { type: String, enum: ['ouverte', 'remediee'], default: 'ouverte', index: true },
  suivis: [suiviSchema],
  dateRemediation: { type: Date },
  controleRemediation: { type: mongoose.Schema.Types.ObjectId, ref: 'Controle' },
}, { timestamps: true });

lacuneSchema.index({ eleve: 1, matiere: 1, competence: 1, statut: 1 });

// Nombre de suivis où le professeur a confirmé que la lacune n'est PAS remédiée
lacuneSchema.virtual('echecs').get(function () {
  return (this.suivis || []).filter(s => !s.remediee).length;
});

// Niveau d'alerte : 0 = aucune, 1 = à surveiller, 2 = urgente
lacuneSchema.virtual('niveauAlerte').get(function () {
  if (this.statut !== 'ouverte') return 0;
  const e = this.echecs;
  if (e >= 2) return 2;
  if (e === 1) return 1;
  return 0;
});

lacuneSchema.set('toJSON', { virtuals: true });
lacuneSchema.set('toObject', { virtuals: true });

module.exports = mongoose.model('Lacune', lacuneSchema);
