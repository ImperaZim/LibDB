<?php

declare(strict_types = 1);

namespace imperazim\db\model;

use imperazim\db\Database;
use imperazim\db\QueryBuilder;

/**
* Query builder scoped to a Model class.
*
* Usage:
*   User::where('level', '>=', 10)->where('active', '=', 1)->get();
*   User::where('name', '=', 'Admin')->first();
*   User::where('level', '<', 5)->count();
*   User::where('id', '=', 3)->delete();
*/
final class ModelQuery {

    /** @var array{column: string, operator: string, value: mixed}[] */
    private array $wheres = [];

    private ?string $orderColumn = null;
    private string $orderDirection = 'ASC';
    private ?int $limitCount = null;

    /**
    * @param class-string<Model> $modelClass The model class name
    */
    public function __construct(
        private string $modelClass
    ) {}

    /**
    * Adds a WHERE condition.
    *
    * @param string $column Column name
    * @param string $operator Comparison operator
    * @param mixed $value Comparison value
    * @return self
    */
    public function where(string $column, string $operator, mixed $value): self {
        $this->wheres[] = ['column' => $column, 'operator' => $operator, 'value' => $value];
        return $this;
    }

    /**
    * Sets ORDER BY clause.
    *
    * @param string $column Column to sort by
    * @param string $direction ASC or DESC
    * @return self
    */
    public function orderBy(string $column, string $direction = 'ASC'): self {
        $this->orderColumn = $column;
        $this->orderDirection = strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC';
        return $this;
    }

    /**
    * Sets LIMIT clause.
    *
    * @param int $count Max rows
    * @return self
    */
    public function limit(int $count): self {
        $this->limitCount = $count;
        return $this;
    }

    /**
    * Executes the query and returns model instances.
    *
    * @return Model[]
    */
    public function get(): array {
        $builder = $this->buildQuery();

        if ($this->orderColumn !== null) {
            $builder->orderBy($this->orderColumn, $this->orderDirection);
        }
        if ($this->limitCount !== null) {
            $builder->limit($this->limitCount);
        }

        $rows = $builder->get();
        $class = $this->modelClass;
        return array_map(fn(array $row) => $class::hydrate($row), $rows);
    }

    /**
    * Returns the first matching model or null.
    *
    * @return Model|null
    */
    public function first(): ?Model {
        $this->limitCount = 1;
        $results = $this->get();
        return $results[0] ?? null;
    }

    /**
    * Returns count of matching rows.
    *
    * @return int
    */
    public function count(): int {
        return $this->buildQuery()->count();
    }

    /**
    * Checks if matching row exists.
    *
    * @return bool
    */
    public function exists(): bool {
        return $this->count() > 0;
    }

    /**
    * Deletes matching rows.
    *
    * @return int
    */
    public function delete(): int {
        return $this->buildQuery()->deleteRows();
    }

    /**
    * Updates matching rows.
    *
    * @param array $data Column => value pairs
    * @return int
    */
    public function update(array $data): int {
        return $this->buildQuery()->update($data);
    }

    /**
    * Builds the underlying QueryBuilder with all where conditions.
    *
    * @return QueryBuilder
    */
    private function buildQuery(): QueryBuilder {
        $class = $this->modelClass;
        $builder = QueryBuilder::table($class::db(), $class::getTable());

        foreach ($this->wheres as $where) {
            $builder->where($where['column'], $where['operator'], $where['value']);
        }

        return $builder;
    }
}
