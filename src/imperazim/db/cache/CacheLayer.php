<?php

declare(strict_types = 1);

namespace imperazim\db\cache;

use Closure;
use imperazim\db\Database;

/**
* Transparent query cache layer for Database.
*
* Usage:
*   $cached = new CacheLayer($db, defaultTtl: 60);
*   $users = $cached->remember('users:all', 30, fn() => $db->select('users', '*'));
*   $cached->forget('users:all');
*   $cached->flush();
*/
final class CacheLayer {

    /** @var array<string, array{data: mixed, expires: float}> */
    private array $cache = [];

    /** @var array<string, string[]> table => [cache keys] for auto-invalidation */
    private array $tableKeys = [];

    /**
    * @param Database $db Database instance
    * @param int $defaultTtl Default TTL in seconds
    */
    public function __construct(
        private Database $db,
        private int $defaultTtl = 60
    ) {}

    /**
    * Gets a cached value or computes and caches it.
    *
    * @param string $key Cache key
    * @param int|null $ttl TTL in seconds (null = default)
    * @param Closure $loader Callback that returns the data to cache
    * @return mixed Cached or freshly loaded data
    */
    public function remember(string $key, ?int $ttl, Closure $loader): mixed {
        if ($this->has($key)) {
            return $this->cache[$key]['data'];
        }

        $data = $loader();
        $this->put($key, $data, $ttl);
        return $data;
    }

    /**
    * Stores a value in the cache.
    *
    * @param string $key Cache key
    * @param mixed $data Data to cache
    * @param int|null $ttl TTL in seconds
    */
    public function put(string $key, mixed $data, ?int $ttl = null): void {
        $this->cache[$key] = [
            'data' => $data,
            'expires' => microtime(true) + ($ttl ?? $this->defaultTtl),
        ];
    }

    /**
    * Checks if a non-expired cache entry exists.
    *
    * @param string $key Cache key
    * @return bool
    */
    public function has(string $key): bool {
        if (!isset($this->cache[$key])) {
            return false;
        }
        if (microtime(true) > $this->cache[$key]['expires']) {
            unset($this->cache[$key]);
            return false;
        }
        return true;
    }

    /**
    * Gets a cached value.
    *
    * @param string $key Cache key
    * @param mixed $default Default if not found
    * @return mixed
    */
    public function get(string $key, mixed $default = null): mixed {
        return $this->has($key) ? $this->cache[$key]['data'] : $default;
    }

    /**
    * Removes a cache entry.
    *
    * @param string $key Cache key
    */
    public function forget(string $key): void {
        unset($this->cache[$key]);
    }

    /**
    * Tags a cache key as belonging to a table (for auto-invalidation).
    *
    * @param string $table Table name
    * @param string $key Cache key
    */
    public function tag(string $table, string $key): void {
        $this->tableKeys[$table][] = $key;
    }

    /**
    * Cached select with auto table tagging.
    *
    * @param string $table Table name
    * @param string $columns Columns to select
    * @param array $filters Where conditions
    * @param int|null $ttl Cache TTL
    * @return array Result rows
    */
    public function select(string $table, string $columns = '*', array $filters = [], ?int $ttl = null): array {
        $key = "select:{$table}:" . md5($columns . serialize($filters));
        $this->tag($table, $key);

        return $this->remember($key, $ttl, fn() => $this->db->select($table, $columns, $filters));
    }

    /**
    * Inserts a row and invalidates cache for the table.
    *
    * @param string $table Table name
    * @param array $data Row data
    */
    public function insert(string $table, array $data): void {
        $this->db->insert($table, $data);
        $this->invalidateTable($table);
    }

    /**
    * Updates rows and invalidates cache for the table.
    *
    * @param string $table Table name
    * @param string $column Column to update
    * @param mixed $value New value
    * @param array $filters Where conditions
    * @return bool
    */
    public function update(string $table, string $column, mixed $value, array $filters = []): bool {
        $result = $this->db->update($table, $column, $value, $filters);
        $this->invalidateTable($table);
        return $result;
    }

    /**
    * Deletes rows and invalidates cache for the table.
    *
    * @param string $table Table name
    * @param array $filters Where conditions
    * @return int
    */
    public function delete(string $table, array $filters): int {
        $result = $this->db->delete($table, $filters);
        $this->invalidateTable($table);
        return $result;
    }

    /**
    * Invalidates all cache entries tagged to a table.
    *
    * @param string $table Table name
    */
    public function invalidateTable(string $table): void {
        foreach ($this->tableKeys[$table] ?? [] as $key) {
            unset($this->cache[$key]);
        }
        unset($this->tableKeys[$table]);
    }

    /**
    * Clears all cached data.
    */
    public function flush(): void {
        $this->cache = [];
        $this->tableKeys = [];
    }

    /**
    * Returns cache statistics.
    *
    * @return array{entries: int, tables: int}
    */
    public function stats(): array {
        $this->cleanup();
        return [
            'entries' => count($this->cache),
            'tables' => count($this->tableKeys),
        ];
    }

    /**
    * Removes expired entries.
    */
    private function cleanup(): void {
        $now = microtime(true);
        foreach ($this->cache as $key => $entry) {
            if ($now > $entry['expires']) {
                unset($this->cache[$key]);
            }
        }
    }

    /**
    * Returns the underlying database instance.
    *
    * @return Database
    */
    public function getDatabase(): Database {
        return $this->db;
    }
}
