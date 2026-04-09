<?php

declare(strict_types = 1);

namespace imperazim\db\seed;

use imperazim\db\Database;
use imperazim\db\exception\DatabaseException;

/**
* Populates tables with initial/default data.
*
* Usage:
*   Seeder::run($db, 'users', [
*       ['name' => 'Admin', 'level' => 100],
*       ['name' => 'Guest', 'level' => 1],
*   ]);
*   Seeder::runOnce($db, 'default_items', 'items', [...]);
*/
final class Seeder {

    /** @var array<string, true> Tracks which seeds have been applied */
    private static array $applied = [];

    /**
    * Seeds a table with rows. Always inserts (idempotent only if table is empty).
    *
    * @param Database $db Database instance
    * @param string $table Target table
    * @param array[] $rows Array of associative row data
    * @return int Number of rows inserted
    */
    public static function run(Database $db, string $table, array $rows): int {
        $count = 0;
        foreach ($rows as $row) {
            $db->insert($table, $row);
            $count++;
        }
        return $count;
    }

    /**
    * Seeds a table only once per session (tracked by seed name).
    *
    * @param Database $db Database instance
    * @param string $seedName Unique seed identifier
    * @param string $table Target table
    * @param array[] $rows Array of associative row data
    * @return int Number of rows inserted (0 if already applied)
    */
    public static function runOnce(Database $db, string $seedName, string $table, array $rows): int {
        if (isset(self::$applied[$seedName])) {
            return 0;
        }
        $count = self::run($db, $table, $rows);
        self::$applied[$seedName] = true;
        return $count;
    }

    /**
    * Seeds a table only if it has no existing rows.
    *
    * @param Database $db Database instance
    * @param string $table Target table
    * @param array[] $rows Array of associative row data
    * @return int Number of rows inserted (0 if table not empty)
    */
    public static function runIfEmpty(Database $db, string $table, array $rows): int {
        $result = $db->query("SELECT COUNT(*) AS count FROM `" . str_replace('`', '``', $table) . "`");
        $count = (int) ($result[0]['count'] ?? 0);
        if ($count > 0) {
            return 0;
        }
        return self::run($db, $table, $rows);
    }

    /**
    * Truncates a table and re-seeds it.
    *
    * @param Database $db Database instance
    * @param string $table Target table
    * @param array[] $rows Array of associative row data
    * @return int Number of rows inserted
    */
    public static function refresh(Database $db, string $table, array $rows): int {
        $db->query("DELETE FROM `" . str_replace('`', '``', $table) . "`");
        return self::run($db, $table, $rows);
    }

    /**
    * Checks if a seed has been applied this session.
    *
    * @param string $seedName Seed identifier
    * @return bool
    */
    public static function isApplied(string $seedName): bool {
        return isset(self::$applied[$seedName]);
    }

    /**
    * Resets the applied seeds tracker.
    */
    public static function reset(): void {
        self::$applied = [];
    }
}
