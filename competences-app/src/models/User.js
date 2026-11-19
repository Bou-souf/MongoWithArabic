const mongoose = require('mongoose');
const bcrypt = require('bcryptjs');

const userSchema = new mongoose.Schema({
  nom: { type: String, required: true, trim: true },
  email: { type: String, required: true, unique: true, lowercase: true, trim: true },
  passwordHash: { type: String, required: true },
  role: { type: String, enum: ['admin', 'prof'], default: 'prof' },
  matieres: [{ type: String, trim: true }],
  actif: { type: Boolean, default: true },
}, { timestamps: true });

userSchema.methods.verifierMotDePasse = function (mdp) {
  return bcrypt.compare(mdp, this.passwordHash);
};

userSchema.statics.hasher = function (mdp) {
  return bcrypt.hash(mdp, 10);
};

module.exports = mongoose.model('User', userSchema);
