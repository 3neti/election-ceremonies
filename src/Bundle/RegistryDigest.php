<?php

namespace LBHurtado\ElectionCeremonies\Bundle;

use PDO;
use RuntimeException;

final class RegistryDigest
{
    public function hash(string $sqlitePath): string
    {
        if (! is_file($sqlitePath)) {
            throw new RuntimeException('Election registry database is missing.');
        }

        $database = new PDO('sqlite:'.$sqlitePath);
        $database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $database->exec('PRAGMA query_only = ON');

        if ($database->query('PRAGMA integrity_check')->fetchColumn() !== 'ok'
            || $database->query('PRAGMA foreign_key_check')->fetch() !== false) {
            throw new RuntimeException('Election registry database failed integrity checks.');
        }

        $tables = $database->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name")
            ->fetchAll(PDO::FETCH_COLUMN);

        if ($tables === []) {
            throw new RuntimeException('Election registry database has no tables.');
        }

        $hash = hash_init('sha256');

        foreach ($tables as $table) {
            $quoted = '"'.str_replace('"', '""', $table).'"';
            $columns = $database->query("PRAGMA table_info({$quoted})")->fetchAll(PDO::FETCH_ASSOC);
            $names = array_column($columns, 'name');
            $ordered = implode(', ', array_map(fn (string $name): string => '"'.str_replace('"', '""', $name).'"', $names));
            hash_update($hash, "table:{$table}\ncolumns:".json_encode($names, JSON_THROW_ON_ERROR)."\n");

            foreach ($database->query("SELECT * FROM {$quoted} ORDER BY {$ordered}", PDO::FETCH_ASSOC) as $row) {
                hash_update($hash, json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
            }
        }

        return hash_final($hash);
    }
}
