<?php

declare(strict_types = 1);

namespace imperazim\db;

use imperazim\db\exception\DatabaseException;
use Exception;

/**
* Factory for creating database connections.
*
* Usage:
*   $db = DBManager::connect('sqlite', ['database' => '/path/to/mydb']);
*   $db = DBManager::connect('mysql', [
*       'host' => 'localhost',
*       'username' => 'root',
*       'password' => '',
*       'database' => 'mydb'
*   ]);
*/
final class DBManager {

    /**
    * Connects to the specified database.
    *
    * @param string $type Database type: 'mysql' or 'sqlite'
    * @param array $config Connection configuration
    * @return Database Database instance
    * @throws DatabaseException If connection fails or type unsupported
    */
    public static function connect(string $type, array $config): Database {
        try {
            return match (strtolower($type)) {
                'mysql' => Mysql::connect(
                    $config['host'],
                    $config['username'],
                    $config['password'],
                    $config['database']
                ),
                'sqlite' => new Sqlite3(
                    dirname($config['database']),
                    pathinfo($config['database'], PATHINFO_FILENAME)
                ),
                default => throw new DatabaseException("Unsupported database type: $type"),
            };
        } catch (DatabaseException $e) {
            throw $e;
        } catch (Exception $e) {
            throw new DatabaseException("Failed to connect to the database: " . $e->getMessage(), 0, $e);
        }
    }
}
