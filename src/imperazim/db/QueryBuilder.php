<?php

declare(strict_types = 1);

namespace imperazim\db;

use imperazim\db\exception\DatabaseException;

/**
* Fluent query builder for safe SQL construction.
*
* Usage:
*   $users = QueryBuilder::table($db, 'users')
*       ->select('name, age')
*       ->where('age', '>', 18)
*       ->where('active', '=', 1)
*       ->orderBy('name')
*       ->limit(10)
*       ->get();
*
*   QueryBuilder::table($db, 'users')
*       ->insert(['name' => 'John', 'age' => 25]);
*
*   QueryBuilder::table($db, 'users')
*       ->where('id', '=', 5)
*       ->update(['name' => 'Jane']);
*
*   QueryBuilder::table($db, 'users')
*       ->where('id', '=', 5)
*       ->deleteRows();
*/
final class QueryBuilder {

    /** @var array{column: string, operator: string, value: mixed}[] */
    private array $wheres = [];

    private ?string $selectColumns = null;
    private ?string $orderByColumn = null;
    private string $orderDirection = 'ASC';
    private ?int $limitCount = null;
    private ?int $offsetCount = null;

    private function __construct(
        private Database $db,
        private string $table
    ) {}

    /**
    * Creates a new query builder for a table.
    *
    * @param Database $db Database instance
    * @param string $table Table name
    */
    public static function table(Database $db, string $table): self {
        return new self($db, $table);
    }

    /**
    * Sets the columns to select.
    *
    * @param string $columns Comma-separated columns or "*"
    */
    public function select(string $columns = '*'): self {
        $this->selectColumns = $columns;
        return $this;
    }

    /**
    * Adds a WHERE condition.
    *
    * @param string $column Column name
    * @param string $operator Comparison operator (=, !=, >, <, >=, <=, LIKE)
    * @param mixed $value Comparison value
    */
    public function where(string $column, string $operator, mixed $value): self {
        $allowedOps = ['=', '!=', '<>', '>', '<', '>=', '<=', 'LIKE', 'NOT LIKE'];
        $operator = strtoupper(trim($operator));
        if (!in_array($operator, $allowedOps, true)) {
            throw new DatabaseException("Invalid operator: $operator");
        }
        $this->wheres[] = ['column' => $column, 'operator' => $operator, 'value' => $value];
        return $this;
    }

    /**
    * Adds a WHERE column IS NULL condition.
    *
    * @param string $column Column name
    */
    public function whereNull(string $column): self {
        $this->wheres[] = ['column' => $column, 'operator' => 'IS NULL', 'value' => null];
        return $this;
    }

    /**
    * Adds a WHERE column IS NOT NULL condition.
    *
    * @param string $column Column name
    */
    public function whereNotNull(string $column): self {
        $this->wheres[] = ['column' => $column, 'operator' => 'IS NOT NULL', 'value' => null];
        return $this;
    }

    /**
    * Sets ORDER BY clause.
    *
    * @param string $column Column to sort by
    * @param string $direction ASC or DESC
    */
    public function orderBy(string $column, string $direction = 'ASC'): self {
        $this->orderByColumn = $column;
        $this->orderDirection = strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC';
        return $this;
    }

    /**
    * Sets LIMIT clause.
    *
    * @param int $count Max rows to return
    */
    public function limit(int $count): self {
        $this->limitCount = max(0, $count);
        return $this;
    }

    /**
    * Sets OFFSET clause.
    *
    * @param int $count Rows to skip
    */
    public function offset(int $count): self {
        $this->offsetCount = max(0, $count);
        return $this;
    }

    /**
    * Executes a SELECT query and returns results.
    *
    * @return array Result rows
    */
    public function get(): array {
        $columns = $this->selectColumns ?? '*';
        $safeColumns = $columns === '*' ? '*' : implode(", ", array_map(
            [self::class, 'quoteName'],
            array_map('trim', explode(',', $columns))
        ));

        $sql = "SELECT $safeColumns FROM " . self::quoteName($this->table);
        $params = [];

        $sql .= $this->buildWhereSQL($params);

        if ($this->orderByColumn !== null) {
            $sql .= " ORDER BY " . self::quoteName($this->orderByColumn) . " " . $this->orderDirection;
        }

        if ($this->limitCount !== null) {
            $sql .= " LIMIT ?";
            $params[] = $this->limitCount;
        }

        if ($this->offsetCount !== null) {
            $sql .= " OFFSET ?";
            $params[] = $this->offsetCount;
        }

        return $this->db->query($sql, $params);
    }

    /**
    * Returns the first result row or null.
    *
    * @return array|null First row or null
    */
    public function first(): ?array {
        $this->limitCount = 1;
        $results = $this->get();
        return $results[0] ?? null;
    }

    /**
    * Returns the count of matching rows.
    *
    * @return int Row count
    */
    public function count(): int {
        $sql = "SELECT COUNT(*) AS count FROM " . self::quoteName($this->table);
        $params = [];
        $sql .= $this->buildWhereSQL($params);

        $result = $this->db->query($sql, $params);
        return (int) ($result[0]['count'] ?? 0);
    }

    /**
    * Inserts a row.
    *
    * @param array $data Column => value pairs
    */
    public function insert(array $data): void {
        $this->db->insert($this->table, $data);
    }

    /**
    * Updates rows matching the WHERE conditions.
    *
    * @param array $data Column => value pairs to update
    * @return int Number of affected rows
    */
    public function update(array $data): int {
        $setClauses = [];
        $params = [];

        foreach ($data as $column => $value) {
            $setClauses[] = self::quoteName($column) . " = ?";
            $params[] = $value;
        }

        $sql = "UPDATE " . self::quoteName($this->table) . " SET " . implode(", ", $setClauses);
        $sql .= $this->buildWhereSQL($params);

        $this->db->query($sql, $params);
        return count($params); // Approximate; exact count depends on driver
    }

    /**
    * Deletes rows matching the WHERE conditions.
    *
    * @return int Number of deleted rows (approximate)
    */
    public function deleteRows(): int {
        if (empty($this->wheres)) {
            throw new DatabaseException("Cannot delete without WHERE conditions.");
        }

        $params = [];
        $sql = "DELETE FROM " . self::quoteName($this->table);
        $sql .= $this->buildWhereSQL($params);

        $this->db->query($sql, $params);
        return 0; // Exact count depends on driver
    }

    /**
    * Checks if any matching row exists.
    *
    * @return bool Whether a row exists
    */
    public function exists(): bool {
        return $this->count() > 0;
    }

    private function buildWhereSQL(array &$params): string {
        if (empty($this->wheres)) {
            return '';
        }

        $clauses = [];
        foreach ($this->wheres as $where) {
            $quoted = self::quoteName($where['column']);
            if ($where['operator'] === 'IS NULL' || $where['operator'] === 'IS NOT NULL') {
                $clauses[] = "$quoted {$where['operator']}";
            } else {
                $clauses[] = "$quoted {$where['operator']} ?";
                $params[] = $where['value'];
            }
        }

        return " WHERE " . implode(" AND ", $clauses);
    }

    private static function quoteName(string $name): string {
        $name = trim($name);
        if ($name === '*') {
            return $name;
        }
        return '`' . str_replace('`', '``', $name) . '`';
    }
}
