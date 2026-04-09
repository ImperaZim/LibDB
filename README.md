# LibDB

<p align="center">
  <img src="https://img.shields.io/badge/PocketMine--MP-5.0.0+-blue?style=flat-square" />
  <img src="https://img.shields.io/badge/PHP-8.2+-777bb4?style=flat-square" />
  <img src="https://img.shields.io/github/license/ImperaZim/LibDB?style=flat-square" />
  <img src="https://img.shields.io/github/issues/ImperaZim/LibDB?style=flat-square" />
  <img src="https://img.shields.io/github/stars/ImperaZim/LibDB?style=flat-square" />
</p>

---

> **LibDB** is a complete database toolkit for PocketMine-MP plugins, providing a unified interface for SQLite and MySQL with fluent query building, an Active Record ORM, schema migrations, data seeding, in-memory caching, and async query support. All operations use prepared statements and identifier quoting for safety.

---

## Technical Features

- Unified `Database` interface for SQLite (PDO) and MySQL (mysqli) drivers
- Factory-based connection via `DBManager` with automatic driver resolution
- Fluent `QueryBuilder` with chainable WHERE, ORDER BY, LIMIT, OFFSET clauses
- Active Record ORM (`Model`) with dirty tracking, find/create/save/destroy
- `ModelQuery` for scoped queries directly from model classes
- Schema versioning with up/down `Migration` steps and rollback support
- `Seeder` for populating tables with initial data (run once, run if empty, refresh)
- `CacheLayer` with TTL, table-based auto-invalidation, and cached CRUD
- `AsyncQuery` and `AsyncDatabaseTask` for non-blocking queries via PocketMine's AsyncTask pool
- `DatabaseException` for consistent error handling across all components
- Transactions with automatic rollback on both SQLite and MySQL drivers
- Upsert support (`INSERT OR REPLACE` for SQLite, `ON DUPLICATE KEY UPDATE` for MySQL)

---

## Installation & Requirements

- **PocketMine-MP** API 5.0.0+
- **PHP** 8.2+
- **No external dependencies**

**Installation:**
- As a library: place `imperazim/db` in your `src/` and register the autoload.
- As a PHAR plugin: download the `.phar` and place it in `plugins/`.

---

## Basic Integration

In your main plugin class:

```php
use imperazim\db\DBManager;

public function onEnable(): void {
    // SQLite
    $db = DBManager::connect('sqlite', [
        'database' => $this->getDataFolder() . 'storage/mydb'
    ]);

    // MySQL
    $db = DBManager::connect('mysql', [
        'host'     => 'localhost',
        'username' => 'root',
        'password' => 'secret',
        'database' => 'mydb'
    ]);
}
```

---

## Technical Examples

### 1. Creating tables

```php
$db->createTableIfNotExists([
    'players' => [
        'id INTEGER PRIMARY KEY AUTOINCREMENT' => '',
        'name TEXT NOT NULL' => '',
        'level INTEGER DEFAULT 1' => '',
        'coins REAL DEFAULT 0.0' => '',
    ]
]);
```

---

### 2. Basic CRUD operations

```php
// Insert
$db->insert('players', ['name' => 'Steve', 'level' => 5, 'coins' => 100.0]);

// Select all
$rows = $db->select('players', '*');

// Select with filter
$admins = $db->select('players', 'name, level', [['level' => 100]]);

// Update
$db->update('players', 'level', 50, [['name' => 'Steve']]);

// Delete
$deleted = $db->delete('players', [['name' => 'Steve']]);

// Check existence
$exists = $db->exists('players', [['name' => 'Steve']]);

// Raw query with parameter binding
$results = $db->query('SELECT * FROM `players` WHERE `level` > ?', [10]);
```

---

### 3. Fluent QueryBuilder

```php
use imperazim\db\QueryBuilder;

// Select with conditions, ordering and pagination
$topPlayers = QueryBuilder::table($db, 'players')
    ->select('name, level, coins')
    ->where('level', '>=', 10)
    ->where('coins', '>', 0)
    ->orderBy('level', 'DESC')
    ->limit(10)
    ->offset(0)
    ->get();

// Get first matching row
$player = QueryBuilder::table($db, 'players')
    ->select('*')
    ->where('name', '=', 'Steve')
    ->first();

// Count rows
$count = QueryBuilder::table($db, 'players')
    ->where('level', '>=', 50)
    ->count();

// Check existence
$hasAdmins = QueryBuilder::table($db, 'players')
    ->where('level', '>=', 100)
    ->exists();

// Insert via builder
QueryBuilder::table($db, 'players')
    ->insert(['name' => 'Alex', 'level' => 1, 'coins' => 0.0]);

// Update via builder
QueryBuilder::table($db, 'players')
    ->where('name', '=', 'Alex')
    ->update(['level' => 10, 'coins' => 50.0]);

// Delete via builder
QueryBuilder::table($db, 'players')
    ->where('level', '<', 5)
    ->deleteRows();

// NULL conditions
QueryBuilder::table($db, 'players')
    ->whereNull('last_login')
    ->get();

QueryBuilder::table($db, 'players')
    ->whereNotNull('last_login')
    ->count();
```

