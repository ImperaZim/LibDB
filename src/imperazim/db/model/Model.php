<?php

declare(strict_types = 1);

namespace imperazim\db\model;

use imperazim\db\Database;
use imperazim\db\QueryBuilder;
use imperazim\db\exception\DatabaseException;

/**
* Simple Active Record ORM base class.
*
* Usage:
*   class User extends Model {
*       protected static string $table = 'users';
*       protected static string $primaryKey = 'id';
*   }
*
*   Model::setDatabase($db);
*   $user = User::find(1);
*   $user->name = 'John';
*   $user->save();
*
*   $admins = User::where('level', '>=', 100)->get();
*   User::create(['name' => 'Jane', 'level' => 50]);
*/
abstract class Model {

    protected static string $table = '';
    protected static string $primaryKey = 'id';

    private static ?Database $db = null;

    /** @var array<string, mixed> Current attributes */
    protected array $attributes = [];

    /** @var array<string, mixed> Original attributes (for dirty tracking) */
    private array $original = [];

    /** @var bool Whether this record exists in the database */
    private bool $exists = false;

    /**
    * Sets the database connection for all models.
    *
    * @param Database $db Database instance
    */
    public static function setDatabase(Database $db): void {
        self::$db = $db;
    }

    /**
    * Returns the database connection.
    *
    * @return Database
    */
    public static function db(): Database {
        if (self::$db === null) {
            throw new DatabaseException('No database connection set. Call Model::setDatabase() first.');
        }
        return self::$db;
    }

    /**
    * Returns the table name for this model.
    *
    * @return string
    */
    public static function getTable(): string {
        return static::$table;
    }

    /**
    * Creates a new model instance from database row data.
    *
    * @param array $attributes Row data
    * @param bool $exists Whether the record exists in DB
    * @return static
    */
    public static function hydrate(array $attributes, bool $exists = true): static {
        $model = new static();
        $model->attributes = $attributes;
        $model->original = $attributes;
        $model->exists = $exists;
        return $model;
    }

    /**
    * Finds a record by primary key.
    *
    * @param mixed $id Primary key value
    * @return static|null
    */
    public static function find(mixed $id): ?static {
        $rows = static::db()->select(static::$table, '*', [[static::$primaryKey => $id]]);
        return !empty($rows) ? static::hydrate($rows[0]) : null;
    }

    /**
    * Finds a record or throws.
    *
    * @param mixed $id Primary key value
    * @return static
    * @throws DatabaseException If not found
    */
    public static function findOrFail(mixed $id): static {
        $model = static::find($id);
        if ($model === null) {
            throw new DatabaseException(static::$table . " with " . static::$primaryKey . " = {$id} not found.");
        }
        return $model;
    }

    /**
    * Returns all records.
    *
    * @return static[]
    */
    public static function all(): array {
        $rows = static::db()->select(static::$table, '*');
        return array_map(fn(array $row) => static::hydrate($row), $rows);
    }

    /**
    * Starts a where query builder.
    *
    * @param string $column Column name
    * @param string $operator Comparison operator
    * @param mixed $value Comparison value
    * @return ModelQuery
    */
    public static function where(string $column, string $operator, mixed $value): ModelQuery {
        return (new ModelQuery(static::class))->where($column, $operator, $value);
    }

    /**
    * Creates and saves a new record.
    *
    * @param array $data Column => value pairs
    * @return static
    */
    public static function create(array $data): static {
        static::db()->insert(static::$table, $data);
        $model = static::hydrate($data, true);
        return $model;
    }

    /**
    * Returns a QueryBuilder for this model's table.
    *
    * @return QueryBuilder
    */
    public static function query(): QueryBuilder {
        return QueryBuilder::table(static::db(), static::$table);
    }

    /**
    * Saves the model (insert or update).
    */
    public function save(): void {
        if ($this->exists) {
            $dirty = $this->getDirty();
            if (empty($dirty)) {
                return;
            }
            foreach ($dirty as $column => $value) {
                static::db()->update(static::$table, $column, $value, [[static::$primaryKey => $this->getKey()]]);
            }
        } else {
            static::db()->insert(static::$table, $this->attributes);
            $this->exists = true;
        }
        $this->original = $this->attributes;
    }

    /**
    * Deletes this record.
    *
    * @return bool
    */
    public function destroy(): bool {
        if (!$this->exists) {
            return false;
        }
        static::db()->delete(static::$table, [[static::$primaryKey => $this->getKey()]]);
        $this->exists = false;
        return true;
    }

    /**
    * Returns the primary key value.
    *
    * @return mixed
    */
    public function getKey(): mixed {
        return $this->attributes[static::$primaryKey] ?? null;
    }

    /**
    * Returns attributes that have changed since loading.
    *
    * @return array
    */
    public function getDirty(): array {
        $dirty = [];
        foreach ($this->attributes as $key => $value) {
            if (!array_key_exists($key, $this->original) || $this->original[$key] !== $value) {
                $dirty[$key] = $value;
            }
        }
        return $dirty;
    }

    /**
    * Whether this record exists in the database.
    *
    * @return bool
    */
    public function exists(): bool {
        return $this->exists;
    }

    /**
    * Returns all attributes as an array.
    *
    * @return array
    */
    public function toArray(): array {
        return $this->attributes;
    }

    /**
    * Fills the model with an array of attributes.
    *
    * @param array $data Column => value pairs
    * @return static
    */
    public function fill(array $data): static {
        foreach ($data as $key => $value) {
            $this->attributes[$key] = $value;
        }
        return $this;
    }

    public function __get(string $name): mixed {
        return $this->attributes[$name] ?? null;
    }

    public function __set(string $name, mixed $value): void {
        $this->attributes[$name] = $value;
    }

    public function __isset(string $name): bool {
        return isset($this->attributes[$name]);
    }

    public function __unset(string $name): void {
        unset($this->attributes[$name]);
    }
}
