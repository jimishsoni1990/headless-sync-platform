# HSP — Progress Status

> **Standing instruction:** Update this file at the end of every working session: flip task states,
> set last-updated, set next action. This is the session-to-session source of progress truth.
>
> Rationale and architecture live in [`docs/IMPLEMENTATION_PLAN.md`](docs/IMPLEMENTATION_PLAN.md)
> and [`docs/ARCHITECTURE_DECISIONS.md`](docs/ARCHITECTURE_DECISIONS.md) — do not duplicate them here.

---

**Current phase:** Phase 0 — Foundation  
**Last updated:** 2026-06-22 (P0-S5 — DB queue provider)  
**Next session: P0-S6 — Worker engine + strategies + event/adapter registries**

---

## Session Checklist

### Phase 0 — Foundation

- [x] P0-S1 Bootstrap + DI container + configuration system
- [x] P0-S2 Migration engine
- [x] P0-S3 Module registry / discovery / lifecycle
- [x] P0-S4 Outbox capture + RelayWorkerStrategy
- [x] P0-S5 DB queue provider
- [ ] P0-S6 Worker engine + strategies + event/adapter registries
- [ ] P0-S7 Phase 0 DoD gate verification

### Phase 1A — Blog MVP

- [ ] P1A-S1 Content events + WP hook wiring + EventProvider
- [ ] P1A-S2 Extractors + source models + validators
- [ ] P1A-S3 Transformers + canonical models
- [ ] P1A-S4 Content migrations + PostgreSQL adapters
- [ ] P1A-S5 REST Delivery API
- [ ] P1A-S6 Next.js validation + end-to-end DoD

### Early Operational Baseline

- [ ] OPS-S1 Early Operational Baseline (DLQ inspect/replay, worker health, metrics)

---

## Architecture Validation Gate

- [ ] Reliability validation
- [ ] Scalability validation
- [ ] Operability validation
- [ ] Extensibility validation

Gate failure blocks Phase 2 and all subsequent phases.

---

## Flags

### FLAG-P0S1-1 — PSR-11 stubs bundled as local source files

**Raised:** 2026-06-22 | **Session:** P0-S1

P0-S1 scope forbids `require`/`require-dev` entries in `composer.json`. The Container
implements `Psr\Container\ContainerInterface` (PSR-11). To satisfy both constraints,
`Psr\Container` interfaces are bundled as three stub files under `core/Psr/Container/`,
mapped via an additional `psr-4` entry in `composer.json`.

**Impact:** When `psr/container` is added as a proper composer dependency in a later session,
the stub files and the `"Psr\\Container\\"` `psr-4` entry in `composer.json` must be removed
to avoid class redeclaration errors. This is a one-line composer require + delete of three
files + remove the composer.json psr-4 line.

**Resolution trigger:** This flag is resolved when the project introduces the official runtime
`psr/container` package via Composer. At that point: remove the temporary `core/Psr/Container/`
interfaces; remove the associated temporary PSR-4 mapping; refactor the Container to depend on
the official package; verify the platform boots and all tests pass. `require-dev` tooling
(e.g. phpunit) does NOT trip this trigger.

---

### FLAG-P0S3-1 — core/Module/ (singular) vs core/Modules/ (plural)

**Raised:** 2026-06-22 | **Session:** P0-S3

Doc 2 §10 uses `core/Modules/` (plural). IMPLEMENTATION_PLAN.md §5b P0-S3 and the session brief both specify `core/Module/` (singular). Per IMPLEMENTATION_PLAN.md §1, the session brief overrides Doc 2. Proceeded with `core/Module/` (singular) as specified in the authoritative session map.

**Resolution trigger:** If Doc 2 §10 is ever amended to match the session map, this flag can be closed. No code change needed — the ruling is already consistent with the operative authority.

---

### FLAG-P0S3-2 — phpunit/phpunit ^11.5 added to require-dev

**Raised:** 2026-06-22 | **Session:** P0-S3 | **Status:** Accepted

`phpunit/phpunit ^11.5` added to `require-dev` to run unit tests. Per the FLAG-P0S1-1 ruling (Intent), a dev-only tool does not trip the PSR-container resolution trigger. No runtime impact.

---

### FLAG-P0S4-1 — `'relaying'` intermediate outbox status not in frozen DDL ENUM

**Raised:** 2026-06-22 | **Session:** P0-S4 | **Status:** Resolved — P0-S4 follow-up (2026-06-22)

