#!/usr/bin/env bash
# Construit build/competences-php.zip prêt à être envoyé par FTP sur l'hébergement
# (dépendances de production incluses, sans tests ni fichiers de développement).
set -euo pipefail
cd "$(dirname "$0")/.."
rm -rf build && mkdir -p build/competences
cp -r public src templates sql storage bin exemples composer.json composer.lock .htaccess config.example.php ovhconfig.example README.md build/competences/
rm -rf build/competences/storage/uploads/* build/competences/bin/package.sh
touch build/competences/storage/uploads/.gitkeep
(cd build/competences && composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction --no-progress --quiet)
find build/competences/vendor -name .git -type d -prune -exec rm -rf {} +
# Dossiers inutiles à l'exécution (tests, exemples, documentation des bibliothèques)
find build/competences/vendor -mindepth 3 -maxdepth 3 -type d \( -name tests -o -name Tests -o -name test -o -name docs -o -name doc -o -name samples -o -name examples \) -prune -exec rm -rf {} +
(cd build && zip -qr competences-php.zip competences)
echo "Archive prête : build/competences-php.zip ($(du -h build/competences-php.zip | cut -f1))"
