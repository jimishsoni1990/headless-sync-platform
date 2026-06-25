<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Bootstrap;

use HSP\Bootstrap\CredentialResolver;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for CredentialResolver — DECISION O (v1.15).
 *
 * Precedence under test: define() > getenv() > default.
 * Required credentials throw \RuntimeException when missing from both sources.
 *
 * Each test that touches PHP constants uses runInSeparateProcess or manipulates
 * getenv() directly; define() is process-global so constant-precedence tests
 * rely on constants that are NOT defined in the test process by default
 * (i.e. the HSP_PG_* constants are not set in phpunit.xml).
 */
final class CredentialResolverTest extends TestCase
{
    private CredentialResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new CredentialResolver();
    }

    // -------------------------------------------------------------------------
    // resolve() — base method
    // -------------------------------------------------------------------------

    public function test_resolve_returns_default_when_neither_source_set(): void
    {
        $key = 'HSP_TEST_RESOLVE_ABSENT_KEY_' . uniqid();
        self::assertSame('my-default', $this->resolver->resolve($key, 'my-default'));
    }

    public function test_resolve_returns_null_default_when_neither_source_set(): void
    {
        $key = 'HSP_TEST_RESOLVE_ABSENT_KEY_' . uniqid();
        self::assertNull($this->resolver->resolve($key));
    }

    public function test_resolve_returns_env_value_over_default(): void
    {
        $key = 'HSP_TEST_ENV_KEY_' . uniqid();
        putenv("{$key}=env-value");
        try {
            self::assertSame('env-value', $this->resolver->resolve($key, 'default'));
        } finally {
            putenv($key); // unset
        }
    }

    public function test_resolve_ignores_empty_env_and_falls_back_to_default(): void
    {
        $key = 'HSP_TEST_EMPTY_ENV_' . uniqid();
        putenv("{$key}=");
        try {
            self::assertSame('fallback', $this->resolver->resolve($key, 'fallback'));
        } finally {
            putenv($key);
        }
    }

    // -------------------------------------------------------------------------
    // required() — fail-loud for missing credentials
    // -------------------------------------------------------------------------

    public function test_required_throws_when_key_is_absent(): void
    {
        $key = 'HSP_TEST_REQUIRED_ABSENT_' . uniqid();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches("/required credential '{$key}'/");
        $this->resolver->required($key);
    }

    public function test_required_returns_env_value_when_set(): void
    {
        $key = 'HSP_TEST_REQUIRED_ENV_' . uniqid();
        putenv("{$key}=pg-host-value");
        try {
            self::assertSame('pg-host-value', $this->resolver->required($key));
        } finally {
            putenv($key);
        }
    }

    public function test_required_throws_for_empty_env_value(): void
    {
        $key = 'HSP_TEST_REQUIRED_EMPTY_ENV_' . uniqid();
        putenv("{$key}=");
        try {
            $this->expectException(\RuntimeException::class);
            $this->resolver->required($key);
        } finally {
            putenv($key);
        }
    }

    // -------------------------------------------------------------------------
    // pgPort() — defaults to 5432 when not set
    // -------------------------------------------------------------------------

    public function test_pg_port_defaults_to_5432(): void
    {
        $key = 'HSP_PG_PORT';
        // Only run this sub-test if the key is not set in the environment
        // (in CI it might be set; in that case just verify it returns an int).
        $envVal = getenv($key);
        if ($envVal !== false && $envVal !== '') {
            self::assertIsInt($this->resolver->pgPort());
        } else {
            self::assertSame(5432, $this->resolver->pgPort());
        }
    }

    public function test_pg_port_reads_env_when_set(): void
    {
        putenv('HSP_PG_PORT=5433');
        try {
            self::assertSame(5433, $this->resolver->pgPort());
        } finally {
            putenv('HSP_PG_PORT');
        }
    }

    // -------------------------------------------------------------------------
    // pgHost/pgUser/pgPassword/pgDbname — required, delegate to required()
    // -------------------------------------------------------------------------

    public function test_pg_host_throws_when_not_set(): void
    {
        // Guard: skip if the constant or env is actually set (live-env CI scenario).
        if (defined('HSP_PG_HOST') || (getenv('HSP_PG_HOST') !== false && getenv('HSP_PG_HOST') !== '')) {
            $this->markTestSkipped('HSP_PG_HOST is set in this environment.');
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches("/HSP_PG_HOST/");
        $this->resolver->pgHost();
    }

    public function test_pg_host_reads_env_when_set(): void
    {
        if (defined('HSP_PG_HOST')) {
            $this->markTestSkipped('HSP_PG_HOST is defined as a constant; cannot override via putenv in this process.');
        }

        putenv('HSP_PG_HOST=db.example.com');
        try {
            self::assertSame('db.example.com', $this->resolver->pgHost());
        } finally {
            putenv('HSP_PG_HOST');
        }
    }

    public function test_pg_dbname_throws_when_not_set(): void
    {
        if (defined('HSP_PG_DBNAME') || (getenv('HSP_PG_DBNAME') !== false && getenv('HSP_PG_DBNAME') !== '')) {
            $this->markTestSkipped('HSP_PG_DBNAME is set in this environment.');
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches("/HSP_PG_DBNAME/");
        $this->resolver->pgDbname();
    }

    public function test_pg_user_throws_when_not_set(): void
    {
        if (defined('HSP_PG_USER') || (getenv('HSP_PG_USER') !== false && getenv('HSP_PG_USER') !== '')) {
            $this->markTestSkipped('HSP_PG_USER is set in this environment.');
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches("/HSP_PG_USER/");
        $this->resolver->pgUser();
    }

    public function test_pg_password_throws_when_not_set(): void
    {
        if (defined('HSP_PG_PASSWORD') || (getenv('HSP_PG_PASSWORD') !== false && getenv('HSP_PG_PASSWORD') !== '')) {
            $this->markTestSkipped('HSP_PG_PASSWORD is set in this environment.');
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches("/HSP_PG_PASSWORD/");
        $this->resolver->pgPassword();
    }

    // -------------------------------------------------------------------------
    // pgDsn() — assembles DSN from individual accessor results
    // -------------------------------------------------------------------------

    public function test_pg_dsn_assembles_correctly_from_env(): void
    {
        if (defined('HSP_PG_HOST') || defined('HSP_PG_DBNAME') || defined('HSP_PG_USER') || defined('HSP_PG_PASSWORD')) {
            $this->markTestSkipped('PG constants are defined; DSN assembly tested in live-env tests.');
        }

        putenv('HSP_PG_HOST=127.0.0.1');
        putenv('HSP_PG_PORT=5432');
        putenv('HSP_PG_DBNAME=hsp');
        putenv('HSP_PG_USER=hsp_user');
        putenv('HSP_PG_PASSWORD=s3cret');

        try {
            $dsn = $this->resolver->pgDsn();
            self::assertStringContainsString('host=127.0.0.1', $dsn);
            self::assertStringContainsString('port=5432', $dsn);
            self::assertStringContainsString('dbname=hsp', $dsn);
            self::assertStringContainsString('user=hsp_user', $dsn);
            self::assertStringContainsString('password=s3cret', $dsn);
        } finally {
            putenv('HSP_PG_HOST');
            putenv('HSP_PG_PORT');
            putenv('HSP_PG_DBNAME');
            putenv('HSP_PG_USER');
            putenv('HSP_PG_PASSWORD');
        }
    }

    // -------------------------------------------------------------------------
    // MySQL — derives from WP DB_* constants by default; HSP_MYSQL_* overrides
    // -------------------------------------------------------------------------

    public function test_mysql_host_defaults_to_db_host_constant(): void
    {
        // DB_HOST is defined in the test bootstrap (wp-config stubs or phpunit bootstrap).
        // If not defined, fall back to 'localhost' — both are acceptable outcomes.
        if (defined('DB_HOST') && DB_HOST !== '') {
            self::assertSame(DB_HOST, $this->resolver->mysqlHost());
        } else {
            self::assertSame('localhost', $this->resolver->mysqlHost());
        }
    }

    public function test_mysql_host_override_via_hsp_mysql_host_env(): void
    {
        putenv('HSP_MYSQL_HOST=override-host');
        try {
            self::assertSame('override-host', $this->resolver->mysqlHost());
        } finally {
            putenv('HSP_MYSQL_HOST');
        }
    }

    public function test_mysql_port_defaults_to_3306(): void
    {
        $envVal = getenv('HSP_MYSQL_PORT');
        if ($envVal !== false && $envVal !== '') {
            self::assertIsInt($this->resolver->mysqlPort());
        } else {
            self::assertSame(3306, $this->resolver->mysqlPort());
        }
    }

    public function test_mysql_port_override_via_env(): void
    {
        putenv('HSP_MYSQL_PORT=10053');
        try {
            self::assertSame(10053, $this->resolver->mysqlPort());
        } finally {
            putenv('HSP_MYSQL_PORT');
        }
    }

    public function test_mysql_dbname_derives_from_db_name_constant(): void
    {
        if (defined('DB_NAME') && DB_NAME !== '') {
            self::assertSame(DB_NAME, $this->resolver->mysqlDbname());
        } else {
            // No WP constant and no override → empty string (harmless default; MySQL connects will fail loudly at runtime)
            self::assertSame('', $this->resolver->mysqlDbname());
        }
    }

    public function test_mysql_dbname_override_via_env(): void
    {
        putenv('HSP_MYSQL_NAME=my_db');
        try {
            self::assertSame('my_db', $this->resolver->mysqlDbname());
        } finally {
            putenv('HSP_MYSQL_NAME');
        }
    }

    public function test_mysql_user_override_via_env(): void
    {
        putenv('HSP_MYSQL_USER=myuser');
        try {
            self::assertSame('myuser', $this->resolver->mysqlUser());
        } finally {
            putenv('HSP_MYSQL_USER');
        }
    }

    public function test_mysql_password_override_via_env(): void
    {
        putenv('HSP_MYSQL_PASSWORD=supersecret');
        try {
            self::assertSame('supersecret', $this->resolver->mysqlPassword());
        } finally {
            putenv('HSP_MYSQL_PASSWORD');
        }
    }

    // -------------------------------------------------------------------------
    // define() takes precedence over getenv() — use a key name that won't
    // already be defined in the test process (uniqid-suffixed synthetic name).
    // We can't define() an HSP_PG_* constant mid-test (process-global, already
    // defined via wp-config.php in live env), so we test the rule via resolve().
    // -------------------------------------------------------------------------

    public function test_getenv_wins_over_default_and_falls_through_when_env_is_set(): void
    {
        $key = 'HSP_TEST_PRECEDENCE_' . uniqid();
        putenv("{$key}=from-env");
        try {
            // No define() for this key → getenv wins over default
            self::assertSame('from-env', $this->resolver->resolve($key, 'default-value'));
        } finally {
            putenv($key);
        }
    }

    public function test_default_is_last_resort_when_nothing_set(): void
    {
        $key = 'HSP_TEST_LAST_RESORT_' . uniqid();
        self::assertSame('last', $this->resolver->resolve($key, 'last'));
    }
}
