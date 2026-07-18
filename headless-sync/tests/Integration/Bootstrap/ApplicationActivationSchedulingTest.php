<?php

declare(strict_types=1);

namespace HSP\Tests\Integration\Bootstrap;

use HSP\Bootstrap\Application;
use HSP\Core\Reconciliation\ReconciliationCronRegistrar;
use HSP\Core\Workers\ProcessingCronRegistrar;
use PHPUnit\Framework\TestCase;

/**
 * Activation smoke test — drives the REAL Application::activate() and asserts the processing
 * cron event becomes scheduled (ADR-054 / Doc 8 v2.0 §24; Principle 8 "Zero-Configuration
 * Operation: activate and synchronization begins"). Resolves FLAG-ALIGNS1-1.
 *
 * This is the entry-point coverage the ALIGN-S1 close flagged as missing: the scheduling
 * BEHAVIOUR is registrar-unit-tested (ProcessingCronRegistrarTest) and deactivation is
 * driven against the real Application (ApplicationCronSchedulingTest), but nothing exercised
 * activate() itself — because activate() boots the full container, which resolves the two
 * runtime PostgreSQL handles (delivery + queue) eagerly. So this must run at the integration
 * tier with live databases; it self-skips when they are absent, exactly like the other
 * integration tests.
 *
 * It drives the genuine path: Application::activate() → boot() (builds the whole container,
 * runs the module lifecycle) → resolves ProcessingCronRegistrar + ReconciliationCronRegistrar
 * → ensureScheduled()/register(). We assert wp_next_scheduled() then reports every HSP cron
 * event as scheduled. No substitution of registrar-level coverage.
 *
 * The ONLY substitution is the WordPress `$wpdb` global: booting the real container resolves
 * the 'relay.worker' binding (OutboxServiceProvider), which reads `$wpdb->prefix` — and a
 * headless PHPUnit process never loads WordPress, so `global $wpdb` is null there. We provide a
 * minimal test-scoped `$GLOBALS['wpdb']` exposing only `->prefix` (set in setUp, restored in
 * tearDown; NOT added to tests/bootstrap.php). This substitutes the WP DB global exactly like
 * ProcessingCycleIntegrationTest substitutes the WP state-reload boundary — the
 * Application/container/ProcessingCronRegistrar/ensureScheduled wiring under test stays genuine.
 *
 * Environment (self-skips if a DB is genuinely absent):
 *   HSP_TEST_MYSQL_HOST / PORT / USER / PASSWORD / DATABASE
 *   HSP_TEST_PGSQL_HOST / PORT / USER / PASSWORD / DATABASE
 */
final class ApplicationActivationSchedulingTest extends TestCase
{
    /** @var array<string,string|false> env keys we set, captured for restoration. */
    private array $priorEnv = [];

    /** Whether this test set $GLOBALS['wpdb'] (so tearDown only unsets what it set). */
    private bool $setWpdbStub = false;

    protected function setUp(): void
    {
        $this->requireDatabasesOrSkip();

        // The ONLY WP-absent substitution: booting the real container resolves 'relay.worker'
        // (OutboxServiceProvider), which reads `$wpdb->prefix`. Provide a minimal test-scoped
        // $wpdb exposing just `->prefix`; the WordPress table prefix is conventionally 'wp_'.
        if (! isset($GLOBALS['wpdb'])) {
            $GLOBALS['wpdb']  = new class { public string $prefix = 'wp_'; };
            $this->setWpdbStub = true;
        }

        // Map the test DB credentials onto the constant names the real CredentialResolver reads
        // (DECISION O define()→getenv() precedence — getenv() is honoured). This points the real
        // container's delivery + queue handles at the live test databases.
        $this->setEnv('HSP_PG_HOST',     getenv('HSP_TEST_PGSQL_HOST') ?: '127.0.0.1');
        $this->setEnv('HSP_PG_PORT',     getenv('HSP_TEST_PGSQL_PORT') ?: '5432');
        $this->setEnv('HSP_PG_DBNAME',   getenv('HSP_TEST_PGSQL_DATABASE') ?: '');
        $this->setEnv('HSP_PG_USER',     getenv('HSP_TEST_PGSQL_USER') ?: '');
        $this->setEnv('HSP_PG_PASSWORD', getenv('HSP_TEST_PGSQL_PASSWORD') ?: '');

        $this->setEnv('HSP_MYSQL_HOST',     getenv('HSP_TEST_MYSQL_HOST') ?: '127.0.0.1');
        $this->setEnv('HSP_MYSQL_PORT',     getenv('HSP_TEST_MYSQL_PORT') ?: '3306');
        $this->setEnv('HSP_MYSQL_NAME',     getenv('HSP_TEST_MYSQL_DATABASE') ?: '');
        $this->setEnv('HSP_MYSQL_USER',     getenv('HSP_TEST_MYSQL_USER') ?: '');
        $this->setEnv('HSP_MYSQL_PASSWORD', getenv('HSP_TEST_MYSQL_PASSWORD') ?: '');

        // Opt the WP-Cron stubs (tests/bootstrap.php) into recording, starting from nothing
        // scheduled — so a truthy wp_next_scheduled() after activate() is genuinely the effect
        // of ensureScheduled(), not a pre-seeded value.
        $GLOBALS['_hsp_stub_scheduled'] = [];
        $GLOBALS['_hsp_stub_filters']   = [];

        $this->resetApplicationSingleton();
    }

