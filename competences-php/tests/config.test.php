<?php
// Base de test : variables d'environnement TEST_DB_* ou valeurs par défaut
return [
    'db' => [
        'host' => getenv('TEST_DB_HOST') ?: '127.0.0.1',
        'port' => (int) (getenv('TEST_DB_PORT') ?: 3306),
        'name' => getenv('TEST_DB_NAME') ?: 'competences_test',
        'user' => getenv('TEST_DB_USER') ?: 'comp',
        'password' => getenv('TEST_DB_PASSWORD') !== false ? getenv('TEST_DB_PASSWORD') : 'comp',
    ],
    'upload_dir' => sys_get_temp_dir() . '/competences-test-uploads',
    'anthropic_api_key' => '',
    'debug' => true,
];
