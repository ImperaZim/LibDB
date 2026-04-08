<?php

declare(strict_types = 1);

namespace imperazim\db;

use imperazim\db\exception\DatabaseException;

/**
* Class DBManager
* @package imperazim\db
*/
final class DBManager {

  /**
  * Connects to the specified database using the provided configuration.
  * @param string $type The type of database (e.g., 'mysql', 'sqlite').
  * @param array $config The configuration array for the database connection.
  * @return mixed The database instance.
  * @throws DatabaseException If the connection fails or the configuration is invalid.
  */
  public static function connect(string $type, array $config): mixed {
    try {
      switch (strtolower($type)) {
        case 'mysql':
          return Mysql::connect($config['host'], $config['username'], $config['password'], $config['database']);
        case 'sqlite':
          $directory = dirname($config['database']);
          $fileName = pathinfo($config['database'], PATHINFO_FILENAME);
          return new Sqlite3($directory, $fileName);
        default:
          throw new DatabaseException("Unsupported database type: $type");
      }
    } catch (\Exception $e) {
      throw new DatabaseException("Failed to connect to the database: " . $e->getMessage(), 0, $e);
    }
  }
}
