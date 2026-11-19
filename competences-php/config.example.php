<?php
// Copiez ce fichier en config.php et adaptez les valeurs.
// Sur OVH : les identifiants MySQL se trouvent dans l'espace client,
// rubrique Hébergements > votre hébergement > Bases de données.
return [
    'db' => [
        'host' => 'localhost',          // OVH : ex. monsite.mysql.db
        'port' => 3306,
        'name' => 'competences',
        'user' => 'root',
        'password' => '',
    ],
    // Dossier de stockage des PDF (hors du dossier public)
    'upload_dir' => __DIR__ . '/storage/uploads',
    // Note sur 20 à partir de laquelle un élève est en réussite
    'seuil_reussite' => 10,
    // Facultatif : active l'analyse avancée des PDF par l'IA Claude
    'anthropic_api_key' => '',
    'claude_model' => 'claude-opus-5-5',
    // Afficher le détail des erreurs (à laisser à false en production)
    'debug' => false,
];