---

### 4. Active Record ORM

```php
use imperazim\db\model\Model;

class Player extends Model {
    protected static string $table = 'players';
    protected static string $primaryKey = 'id';
}

// Set the database connection (once, at startup)
Model::setDatabase($db);

// Find by primary key
$player = Player::find(1);
$player = Player::findOrFail(1); // throws DatabaseException if not found

// Get all records
$allPlayers = Player::all();

// Create a new record
$player = Player::create(['name' => 'Notch', 'level' => 100, 'coins' => 9999.0]);

// Modify and save
$player = Player::find(1);
$player->level = 75;
$player->coins = 500.0;
$player->save(); // only updates dirty (changed) attributes

// Delete a record
$player->destroy();

// Query through the model
$veterans = Player::where('level', '>=', 50)
    ->where('coins', '>', 100)
    ->orderBy('level', 'DESC')
    ->limit(20)
    ->get();

$topPlayer = Player::where('level', '>=', 90)->first();
$count = Player::where('level', '<', 10)->count();
$hasNewbies = Player::where('level', '=', 1)->exists();

// Bulk operations
Player::where('level', '<', 5)->update(['level' => 5]);
Player::where('coins', '<', 0)->delete();

// Fill attributes in bulk
$player->fill(['level' => 30, 'coins' => 200.0]);
$player->save();

// Convert to array
$data = $player->toArray();

// Access underlying QueryBuilder
$results = Player::query()
    ->where('name', 'LIKE', '%Steve%')
    ->get();
```

---

### 5. Migrations

```php
use imperazim\db\migration\Migration;
use imperazim\db\migration\MigrationStep;
use imperazim\db\Database;

// Register migration steps
Migration::register('001_create_players', new class extends MigrationStep {
    public function up(Database $db): void {
        $db->createTableIfNotExists(['players' => [
            'id INTEGER PRIMARY KEY AUTOINCREMENT' => '',
            'name TEXT NOT NULL' => '',
            'level INTEGER DEFAULT 1' => '',
        ]]);
    }
    public function down(Database $db): void {
        $db->query('DROP TABLE IF EXISTS `players`');
    }
});

Migration::register('002_add_coins_column', new class extends MigrationStep {
    public function up(Database $db): void {
        $db->query('ALTER TABLE `players` ADD COLUMN `coins` REAL DEFAULT 0.0');
    }
    public function down(Database $db): void {
        // SQLite does not support DROP COLUMN on older versions;
        // recreate the table without the column if needed
    }
});

// Run all pending migrations
$applied = Migration::runAll($db);
// Returns: ['001_create_players', '002_add_coins_column']

// Run a single migration
Migration::run($db, '002_add_coins_column');

// Check migration status
$pending = Migration::getPending($db);
$applied = Migration::getApplied($db);

// Rollback the last migration
$rolledBack = Migration::rollback($db, 1);

// Reset all migrations (rollback everything)
Migration::resetAll($db);

// Clear in-memory registry
Migration::clearRegistry();
```

---

### 6. Seeder

```php
use imperazim\db\seed\Seeder;

// Insert rows into a table
Seeder::run($db, 'items', [
    ['name' => 'Sword', 'damage' => 7],
    ['name' => 'Shield', 'defense' => 5],
    ['name' => 'Potion', 'heal' => 20],
]);

// Run a seed only once per session (tracked by name)
Seeder::runOnce($db, 'default_items', 'items', [
    ['name' => 'Sword', 'damage' => 7],
    ['name' => 'Shield', 'defense' => 5],
]);

// Seed only if the table is empty
Seeder::runIfEmpty($db, 'items', [
    ['name' => 'Sword', 'damage' => 7],
]);

// Truncate and re-seed
Seeder::refresh($db, 'items', [
    ['name' => 'Diamond Sword', 'damage' => 12],
    ['name' => 'Iron Shield', 'defense' => 8],
]);

// Check if a seed was already applied
if (Seeder::isApplied('default_items')) {
    // skip
}

// Reset the applied seeds tracker
Seeder::reset();
```

