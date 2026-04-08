<?php

declare(strict_types = 1);

namespace imperazim\db;

use PDO;
use PDOException;
use PDOStatement;
use imperazim\db\exception\DatabaseException;

/**
* SQLite database driver using PDO.
*
* All table/column names are sanitized with backtick quoting.
* Values are always bound via prepared statements.
*/
final class Sqlite3 implements Database {

    private PDO $sqlite;

    /**
    * @param string $directory Directory for the .db file
    * @param string $fileName Database file name (without .db extension)
    */
    public function __construct(
        string $directory,
        string $fileName
    ) {
        $directory = rtrim(str_replace('//', '/', $directory . '/'), '/') . '/';
        try {
            $this->sqlite = new PDO('sqlite:' . $directory . $fileName . '.db', null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
            ]);
        } catch (PDOException $e) {
            throw new DatabaseException("SQLite connection error: " . $e->getMessage(), 0, $e);
        }
    }

    public function createTableIfNotExists(array $tables): void {
        try {
            foreach ($tables as $table => $rows) {
                $safeName = self::quoteName($table);
                $columnDefs = implode(", ", array_keys($rows));
                $this->sqlite->exec("CREATE TABLE IF NOT EXISTS $safeName ($columnDefs)");
            }
        } catch (PDOException $e) {
            throw new DatabaseException("SQLite create table error: " . $e->getMessage(), 0, $e);
        }
    }

    public function insert(string $table, array $data): void {
        try {
            $columns = implode(", ", array_map([self::class, 'quoteName'], array_keys($data)));
            $placeholders = implode(", ", array_fill(0, count($data), "?"));
            $values = array_values($data);

            $sql = "INSERT INTO " . self::quoteName($table) . " ($columns) VALUES ($placeholders)";
            $stmt = $this->sqlite->prepare($sql);
            $stmt->execute($values);
        } catch (PDOException $e) {
            throw new DatabaseException("SQLite insert error: " . $e->getMessage(), 0, $e);
        }
    }

    public function select(string $table, string $columns, array $filters = []): array {
        try {
            $safeColumns = $columns === '*' ? '*' : implode(", ", array_map(
                [self::class, 'quoteName'],
                array_map('trim', explode(',', $columns))
            ));
            $sql = "SELECT $safeColumns FROM " . self::quoteName($table);
            $values = [];

            if (!empty($filters)) {
                $sql .= " WHERE " . $this->buildWhereClause($filters, $values);
            }

            $stmt = $this->sqlite->prepare($sql);
            $stmt->execute($values);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            throw new DatabaseException("SQLite select error: " . $e->getMessage(), 0, $e);
        }
    }

    public function update(string $table, string $column, mixed $value, array $filters = []): bool {
        try {
            $sql = "UPDATE " . self::quoteName($table) . " SET " . self::quoteName($column) . " = ?";
            $values = [$value];
            if (!empty($filters)) {
                $sql .= " WHERE " . $this->buildWhereClause($filters, $values);
            }
            $stmt = $this->sqlite->prepare($sql);
            $stmt->execute($values);
            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            throw new DatabaseException("SQLite update error: " . $e->getMessage(), 0, $e);
        }
    }

    public function delete(string $table, array $filters): int {
        if (empty($filters)) {
            throw new DatabaseException("Cannot delete without conditions. Use query() for raw DELETE.");
        }
        try {
            $values = [];
            $sql = "DELETE FROM " . self::quoteName($table) . " WHERE " . $this->buildWhereClause($filters, $values);
            $stmt = $this->sqlite->prepare($sql);
            $stmt->execute($values);
            return $stmt->rowCount();
        } catch (PDOException $e) {
            throw new DatabaseException("SQLite delete error: " . $e->getMessage(), 0, $e);
        }
    }

    public function exists(string $table, array $conditions): bool {
        try {
            $values = [];
            $sql = "SELECT COUNT(*) AS count FROM " . self::quoteName($table) . " WHERE " . $this->buildWhereClause($conditions, $values);
            $stmt = $this->sqlite->prepare($sql);
            $stmt->execute($values);
            $row = $stmt->fetch();
            return $row['count'] > 0;
        } catch (PDOException $e) {
            throw new DatabaseException("SQLite exists error: " . $e->getMessage(), 0, $e);
        }
    }

    public function query(string $sql, array $params = []): array {
        try {
            $stmt = $this->sqlite->prepare($sql);
            $stmt->execute($params);
            if ($stmt->columnCount() > 0) {
                return $stmt->fetchAll();
            }
            return [];
        } catch (PDOException $e) {
            throw new DatabaseException("SQLite query error: " . $e->getMessage(), 0, $e);
        }
    }

    /**
    * Inserts or replaces a row (upsert via SQLite's INSERT OR REPLACE).
    *
    * @param string $table Table name
    * @param array $data Column => value pairs
    */
    public function upsert(string $table, array $data): void {
        try {
            $columns = implode(", ", array_map([self::class, 'quoteName'], array_keys($data)));
            $placeholders = implode(", ", array_fill(0, count($data), "?"));
            $values = array_values($data);

            $sql = "INSERT OR REPLACE INTO " . self::quoteName($table) . " ($columns) VALUES ($placeholders)";
            $stmt = $this->sqlite->prepare($sql);
            $stmt->execute($values);
        } catch (PDOException $e) {
            throw new DatabaseException("SQLite upsert error: " . $e->getMessage(), 0, $e);
        }
    }

    /**
    * Executes a callback within a transaction.
    *
    * @param \Closure $callback fn(Sqlite3): void
    */
    public function transaction(\Closure $callback): void {
        try {
            $this->sqlite->beginTransaction();
            $callback($this);
            $this->sqlite->commit();
        } catch (\Throwable $e) {
            $this->sqlite->rollBack();
            throw new DatabaseException("SQLite transaction error: " . $e->getMessage(), 0, $e);
        }
    }

    public function close(): void {
        unset($this->sqlite);
    }

    /**
    * Gets the underlying PDO instance.
    */
    public function getPdo(): PDO {
        return $this->sqlite;
    }

    /**
    * Sanitizes an identifier (table/column name) with backtick quoting.
    */
    private static function quoteName(string $name): string {
        $name = trim($name);
        if ($name === '*') {
            return $name;
        }
        return '`' . str_replace('`', '``', $name) . '`';
    }

    private function buildWhereClause(array $filters, array &$values): string {
        $whereClauses = [];
        foreach ($filters as $filter) {
            $clauses = [];
            foreach ($filter as $key => $value) {
                $clauses[] = self::quoteName($key) . " = ?";
                $values[] = $value;
            }
            $whereClauses[] = implode(" AND ", $clauses);
        }
        return implode(" AND ", $whereClauses);
    }
}
