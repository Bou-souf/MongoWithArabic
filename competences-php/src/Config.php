<?php
declare(strict_types=1);

namespace App;

final class Config
{
    private static ?array $config = null;

    public static function load(?array $override = null): array
    {
        if ($override !== null) {
            return self::$config = array_replace_recursive(self::defaults(), $override);
        }
        if (self::$config === null) {
            $fichier = getenv('COMPETENCES_CONFIG') ?: dirname(__DIR__) . '/config.php';
            $local = is_file($fichier) ? require $fichier : [];
            self::$config = array_replace_recursive(self::defaults(), $local);
        }
        return self::$config;
    }

    public static function get(string $cle, mixed $defaut = null): mixed
    {
        $c = self::load();
        foreach (explode('.', $cle) as $k) {
            if (!is_array($c) || !array_key_exists($k, $c)) {
                return $defaut;
            }
            $c = $c[$k];
        }
        return $c;
    }

    private static function defaults(): array
    {
        return [
            'db' => ['host' => '127.0.0.1', 'port' => 3306, 'name' => 'competences', 'user' => 'root', 'password' => ''],
            'upload_dir' => dirname(__DIR__) . '/storage/uploads',
            'seuil_reussite' => 10,
            'anthropic_api_key' => '',
            'claude_model' => 'claude-opus-5-5',
            'debug' => false,
        ];
    }
}