---

### 7. CacheLayer

```php
use imperazim\db\cache\CacheLayer;

$cache = new CacheLayer($db, defaultTtl: 60);

// Cached select (auto-tagged by table for invalidation)
$players = $cache->select('players', '*', [], ttl: 30);

// Manual cache with remember pattern
$leaderboard = $cache->remember('leaderboard:top10', 120, function () use ($db) {
    return $db->query('SELECT * FROM `players` ORDER BY `level` DESC LIMIT 10');
});

// CRUD through cache (auto-invalidates related table caches)
$cache->insert('players', ['name' => 'Herobrine', 'level' => 99]);
$cache->update('players', 'level', 100, [['name' => 'Herobrine']]);
$cache->delete('players', [['name' => 'Herobrine']]);

// Manual cache operations
$cache->put('custom:key', $someData, ttl: 300);
$value = $cache->get('custom:key', default: null);
$cache->has('custom:key');
$cache->forget('custom:key');

// Table tagging for custom keys
$cache->tag('players', 'custom:players:stats');
$cache->invalidateTable('players'); // clears all tagged keys for this table

// Flush all cache entries
$cache->flush();

// View cache stats
$stats = $cache->stats(); // ['entries' => 5, 'tables' => 2]

// Access the underlying database
$rawDb = $cache->getDatabase();
```

---

### 8. Async Queries

```php
use imperazim\db\async\AsyncQuery;

// Async SQLite query
AsyncQuery::sqlite(
    $dataFolder . 'storage/mydb.db',
    'SELECT * FROM `players` WHERE `level` > ?',
    [10],
    onComplete: function (array $rows) {
        foreach ($rows as $row) {
            Server::getInstance()->getLogger()->info("Player: " . $row['name']);
        }
    },
    onError: function (\Throwable $e) {
        Server::getInstance()->getLogger()->error("Query failed: " . $e->getMessage());
    }
);

// Async MySQL query
AsyncQuery::mysql(
    ['host' => 'localhost', 'username' => 'root', 'password' => '', 'database' => 'mydb'],
    'INSERT INTO `logs` (`action`, `timestamp`) VALUES (?, ?)',
    ['player_join', date('Y-m-d H:i:s')],
    onComplete: fn(array $rows) => null,
    onError: fn(\Throwable $e) => Server::getInstance()->getLogger()->error($e->getMessage())
);
```

---

### 9. Transactions

```php
// SQLite transaction
$sqliteDb = new Sqlite3($dataFolder . 'storage', 'mydb');
$sqliteDb->transaction(function (Sqlite3 $db) {
    $db->insert('players', ['name' => 'Alice', 'level' => 10]);
    $db->insert('players', ['name' => 'Bob', 'level' => 15]);
    $db->update('players', 'coins', 100, [['name' => 'Alice']]);
});
// Automatically rolls back on any exception

// MySQL transaction
$mysqlDb = Mysql::connect('localhost', 'root', '', 'mydb');
$mysqlDb->transaction(function (Mysql $db) {
    $db->insert('transactions', ['player' => 'Alice', 'amount' => -50]);
    $db->insert('transactions', ['player' => 'Bob', 'amount' => 50]);
});
```

---

### 10. Upsert

```php
// SQLite: INSERT OR REPLACE
$sqliteDb->upsert('players', ['id' => 1, 'name' => 'Steve', 'level' => 20]);

// MySQL: INSERT ... ON DUPLICATE KEY UPDATE
$mysqlDb->upsert('players', ['id' => 1, 'name' => 'Steve', 'level' => 20]);
```

---

### 11. Error handling

```php
use imperazim\db\exception\DatabaseException;

try {
    $db = DBManager::connect('redis', []);
} catch (DatabaseException $e) {
    // "Unsupported database type: redis"
}

try {
    $db->delete('players', []);
} catch (DatabaseException $e) {
    // "Cannot delete without conditions. Use query() for raw DELETE."
}

try {
    $player = Player::findOrFail(999);
} catch (DatabaseException $e) {
    // "players with id = 999 not found."
}
```

---

## License

MIT. Pull requests and suggestions are welcome!

---

## Useful Links

- [PocketMine-MP](https://pmmp.io/)
- [EasyLibraryCore](https://github.com/ImperaZim/EasyLibraryCore)

---

Questions? Open an issue or contribute on GitHub!
