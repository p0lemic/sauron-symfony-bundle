<?php

declare(strict_types=1);

namespace Sauron\Bundle\Doctrine;

use Doctrine\DBAL\Driver as DriverInterface;
use Doctrine\DBAL\Driver\Middleware as MiddlewareInterface;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractStatementMiddleware;
use Sauron\Bundle\SauronClient;

/**
 * Doctrine DBAL middleware that creates a "db" span for every SQL query.
 *
 * The middleware reads the active trace context from SauronClient, which is
 * set by TraceSubscriber on kernel.controller. Queries executed outside of a
 * traced request are silently ignored.
 *
 * Auto-registered via the `doctrine.middleware` tag when
 * sauron.instrument_doctrine is true.
 */
final class SauronMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly SauronClient $client) {}

    public function wrap(DriverInterface $driver): DriverInterface
    {
        return new class($driver, $this->client) extends AbstractDriverMiddleware {
            public function __construct(DriverInterface $driver, private readonly SauronClient $client)
            {
                parent::__construct($driver);
            }

            public function connect(array $params): \Doctrine\DBAL\Driver\Connection
            {
                return new SauronConnection(parent::connect($params), $this->client);
            }
        };
    }
}

/**
 * Connection wrapper that intercepts query/exec and wraps prepared statements.
 *
 * @internal
 */
final class SauronConnection extends AbstractConnectionMiddleware
{
    public function __construct(
        \Doctrine\DBAL\Driver\Connection $connection,
        private readonly SauronClient $client,
    ) {
        parent::__construct($connection);
    }

    public function prepare(string $sql): \Doctrine\DBAL\Driver\Statement
    {
        return new SauronStatement(parent::prepare($sql), $this->client, $sql);
    }

    public function query(string $sql): \Doctrine\DBAL\Driver\Result
    {
        return $this->track($sql, fn () => parent::query($sql));
    }

    public function exec(string $sql): int|string
    {
        return $this->track($sql, fn () => parent::exec($sql));
    }

    /**
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    private function track(string $sql, callable $fn): mixed
    {
        if (!$this->client->hasActiveTrace()) {
            return $fn();
        }

        $start = microtime(true);
        $status = 'ok';
        try {
            return $fn();
        } catch (\Throwable $e) {
            $status = 'error';
            throw $e;
        } finally {
            $this->record($sql, $start, $status);
        }
    }

    private function record(string $sql, float $start, string $status): void
    {
        $durationMs = (microtime(true) - $start) * 1000;
        $startTime  = self::floatToDateTime($start);

        $this->client->recordSpan(
            name:       self::queryLabel($sql),
            kind:       'db',
            startTime:  $startTime,
            durationMs: $durationMs,
            attributes: ['db.query' => self::normalize($sql)],
            status:     $status,
        );
    }

    // ── SQL helpers ───────────────────────────────────────────────────────────

    /**
     * Return a short readable label: "SELECT users", "INSERT orders", etc.
     */
    public static function queryLabel(string $sql): string
    {
        $sql   = ltrim($sql);
        $verb  = strtoupper(strtok($sql, " \t\n\r") ?: 'QUERY');
        $table = '';

        // Try to extract the main table name.
        $patterns = [
            '/\bFROM\s+["`]?(\w+)/i',
            '/\bINTO\s+["`]?(\w+)/i',
            '/\bUPDATE\s+["`]?(\w+)/i',
            '/\bTABLE\s+["`]?(\w+)/i',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $sql, $m)) {
                $table = $m[1];
                break;
            }
        }

        return $table !== '' ? "{$verb} {$table}" : $verb;
    }

    /**
     * Collapse literal values in SQL to make it safe for storage.
     * Replaces string literals, numbers and IN-lists with placeholders.
     */
    public static function normalize(string $sql): string
    {
        // Replace quoted strings
        $sql = preg_replace("/('[^']*'|\"[^\"]*\")/", "'?'", $sql) ?? $sql;
        // Replace numeric literals
        $sql = preg_replace('/\b\d+\b/', '?', $sql) ?? $sql;
        // Collapse whitespace
        return preg_replace('/\s+/', ' ', trim($sql)) ?? $sql;
    }

    private static function floatToDateTime(float $microtime): \DateTimeImmutable
    {
        $dt = \DateTimeImmutable::createFromFormat('U.u', number_format($microtime, 6, '.', ''));
        return $dt !== false ? $dt : new \DateTimeImmutable();
    }
}

/**
 * Statement wrapper that tracks execute() calls.
 *
 * @internal
 */
final class SauronStatement extends AbstractStatementMiddleware
{
    public function __construct(
        \Doctrine\DBAL\Driver\Statement $statement,
        private readonly SauronClient $client,
        private readonly string $sql,
    ) {
        parent::__construct($statement);
    }

    public function execute(): \Doctrine\DBAL\Driver\Result
    {
        if (!$this->client->hasActiveTrace()) {
            return parent::execute();
        }

        $start  = microtime(true);
        $status = 'ok';
        try {
            return parent::execute();
        } catch (\Throwable $e) {
            $status = 'error';
            throw $e;
        } finally {
            $durationMs = (microtime(true) - $start) * 1000;
            $startTime  = \DateTimeImmutable::createFromFormat(
                'U.u', number_format($start, 6, '.', '')
            ) ?: new \DateTimeImmutable();

            $this->client->recordSpan(
                name:       SauronConnection::queryLabel($this->sql),
                kind:       'db',
                startTime:  $startTime,
                durationMs: $durationMs,
                attributes: ['db.query' => SauronConnection::normalize($this->sql)],
                status:     $status,
            );
        }
    }
}
