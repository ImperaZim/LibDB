<?php

declare(strict_types = 1);

namespace imperazim\db\migration;

use imperazim\db\Database;
use imperazim\db\exception\DatabaseException;

/**
* Schema versioning with up/down migrations.
*
* Usage:
*   Migration::register('001_create_users', new class extends MigrationStep {
*       public function up(Database $db): void {
*           $db->createTableIfNotExists(['users' => [
*               'id INTEGER PRIMARY KEY AUTOINCREMENT' => '',
*               'name TEXT NOT NULL' => '',
*               'level INTEGER DEFAULT 1' => '',
*           ]]);
*       }
*       public function down(Database $db): void {
*           $db->query('DROP TABLE IF EXISTS `users`');
*       }
*   });
*   Migration::runAll($db);
*/
final class Migration {

    private const MIGRATION_TABLE = '_migrations';

    /** @var array<string, MigrationStep> name => step */
    private static array $migrations = [];

    /** @var string[] Ordered list of migration names */
    private static array $order = [];

    /**
    * Registers a migration step.
    *
    * @param string $name Unique migration name (e.g. '001_create_users')
    * @param MigrationStep $step Migration step instance
    */
    public static function register(string $name, MigrationStep $step): void {
        if (!isset(self::$migrations[$name])) {
            self::$order[] = $name;
        }
        self::$migrations[$name] = $step;
    }

    /**
    * Runs all pending migrations.
    *
    * @param Database $db Database instance
    * @return string[] Names of migrations that were run
    */
    public static function runAll(Database $db): array {
        self::ensureTable($db);
        $applied = self::getApplied($db);
        $ran = [];

        foreach (self::$order as $name) {
            if (in_array($name, $applied, true)) {
                continue;
            }
            self::$migrations[$name]->up($db);
            $db->insert(self::MIGRATION_TABLE, [
                'name' => $name,
                'applied_at' => date('Y-m-d H:i:s'),
            ]);
            $ran[] = $name;
        }

        return $ran;
    }

    /**
    * Runs a single specific migration.
    *
    * @param Database $db Database instance
    * @param string $name Migration name
    * @return bool Whether it was applied
    */
    public static function run(Database $db, string $name): bool {
        self::ensureTable($db);
        if (!isset(self::$migrations[$name])) {
            throw new DatabaseException("Unknown migration: {$name}");
        }

        $applied = self::getApplied($db);
        if (in_array($name, $applied, true)) {
            return false;
        }

        self::$migrations[$name]->up($db);
        $db->insert(self::MIGRATION_TABLE, [
            'name' => $name,
            'applied_at' => date('Y-m-d H:i:s'),
        ]);
        return true;
    }

    /**
    * Rolls back the last N migrations.
    *
    * @param Database $db Database instance
    * @param int $count Number of migrations to roll back
    * @return string[] Names of rolled-back migrations
    */
    public static function rollback(Database $db, int $count = 1): array {
        self::ensureTable($db);
        $applied = self::getApplied($db);
        $toRollback = array_slice(array_reverse($applied), 0, $count);
        $rolledBack = [];

        foreach ($toRollback as $name) {
            if (!isset(self::$migrations[$name])) {
                throw new DatabaseException("Cannot rollback unknown migration: {$name}");
            }
            self::$migrations[$name]->down($db);
            $db->delete(self::MIGRATION_TABLE, [['name' => $name]]);
            $rolledBack[] = $name;
        }

        return $rolledBack;
    }

    /**
    * Returns list of applied migration names.
    *
    * @param Database $db Database instance
    * @return string[]
    */
    public static function getApplied(Database $db): array {
        self::ensureTable($db);
        $rows = $db->query("SELECT `name` FROM `" . self::MIGRATION_TABLE . "` ORDER BY `applied_at` ASC");
        return array_column($rows, 'name');
    }

    /**
    * Returns list of pending migration names.
    *
    * @param Database $db Database instance
    * @return string[]
    */
    public static function getPending(Database $db): array {
        $applied = self::getApplied($db);
        return array_values(array_filter(self::$order, fn(string $name) => !in_array($name, $applied, true)));
    }

    /**
    * Resets all migrations (rolls back all, then clears registry).
    *
    * @param Database $db Database instance
    * @return string[] Names of rolled-back migrations
    */
    public static function resetAll(Database $db): array {
        $applied = self::getApplied($db);
        $rolledBack = [];

        foreach (array_reverse($applied) as $name) {
            if (isset(self::$migrations[$name])) {
                self::$migrations[$name]->down($db);
                $rolledBack[] = $name;
            }
            $db->delete(self::MIGRATION_TABLE, [['name' => $name]]);
        }

        return $rolledBack;
    }

    /**
    * Clears all registered migrations from memory.
    */
    public static function clearRegistry(): void {
        self::$migrations = [];
        self::$order = [];
    }

    /**
    * Ensures the migrations tracking table exists.
    */
    private static function ensureTable(Database $db): void {
        $db->createTableIfNotExists([
            self::MIGRATION_TABLE => [
                'name TEXT PRIMARY KEY' => '',
                'applied_at TEXT NOT NULL' => '',
            ],
        ]);
    }
}
