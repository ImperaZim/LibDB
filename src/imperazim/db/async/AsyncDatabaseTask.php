<?php

declare(strict_types = 1);

namespace imperazim\db\async;

use Closure;
use RuntimeException;
use Throwable;
use pocketmine\scheduler\AsyncTask;

/**
* Internal AsyncTask for running database queries off the main thread.
* Uses SQL + params (serializable data) instead of closures for the async thread.
* Callbacks are stored via storeLocal() for main thread completion.
*/
final class AsyncDatabaseTask extends AsyncTask {

    private const TLS_CALLBACKS = 'callbacks';

    private string $driverType;
    private string $serializedConfig;
    private string $sql;
    private string $serializedParams;

    /**
    * @param string $driverType 'sqlite' or 'mysql'
    * @param array $config Connection config
    * @param string $sql SQL query with ? placeholders
    * @param array $params Bound parameters
    * @param Closure|null $onComplete fn(array $rows): void
    * @param Closure|null $onError fn(Throwable $error): void
    */
    public function __construct(
        string $driverType,
        array $config,
        string $sql,
        array $params = [],
        ?Closure $onComplete = null,
        ?Closure $onError = null
    ) {
        $this->driverType = $driverType;
        $this->serializedConfig = serialize($config);
        $this->sql = $sql;
        $this->serializedParams = serialize($params);

        $this->storeLocal(self::TLS_CALLBACKS, [
            'onComplete' => $onComplete,
            'onError' => $onError,
        ]);
    }

    public function onRun(): void {
        try {
            $config = unserialize($this->serializedConfig);
            $params = unserialize($this->serializedParams);

            $rows = match ($this->driverType) {
                'sqlite' => $this->runSqlite($config, $this->sql, $params),
                'mysql' => $this->runMysql($config, $this->sql, $params),
                default => throw new RuntimeException("Unsupported async driver: {$this->driverType}"),
            };

            $this->setResult(['success' => true, 'data' => serialize($rows)]);
        } catch (Throwable $e) {
            $this->setResult(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    public function onCompletion(): void {
        /** @var array{onComplete: ?Closure, onError: ?Closure} $callbacks */
        $callbacks = $this->fetchLocal(self::TLS_CALLBACKS);
        $result = $this->getResult();

        if ($result['success']) {
            $callbacks['onComplete']?->__invoke(unserialize($result['data']));
        } else {
            $callbacks['onError']?->__invoke(new RuntimeException($result['error']));
        }
    }

    private function runSqlite(array $config, string $sql, array $params): array {
        $pdo = new \PDO("sqlite:{$config['database']}", null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        ]);
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    private function runMysql(array $config, string $sql, array $params): array {
        $mysqli = new \mysqli($config['host'], $config['username'], $config['password'], $config['database']);
        if ($mysqli->connect_error) {
            throw new RuntimeException("MySQL connection failed: " . $mysqli->connect_error);
        }

        $stmt = $mysqli->prepare($sql);
        if ($stmt === false) {
            throw new RuntimeException("MySQL prepare failed: " . $mysqli->error);
        }

        if (!empty($params)) {
            $types = '';
            foreach ($params as $p) {
                $types .= match (true) {
                    is_int($p) => 'i',
                    is_float($p) => 'd',
                    default => 's',
                };
            }
            $stmt->bind_param($types, ...$params);
        }

        $stmt->execute();
        $result = $stmt->get_result();
        $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        $stmt->close();
        $mysqli->close();
        return $rows;
    }
}
