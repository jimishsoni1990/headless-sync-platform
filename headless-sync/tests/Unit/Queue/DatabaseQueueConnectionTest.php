<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Queue;

use HSP\Core\Database\Exception\DatabaseException;
use HSP\Core\Database\PostgresDatabaseConnection;
use HSP\Core\Queue\Exception\QueueException;
use HSP\Core\Queue\Providers\Database\DatabaseQueueConnection;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for DatabaseQueueConnection exception-boundary translation.
 *
 * DECISION E (v1.5) requires that DatabaseException from the shared layer is
 * translated to QueueException at the queue boundary so consumers never
 * observe raw DatabaseException.
 *
 * Uses a fake subclass of PostgresDatabaseConnection to inject DatabaseException
 * failures without a live database connection.
 */
final class DatabaseQueueConnectionTest extends TestCase
{
    private DatabaseQueueConnection $conn;

    protected function setUp(): void
    {
        $this->conn = new DatabaseQueueConnection(new FailingPostgresDatabaseConnection());
    }

    public function test_execute_translates_database_exception_to_queue_exception(): void
    {
        $this->expectException(QueueException::class);
        $this->conn->execute('SELECT 1');
    }

    public function test_execute_wraps_exception_as_queue_not_database_exception(): void
    {
        $caught = null;
        try {
            $this->conn->execute('SELECT 1');
        } catch (\Throwable $e) {
            $caught = $e;
        }
        self::assertInstanceOf(QueueException::class, $caught,
            'execute() must throw QueueException, not raw DatabaseException — DECISION E');
        self::assertNotInstanceOf(DatabaseException::class, $caught,
            'DatabaseException must not escape the queue boundary — DECISION E');
    }

    public function test_query_translates_database_exception_to_queue_exception(): void
    {
        $this->expectException(QueueException::class);
        $this->conn->query('SELECT 1');
    }

    public function test_begin_transaction_translates_database_exception(): void
    {
        $this->expectException(QueueException::class);
        $this->conn->beginTransaction();
    }

    public function test_commit_translates_database_exception(): void
    {
        $this->expectException(QueueException::class);
        $this->conn->commit();
    }

    public function test_rollback_does_not_throw(): void
    {
        // rollback() must never throw — silent failure is intentional.
        $this->conn->rollback();
        $this->addToAssertionCount(1);
    }

    public function test_invalid_handle_translates_to_queue_exception(): void
    {
        $this->expectException(QueueException::class);
        // Passing a non-handle value triggers DatabaseException in PostgresDatabaseConnection
        // constructor; DatabaseQueueConnection must translate it.
        new DatabaseQueueConnection('not-a-connection-handle');
    }

    public function test_invalid_handle_wraps_exception_as_queue_not_database_exception(): void
    {
        $caught = null;
        try {
            new DatabaseQueueConnection('not-a-connection-handle');
        } catch (\Throwable $e) {
            $caught = $e;
        }
        self::assertInstanceOf(QueueException::class, $caught,
            'Constructor must throw QueueException, not raw DatabaseException — DECISION E');
        self::assertNotInstanceOf(DatabaseException::class, $caught,
            'DatabaseException must not escape the queue boundary — DECISION E');
    }
}

/**
 * Fake PostgresDatabaseConnection that always throws DatabaseException.
 * Allows boundary-translation tests to run without a live pg_connect handle.
 */
final class FailingPostgresDatabaseConnection extends PostgresDatabaseConnection
{
    public function __construct()
    {
        // Skip parent constructor — no real connection needed.
    }

    public function execute(string $sql, array $params = []): int
    {
        throw new DatabaseException('Simulated execute failure');
    }

    public function query(string $sql, array $params = []): array
    {
        throw new DatabaseException('Simulated query failure');
    }

    public function beginTransaction(): void
    {
        throw new DatabaseException('Simulated BEGIN failure');
    }

    public function commit(): void
    {
        throw new DatabaseException('Simulated COMMIT failure');
    }

    public function rollback(): void
    {
        // Silent — matches PostgresDatabaseConnection::rollback() behaviour.
    }
}
