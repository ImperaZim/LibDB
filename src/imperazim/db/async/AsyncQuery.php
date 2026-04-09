<?php

declare(strict_types = 1);

namespace imperazim\db\async;

use pocketmine\Server;

/**
* Non-blocking database queries using PocketMine's AsyncTask system.
*
* Usage:
*   AsyncQuery::sqlite('path/to/db.db', "SELECT * FROM users WHERE level > ?", [10],
*       onComplete: fn(array $rows) => var_dump($rows),
*       onError: fn(\Throwable $e) => echo $e->getMessage()
*   );
*
*   AsyncQuery::mysql(
*       ['host' => 'localhost', 'username' => 'root', 'password' => '', 'database' => 'mydb'],
*       "SELECT * FROM users",
*       onComplete: fn(array $rows) => handleResults($rows)
*   );
*/
final class AsyncQuery {

    /**
    * Runs an async SQLite query.
    *
    * @param string $dbPath Full path to SQLite database file
    * @param string $sql SQL query with ? placeholders
    * @param array $params Bound parameters
    * @param \Closure|null $onComplete fn(array $rows): void — called on main thread
    * @param \Closure|null $onError fn(\Throwable $error): void — called on main thread
    */
    public static function sqlite(string $dbPath, string $sql, array $params = [], ?\Closure $onComplete = null, ?\Closure $onError = null): void {
        $task = new AsyncDatabaseTask('sqlite', ['database' => $dbPath], $sql, $params, $onComplete, $onError);
        Server::getInstance()->getAsyncPool()->submitTask($task);
    }

    /**
    * Runs an async MySQL query.
    *
    * @param array $config Connection config: host, username, password, database
    * @param string $sql SQL query with ? placeholders
    * @param array $params Bound parameters
    * @param \Closure|null $onComplete fn(array $rows): void — called on main thread
    * @param \Closure|null $onError fn(\Throwable $error): void — called on main thread
    */
    public static function mysql(array $config, string $sql, array $params = [], ?\Closure $onComplete = null, ?\Closure $onError = null): void {
        $task = new AsyncDatabaseTask('mysql', $config, $sql, $params, $onComplete, $onError);
        Server::getInstance()->getAsyncPool()->submitTask($task);
    }
}