**Resolution:** Redesigned by removing the intermediate status entirely. `RelayWorkerStrategy` now holds the MySQL `FOR UPDATE` row lock for the entire batch duration: `BEGIN` → SELECT FOR UPDATE SKIP LOCKED → (PG insert + MySQL mark-`'relayed'` per row) → `COMMIT`. The row lock is the claim guard; no status transition to `'relaying'` is needed. OPEN-6 v1.3 frozen `ENUM('pending','relayed')` DDL is correct as-is — no migration change. 91/91 unit tests pass; a negative-assertion test (`test_tick_does_not_use_relaying_intermediate_status`) confirms `'relaying'` never appears in emitted SQL.

---

### FLAG-P0S4-2 — Integration tests self-skipped; live-DB DoD items unproven

**Raised:** 2026-06-22 | **Session:** P0-S4 follow-up | **Status:** Fully resolved — 2026-06-22

**Counter test (item 1): RESOLVED.** `ConcurrentAggregateVersionTest` ran against live MySQL (localhost:10053, db `local`) and passed 3/3. Bug found and fixed: `VALUES (%s, %s, 1)` → `VALUES (%s, %s, LAST_INSERT_ID(1))` so `LAST_INSERT_ID()` returns `1` on first insert.

**Relay end-to-end test (item 2): RESOLVED.** `RelayEndToEndTest` written and passed 5/5 against live MySQL (localhost:10053) + live PostgreSQL (Docker 127.0.0.1:5432, headless-sync-platform-postgres). All four P0-S4 DoD items proven: (1) happy-path relay, (2) idempotent re-relay via ON CONFLICT DO NOTHING, (3) crash-safety — PG row survives MySQL rollback, recovery tick produces no duplicate, (4) SKIP LOCKED concurrency — Worker B finds zero rows while Worker A holds locks. Full suite: 99/99 tests pass.

---

### FLAG-P0S4-3 — `created_at` UTC fidelity on relay: binding and assertion both weak

**Raised:** 2026-06-22 | **Session:** P0-S4 close | **Status:** Open — resolve by P0-S7 gate

`RelayWorkerStrategy` binds `created_at` from the outbox row as a plain string (MySQL `DATETIME`, no timezone). PostgreSQL stores it as `TIMESTAMPTZ` but interprets a bare datetime string in the session timezone, which may not be UTC. The relay should cast or suffix the value with `AT TIME ZONE 'UTC'` (or bind via an explicit UTC-suffixed string) to guarantee the stored TIMESTAMPTZ reflects capture time in UTC.

Additionally, `RelayEndToEndTest::test_pending_row_is_relayed_and_marked_relayed` asserts only that the PG `created_at` value contains today's date substring (`assertStringContainsString(gmdate('Y-m-d'), ...)`). This does not verify UTC offset is preserved.

**Resolution by P0-S7:** (1) Verify (or fix) that `MysqliOutboxConnection`/`RelayWorkerStrategy` appends `+00` or binds `AT TIME ZONE 'UTC'` when inserting `created_at` into `system.events`. (2) Strengthen the assertion to compare the full UTC datetime, not just the date substring.

---

## Session Log

