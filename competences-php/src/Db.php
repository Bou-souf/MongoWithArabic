<?php
declare(strict_types=1);

namespace App;

use PDO;

/** Accès PDO minimaliste */
final class Db
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $c = Config::get('db');
            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $c['host'], (int) $c['port'], $c['name']);
            self::$pdo = new PDO($dsn, $c['user'], $c['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        }
        return self::$pdo;
    }

    public static function reset(): void
    {
        self::$pdo = null;
    }

    public static function all(string $sql, array $params = []): array
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }

    public static function one(string $sql, array $params = []): ?array
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        $r = $st->fetch();
        return $r === false ? null : $r;
    }

    public static function val(string $sql, array $params = []): mixed
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        $r = $st->fetchColumn();
        return $r === false ? null : $r;
    }

    public static function exec(string $sql, array $params = []): int
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st->rowCount();
    }

    public static function insert(string $table, array $data): int
    {
        $cols = array_keys($data);
        $sql = sprintf('INSERT INTO %s (%s) VALUES (%s)', $table, implode(',', $cols), implode(',', array_fill(0, count($cols), '?')));
        self::exec($sql, array_values($data));
        return (int) self::pdo()->lastInsertId();
    }

    /** Génère "?,?,?" pour une clause IN ; retourne "NULL" si la liste est vide */
    public static function in(array $valeurs): string
    {
        return $valeurs ? implode(',', array_fill(0, count($valeurs), '?')) : 'NULL';
    }

    public static function tablesExistent(): bool
    {
        return (bool) self::val("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'users'");
    }

    public static function installerSchema(): void
    {
        $sql = file_get_contents(dirname(__DIR__) . '/sql/schema.sql');
        foreach (array_filter(array_map('trim', preg_split('/;\s*\n/', $sql))) as $requete) {
            if (preg_match('/^\s*(--.*\n\s*)*$/', $requete)) {
                continue;
            }
            self::pdo()->exec($requete);
        }
    }
}