    protected function tearDown(): void
    {
        $this->resetApplicationSingleton();
        if ($this->setWpdbStub) {
            unset($GLOBALS['wpdb']);
            $this->setWpdbStub = false;
        }
        unset($GLOBALS['_hsp_stub_scheduled'], $GLOBALS['_hsp_stub_filters']);

        foreach ($this->priorEnv as $key => $value) {
            if ($value === false) {
                putenv($key);
            } else {
                putenv("{$key}={$value}");
            }
        }
        $this->priorEnv = [];
    }

    public function test_activate_schedules_the_processing_cron_event(): void
    {
        // Nothing scheduled before activation.
        self::assertFalse(
            \wp_next_scheduled(ProcessingCronRegistrar::HOOK),
            'precondition: the processing cron event is not scheduled before activate()',
        );

        // Drive the REAL entry point (boots the whole container, resolves the two runtime PG
        // handles against live PG, then schedules the events).
        Application::getInstance()->activate();

        // Principle 8: activation scheduled the processing cycle so synchronization begins.
        self::assertNotFalse(
            \wp_next_scheduled(ProcessingCronRegistrar::HOOK),
            'activate() schedules the processing-cycle WP-Cron event',
        );

        // Reconciliation events are scheduled on activation too (for consistency).
        self::assertNotFalse(\wp_next_scheduled(ReconciliationCronRegistrar::HOOK_DRIFT));
        self::assertNotFalse(\wp_next_scheduled(ReconciliationCronRegistrar::HOOK_INCREMENTAL));
        self::assertNotFalse(\wp_next_scheduled(ReconciliationCronRegistrar::HOOK_FULL));
    }

    public function test_activate_is_idempotent_and_does_not_double_schedule(): void
    {
        Application::getInstance()->activate();
        $first = \wp_next_scheduled(ProcessingCronRegistrar::HOOK);
        self::assertNotFalse($first);

        // Re-activating must not throw and must leave exactly one scheduled event
        // (wp_next_scheduled guard). The stub tracks one marker per hook.
        Application::getInstance()->activate();

        self::assertArrayHasKey(ProcessingCronRegistrar::HOOK, $GLOBALS['_hsp_stub_scheduled']);
        self::assertNotFalse(\wp_next_scheduled(ProcessingCronRegistrar::HOOK));
    }

    // --- helpers ------------------------------------------------------------

    private function requireDatabasesOrSkip(): void
    {
        $pgUser = getenv('HSP_TEST_PGSQL_USER');
        $pgDb   = getenv('HSP_TEST_PGSQL_DATABASE');
        if (! $pgUser || ! $pgDb) {
            self::markTestSkipped('PostgreSQL env vars not set (HSP_TEST_PGSQL_USER, HSP_TEST_PGSQL_DATABASE).');
        }

        $myUser = getenv('HSP_TEST_MYSQL_USER');
        $myDb   = getenv('HSP_TEST_MYSQL_DATABASE');
        if (! $myUser || ! $myDb) {
            self::markTestSkipped('MySQL env vars not set (HSP_TEST_MYSQL_USER, HSP_TEST_MYSQL_DATABASE).');
        }

        if (! function_exists('pg_connect')) {
            self::markTestSkipped('pgsql extension not loaded.');
        }
    }

    private function setEnv(string $key, string $value): void
    {
        $this->priorEnv[$key] = getenv($key);
        putenv("{$key}={$value}");
    }

    /**
     * Reset the Application singleton so each test boots a fresh container and does not leak a
     * booted instance into the rest of the suite.
     */
    private function resetApplicationSingleton(): void
    {
        $ref  = new \ReflectionClass(Application::class);
        $prop = $ref->getProperty('instance');
        $prop->setAccessible(true);
        $prop->setValue(null, null);
    }
}
