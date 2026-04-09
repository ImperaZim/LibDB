<?php

declare(strict_types = 1);

namespace imperazim\db;

use mysqli;
use mysqli_result;
use mysqli_sql_exception;
use Closure;
use Throwable;
use imperazim\db\exception\DatabaseException;

/**
* MySQL database driver using mysqli with prepared statements.
*
* All table/column names are sanitized with backtick quoting.
* Values are always bound via prepared statements.
*/
final class Mysql implements Database {

    public function __construct(private mysqli $connection) {}

    /**
    * Connects to a MySQL database.
    *
    * @param string $host Database host
    * @param string $user Database user
    * @param string $password Database password
    * @param string $database Database name
    * @return self Connected instance
    * @throws DatabaseException If connection fails
    */
    public static function connect(string $host, string $user, string $password, string $database): self {
        try {
            $connection = new mysqli($host, $user, $password, $database);
            if ($connection->connect_error) {
                throw new DatabaseException("Connection failed: " . $connection->connect_error);
            }
            return new self($connection);
        } catch (mysqli_sql_exception $e) {
            throw new DatabaseException("MySQL connection error: " . $e->getMessage(), 0, $e);
        }
    }

    public function createTableIfNotExists(array $tables): void {
        try {
            foreach ($tables as $table => $rows) {
                $safeName = self::quoteName($table);
                $columnDefs = implode(", ", array_keys($rows));
                $this->connection->query("CREATE TABLE IF NOT EXISTS $safeName ($columnDefs)");
            }
        } catch (mysqli_sql_exception $e) {
            throw new DatabaseException("MySQL create table error: " . $e->getMessage(), 0, $e);
        }
    }

    public function insert(string $table, array $data): void {
        try {
            $columns = implode(", ", array_map([self::class, 'quoteName'], array_keys($data)));
            $placeholders = implode(", ", array_fill(0, count($data), "?"));
            $values = array_values($data);

            $sql = "INSERT INTO " . self::quoteName($table) . " ($columns) VALUES ($placeholders)";
            $stmt = $this->connection->prepare($sql);
            $stmt->bind_param(self::buildTypeString($values), ...$values);
            $stmt->execute();
            $stmt->close();
        } catch (mysqli_sql_exception $e) {
            throw new DatabaseException("MySQL insert error: " . $e->getMessage(), 0, $e);
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

            $stmt = $this->connection->prepare($sql);
            if (!empty($values)) {
                $stmt->bind_param(self::buildTypeString($values), ...$values);
            }
            $stmt->execute();
            $result = $stmt->get_result();
            $rows = $result->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            return $rows;
        } catch (mysqli_sql_exception $e) {
            throw new DatabaseException("MySQL select error: " . $e->getMessage(), 0, $e);
        }
    }

    public function update(string $table, string $column, mixed $value, array $filters = []): bool {
        try {
            $sql = "UPDATE " . self::quoteName($table) . " SET " . self::quoteName($column) . " = ?";
            $values = [$value];

            if (!empty($filters)) {
                $sql .= " WHERE " . $this->buildWhereClause($filters, $values);
            }

            $stmt = $this->connection->prepare($sql);
            $stmt->bind_param(self::buildTypeString($values), ...$values);
            $stmt->execute();
            $affected = $stmt->affected_rows > 0;
            $stmt->close();
            return $affected;
        } catch (mysqli_sql_exception $e) {
            throw new DatabaseException("MySQL update error: " . $e->getMessage(), 0, $e);
        }
    }

    public function delete(string $table, array $filters): int {
        if (empty($filters)) {
            throw new DatabaseException("Cannot delete without conditions. Use query() for raw DELETE.");
        }
        try {
            $values = [];
            $sql = "DELETE FROM " . self::quoteName($table) . " WHERE " . $this->buildWhereClause($filters, $values);
            $stmt = $this->connection->prepare($sql);
            $stmt->bind_param(self::buildTypeString($values), ...$values);
            $stmt->execute();
            $count = $stmt->affected_rows;
            $stmt->close();
            return $count;
        } catch (mysqli_sql_exception $e) {
            throw new DatabaseException("MySQL delete error: " . $e->getMessage(), 0, $e);
        }
    }

    public function exists(string $table, array $conditions): bool {
        try {
            $values = [];
            $sql = "SELECT COUNT(*) AS count FROM " . self::quoteName($table) . " WHERE " . $this->buildWhereClause($conditions, $values);
            $stmt = $this->connection->prepare($sql);
            $stmt->bind_param(self::buildTypeString($values), ...$values);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            $stmt->close();
            return $row['count'] > 0;
        } catch (mysqli_sql_exception $e) {
            throw new DatabaseException("MySQL exists error: " . $e->getMessage(), 0, $e);
        }
    }

    /**
    * Executes a raw query with parameter binding.
    *
    * @param string $sql SQL with placeholders (?)
    * @param array $params Bound parameter values
    * @return array Result rows (empty for non-SELECT)
    */
    public function query(string $sql, array $params = []): array {
        try {
            $stmt = $this->connection->prepare($sql);
            if (!empty($params)) {
                $stmt->bind_param(self::buildTypeString($params), ...$params);
            }
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result instanceof mysqli_result) {
                $rows = $result->fetch_all(MYSQLI_ASSOC);
                $stmt->close();
                return $rows;
            }
            $stmt->close();
            return [];
        } catch (mysqli_sql_exception $e) {
            throw new DatabaseException("MySQL query error: " . $e->getMessage(), 0, $e);
        }
    }

    /**
    * Inserts or updates a row (upsert via ON DUPLICATE KEY UPDATE).
    *
    * @param string $table Table name
    * @param array $data Column => value pairs
    */
    public function upsert(string $table, array $data): void {
        try {
            $columns = implode(", ", array_map([self::class, 'quoteName'], array_keys($data)));
            $placeholders = implode(", ", array_fill(0, count($data), "?"));
            $updates = implode(", ", array_map(
                fn($col) => self::quoteName($col) . " = VALUES(" . self::quoteName($col) . ")",
                array_keys($data)
            ));
            $values = array_values($data);

            $sql = "INSERT INTO " . self::quoteName($table) . " ($columns) VALUES ($placeholders) ON DUPLICATE KEY UPDATE $updates";
            $stmt = $this->connection->prepare($sql);
            $stmt->bind_param(self::buildTypeString($values), ...$values);
            $stmt->execute();
            $stmt->close();
        } catch (mysqli_sql_exception $e) {
            throw new DatabaseException("MySQL upsert error: " . $e->getMessage(), 0, $e);
        }
    }

    /**
    * Executes a callback within a transaction.
    *
    * @param Closure $callback fn(Mysql): void
    */
    public function transaction(Closure $callback): void {
        try {
            $this->connection->begin_transaction();
            $callback($this);
            $this->connection->commit();
        } catch (Throwable $e) {
            $this->connection->rollback();
            throw new DatabaseException("MySQL transaction error: " . $e->getMessage(), 0, $e);
        }
    }

    public function close(): void {
        $this->connection->close();
    }

    /**
    * Gets the underlying mysqli instance.
    */
    public function getMysqli(): mysqli {
        return $this->connection;
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

    /**
    * Builds a type string for mysqli bind_param.
    */
    private static function buildTypeString(array $values): string {
        $types = '';
        foreach ($values as $value) {
            if (is_int($value)) {
                $types .= 'i';
            } elseif (is_float($value)) {
                $types .= 'd';
            } elseif (is_string($value)) {
                $types .= 's';
            } else {
                $types .= 's';
            }
        }
        return $types;
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
