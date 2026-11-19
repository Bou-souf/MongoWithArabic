require('dotenv').config({ quiet: true });

module.exports = {
  port: parseInt(process.env.PORT || '3000', 10),
  mongoUri: process.env.MONGODB_URI || 'mongodb://127.0.0.1:27017/competences',
  sessionSecret: process.env.SESSION_SECRET || 'change-moi-en-production',
  anthropicApiKey: process.env.ANTHROPIC_API_KEY || '',
  claudeModel: process.env.CLAUDE_MODEL || 'claude-opus-5-5',
  uploadDir: process.env.UPLOAD_DIR || require('path').join(__dirname, '..', 'uploads'),
  // Note à partir de laquelle un élève est considéré en réussite (sur 20)
  seuilReussite: parseFloat(process.env.SEUIL_REUSSITE || '10'),
};