<!-- Append one line per session: YYYY-MM-DD | session ID | what shipped | flags raised -->
2026-06-22 | P0-S1 | Shipped: headless-sync.php, bootstrap/ (Application, Bootstrapper, Environment, Constants, Version), config/ (7 skeletons), core/Container/ (Container, ContainerBuilder, ServiceRegistry, ServiceProvider, Definitions/CoreServiceProvider), core/Configuration/ConfigLoader, core/Psr/Container/ (PSR-11 stubs), composer.json (autoload only), vendor/autoload.php generated. Config hierarchy (Global→Module→Env), PSR-11 container, and ADR-012 constructor injection all verified via smoke tests. | FLAG-P0S1-1: PSR-11 stubs bundled locally (see flags section below)
2026-06-22 | housekeeping | Monorepo restructure (DECISION G v1.5): all plugin files moved into headless-sync/; CLAUDE.md, STATUS.md, docs/ remain at root; composer.json PSR-4 fixed to explicit per-prefix maps (FLAG-P0S1-2 resolved); vendor autoload stubs regenerated; workspace-root .gitignore added; git repo initialized, remote wired, committed. Push blocked: SSH key not on this machine (FLAG-MONOREPO-SSH).
2026-06-22 | P0-S2 | Shipped: core/Migrations/ engine (MigrationRunner with UUIDv7 per ADR-015, AbstractSqlMigration, MigrationRecord, ConnectionInterface + WpdbMysqlConnection + PgsqlConnection + ConnectionFactory, MigrationException); 12 concrete migration classes (2 MySQL, 10 PgSQL) in database/Core/; MigrationServiceProvider wired into ContainerBuilder; phpunit.xml; tests/Unit/Migrations/ (MigrationRunnerTest, AbstractSqlMigrationTest, FakeConnection, FakeMigration); composer.json + vendor stubs updated (HSP\Database\, HSP\Tests\ namespaces). Review corrections applied: UUIDv7 replaces UUIDv4, bootstrap() single-sourced to 0008 SQL file (no inline DDL copy), CHAR(64) confirmed correct per OPEN-6 v1.3 for MySQL only, numeric-prefix ordering guard test added, checksum prefix-stability tests added, idempotency tests added. All DoD Gates 1–6 verified and approved. | No new flags.
2026-06-22 | P0-S3 | Shipped: core/Module/ (ModuleManifest, ModuleDiscovery, ModuleLoader, ModuleRegistry, ModuleRegistrar, Exception/InvalidManifestException), core/Contracts/ModuleInterface.php (OPEN-9 union shape), core/Container/Definitions/ModuleServiceProvider.php, modules/Content/module.json fixture, tests/Unit/Module/ (35 tests). 57/57 unit tests pass. Two-phase register-then-boot ordering verified across modules. | Flags: FLAG-P0S3-1 (core/Module singular, session map wins — no action); FLAG-P0S3-2 (phpunit ^11.5 require-dev, Accepted); BOM fix in MigrationRunner.php (P0-S2 file, benign).
2026-06-22 | housekeeping | Committed P0-S2+P0-S3 (close ritual had been skipped; tree was dirty). SSH verified; pushed to origin/main (608fb27). FLAG-MONOREPO-SSH resolved.
2026-06-22 | P0-S4 | Shipped: core/Contracts/ (OutboxWriterInterface, AggregateVersionCounterInterface), core/Events/Outbox/ (OutboxEvent, OutboxWriter, AggregateVersionCounter, Exception/OutboxWriteException, Connection/OutboxConnectionInterface + MysqliOutboxConnection + PgsqlOutboxConnection), core/Workers/Strategies/RelayWorkerStrategy, core/Container/Definitions/OutboxServiceProvider (wired into ContainerBuilder), tests/bootstrap.php (wpdb stub), tests/Unit/Events/Outbox/ (FakeWpdb, FakeOutboxConnection, AggregateVersionCounterTest ×5, OutboxWriterTest ×8, RelayWorkerStrategyTest ×21), tests/Integration/Events/Outbox/ (ConcurrentAggregateVersionTest ×3 live MySQL, RelayEndToEndTest ×5 live MySQL + live PG). Bugs fixed: bare VALUES(1) → LAST_INSERT_ID(1) in AggregateVersionCounter; \wpdb type hint → object for structural test compatibility; bind_param type-string mismatch in test setup. All four P0-S4 DoD items proved against live DBs: happy-path relay, idempotent re-relay (ON CONFLICT DO NOTHING), crash-safety (CommitSaboteurMysqlConnection — PG row survives MySQL rollback, recovery tick produces no duplicate), SKIP LOCKED concurrency. RelayWorkerStrategy redesigned mid-session: removed 'relaying' intermediate status; MySQL FOR UPDATE lock spans entire batch (BEGIN→SELECT SKIP LOCKED→PG insert+mark-relayed→COMMIT). Full suite: 99/99 tests pass (91 unit + 8 integration). Reviewer approved. | FLAG-P0S4-1: resolved by redesign (no DDL change). FLAG-P0S4-2: resolved (live-DB integration tests pass). FLAG-P0S4-3: open — created_at UTC fidelity on relay binding and assertion; resolve by P0-S7 gate.
2026-06-22 | P0-S5 | Shipped: core/Queue/Exception/QueueException, core/Queue/Providers/Database/ (QueueConnectionInterface, DatabaseQueueConnection, DatabaseQueueProvider), core/Container/Definitions/QueueServiceProvider (wired into ContainerBuilder), tests/Unit/Queue/ (FakeQueueConnection, FakeEvent, DatabaseQueueProviderTest ×37), tests/Integration/Queue/DatabaseQueueProviderIntegrationTest ×9 (live PG). Bug found and fixed mid-session: DatabaseQueueConnection was final — extracted QueueConnectionInterface so DatabaseQueueProvider is testable without a real PG handle. Second bug: pg_connect() pooling caused SKIP LOCKED test to use the same connection for both locker and claimant — fixed with PGSQL_CONNECT_FORCE_NEW on all provider connections. All five P0-S5 DoD items proved: SKIP LOCKED concurrency (providerB finds null while lockConn holds lock), visibility-timeout recovery (requeueTimedOut revives backdated jobs), idempotent requeue race (two concurrent calls requeue exactly 1 row), retry-limit → dead-letter with payload_snapshot NOT NULL (DECISION A coercion for null/invalid/array/string payloads), partition isolation (commerce claim ignores content jobs). Full suite: 144/144 tests pass (127 unit + 9 PG integration + 8 pre-existing relay integration). | No new flags.

