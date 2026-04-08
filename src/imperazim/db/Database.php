<?php

declare(strict_types = 1);

namespace imperazim\db;

/**
* Common interface for database drivers.
*
* Both Mysql and Sqlite3 implement this interface, providing
* a consistent API for CRUD operations.
*/
interface Database {

    /**
    * Creates tables if they do not exist.
    *
    * @param array $tables Associative array: table name => column definitions array
    */
    public function createTableIfNotExists(array $tables): void;

    /**
    * Inserts a row into a table.
    *
    * @param string $table Table name
    * @param array $data Column => value pairs
    */
    public function insert(string $table, array $data): void;

    /**
    * Selects rows from a table.
    *
    * @param string $table Table name
    * @param string $columns Column(s) to select (e.g. "*", "name, age")
    * @param array $filters Where conditions: [["col" => "val"], ...]
    * @return array Result rows
    */
    public function select(string $table, string $columns, array $filters = []): array;

    /**
    * Updates rows in a table.
    *
    * @param string $table Table name
    * @param string $column Column to update
    * @param mixed $value New value
    * @param array $filters Where conditions
    * @return bool Whether any rows were affected
    */
    public function update(string $table, string $column, mixed $value, array $filters = []): bool;

    /**
    * Deletes rows from a table.
    *
    * @param string $table Table name
    * @param array $filters Where conditions (required — no empty deletes)
    * @return int Number of deleted rows
    */
    public function delete(string $table, array $filters): int;

    /**
    * Checks if a record exists in a table.
    *
    * @param string $table Table name
    * @param array $conditions Where conditions
    * @return bool Whether matching record exists
    */
    public function exists(string $table, array $conditions): bool;

    /**
    * Executes a raw query with parameter binding.
    *
    * @param string $sql SQL with placeholders (?)
    * @param array $params Bound parameter values
    * @return array Result rows (empty for non-SELECT)
    */
    public function query(string $sql, array $params = []): array;

    /**
    * Closes the database connection.
    */
    public function close(): void;
}
