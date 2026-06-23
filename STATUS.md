# HSP — Progress Status

> **Standing instruction:** Update this file at the end of every working session: flip task states,
> set last-updated, set next action. This is the session-to-session source of progress truth.
>
> Rationale and architecture live in [`docs/IMPLEMENTATION_PLAN.md`](docs/IMPLEMENTATION_PLAN.md)
> and [`docs/ARCHITECTURE_DECISIONS.md`](docs/ARCHITECTURE_DECISIONS.md) — do not duplicate them here.

---

**Current phase:** Phase 1A — Blog MVP  
**Last updated:** 2026-06-23  
**Next session: P1A-S5 — REST Delivery API**

---

## Session Checklist

### Phase 0 — Foundation

- [x] P0-S1 Bootstrap + DI container + configuration system
- [x] P0-S2 Migration engine
- [x] P0-S3 Module registry / discovery / lifecycle
- [x] P0-S4 Outbox capture + RelayWorkerStrategy
- [x] P0-S5 DB queue provider
- [x] P0-S6 Worker engine + strategies + event/adapter registries
- [x] P0-S7 Phase 0 DoD gate verification

### Phase 1A — Blog MVP

- [x] P1A-S1 Content events + WP hook wiring + EventProvider
- [x] P1A-S2 Extractors + source models + validators
- [x] P1A-S3 Transformers + canonical models
- [x] P1A-S4 Content migrations + PostgreSQL adapters
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

**Raised:** 2026-06-22 | **Session:** P0-S4 close | **Status:** Resolved — P0-S7 (2026-06-22)

**Resolution:** (1) `RelayWorkerStrategy::insertIntoSystemEvents()` already appended `'+00:00'` to both `source_updated_at` and `created_at` bindings (`$row['created_at'] . '+00:00'`), cast via `$12::timestamptz`. PostgreSQL interprets the `+00:00` suffix as UTC, guaranteeing the stored TIMESTAMPTZ reflects capture time in UTC regardless of session timezone. (2) `RelayEndToEndTest::test_pending_row_is_relayed_and_marked_relayed` strengthened: now asserts the full captured UTC datetime string (`assertStringContainsString($captureUtc, ...)`) AND that the PG value ends with an explicit UTC offset (`assertMatchesRegularExpression('/\+00(:00)?$/', ...)`). The `insertOutboxRow()` helper gains an optional `captureAt` parameter so the test can pin the timestamp before insertion.

---

### FLAG-P0S5-1 — Three structurally identical pg_* connection wrappers

**Raised:** 2026-06-22 | **Session:** P0-S5 | **Status:** Resolved — DECISION E (v1.5, 2026-06-22)

**Resolution:** DECISION E in `docs/ARCHITECTURE_DECISIONS.md` v1.5 rules that runtime DML subsystems
share a single PostgreSQL connection abstraction, with consolidation deferred to P0-S7. The three
existing wrappers are accepted temporary duplication. P0-S6 binding constraint: no new raw `pg_*`
wrapper may be introduced. P0-S7 authorised scope: collapse `OutboxConnectionInterface` and
`QueueConnectionInterface` into a shared `DatabaseConnectionInterface` under `core/Database/`.

---

### FLAG-P0S7-1 — DECISION E collapse interpretation: marker-interface vs full split

**Raised:** 2026-06-23 | **Session:** P0-S7 | **Status:** Resolved — DECISION E v1.6 (2026-06-23)

**Resolution (architect's ruling, DECISION E v1.6 — Split):**
- QUEUE: collapsed fully — `QueueConnectionInterface` deleted; `DatabaseQueueConnection` and `DatabaseQueueProvider` now depend on `DatabaseConnectionInterface` directly.
- OUTBOX: split by persistence technology — `PgsqlOutboxConnection` implements `DatabaseConnectionInterface` (PG delivery path); `MysqliOutboxConnection` implements new `MysqlOutboxConnectionInterface` (MySQL capture path). `OutboxConnectionInterface` deleted. The two contracts share no inheritance; `DatabaseConnectionInterface` is PostgreSQL-only.
- `RelayWorkerStrategy` holds one `MysqlOutboxConnectionInterface` + one `DatabaseConnectionInterface`, treating them as explicitly distinct abstractions.
- Rollback swallow semantics preserved: `PostgresDatabaseConnection::rollback()` matches historical behaviour from git commit 084456a — false return from `pg_query('ROLLBACK')` silently discarded, no exception thrown.
- All split fakes, service providers, integration tests, and the `FakeQueueConnection` updated to match.
- 204 unit + 18 integration tests pass; 0 skipped.

---

### FLAG-P1AS1-1 — Unpublish transition (publish→draft/pending/private/future) emits zero events

**Raised:** 2026-06-23 | **Session:** P1A-S1 review | **Status:** Resolved — OPEN-10 ruling applied, P1A-S1 close (2026-06-23)

**Resolution:** OPEN-10 (Resolved) — Option A approved. Membership-based capture: public set = `{publish}` only. `HookWiring::onTransitionPostStatus` updated with `$wasPublic`/`$isPublic` booleans; all four exit transitions (`publish → draft/pending/private/future`) now emit `content.{type}.deleted`. `wp_trash_post` suppressed by `$handledByTransition` guard when `transition_post_status` already handled the post_id (double-emit prevention). All nine new OPEN-10 tests pass. Full suite: 363 tests, 0 failures.

---

### FLAG-P1AS3-1 — `CanonicalModelInterface::getChecksum()` scope and DECISION 3 write-suppress compatibility

**Raised:** 2026-06-23 | **Session:** P1A-S3 | **Status:** Resolved — OPEN-11 (2026-06-23)

`CanonicalModelInterface::getChecksum()` doc-comment states: *"sha256 checksum of the canonical representation; used for write-suppress comparison against the stored projection checksum — DECISION 3."* DECISION 3 requires write-suppress to compare a **freshly-computed projection checksum** against the stored `content.*` checksum.

These two are compatible **only if** the canonical model and the PostgreSQL projection are a lossless reshape of the same fields — i.e. the adapter stores exactly what the canonical model contains and nothing else contributes to the stored checksum. If the adapter projection adds, drops, or transforms any field relative to the canonical model (e.g. computes a `uri` column from `slug`, or omits `meta` from the stored checksum), then `canonical.getChecksum()` will diverge from a write-side recomputed projection checksum, and write-suppress will either falsely skip writes or falsely execute them on every sync.

**P1A-S4 entry condition:** Before wiring write-suppress in the adapter, the architect must rule on one of:
- **Option A** — The adapter uses `canonical.getChecksum()` directly as the stored checksum. The projection schema must be a lossless reshape of every canonical field. No adapter-side field additions or omissions contribute to the stored checksum.
- **Option B** — The adapter computes a separate projection-shaped checksum over only the columns it writes to `content.*`. The stored checksum diverges from `canonical.getChecksum()`. `CanonicalModelInterface::getChecksum()` becomes unused or repurposed.

**Resolution:** Option A approved (OPEN-11, 2026-06-23). The Phase 1A delivery projection is a lossless reshape of the canonical model: no canonical field omitted, no derived columns added (precomputed URI variants, search vectors, denormalized aggregates, and analytics/ranking columns are explicitly excluded from Phase 1A). The adapter persists `canonical.getChecksum()` directly as the stored `content.*` checksum. Write-suppression compares the stored checksum against the canonical checksum — no second projection-shaped checksum path exists in Phase 1A. When a future projection intentionally diverges, it must compute and persist its own projection checksum, and that divergence requires a future ADR before implementation.

---

### FLAG-P1AS4-1 — content.entity_taxonomies column shape

**Raised:** 2026-06-23 | **Session:** P1A-S4 | **Status:** Resolved — ARCHITECTURE_DECISIONS.md v1.8 (2026-06-23)

**Resolution:** Pure join table. `(entity_id UUID, taxonomy_id UUID)` composite PK only. No timestamps, checksums, or metadata unless a future ADR explicitly requires relationship attributes. Migration `0005_create_content_entity_taxonomies.sql` frozen accordingly.

---

### FLAG-P1AS4-2 — aggregate_versions upsert monotonicity

**Raised:** 2026-06-23 | **Session:** P1A-S4 | **Status:** Resolved — ARCHITECTURE_DECISIONS.md v1.8 (2026-06-23)

**Resolution:** Monotonic guard adopted. The `system.aggregate_versions` upsert in all three adapters uses `GREATEST()` so `latest_processed_version` only ever advances. Worker Resolve step remains the primary stale-event guard; the database GREATEST() guard provides defense-in-depth. Integration test `test_aggregate_versions_never_regresses_on_out_of_order_delivery` confirms the guard holds against live PostgreSQL.

---

### FLAG-P1AS4-3 — `bulkPersist()` version guard and event recording

**Raised:** 2026-06-23 | **Session:** P1A-S4 close | **Status:** Resolved — architect ruling 2026-06-23

**Resolution (architect, 2026-06-23):** Option B approved. `persist()` is the ONLY supported persistence entry point in Phase 1A. `bulkPersist()` stays on `AdapterInterface` (signature unchanged) but performs NO projection writes in Phase 1A. All three adapters implement it as `throw new \LogicException('bulkPersist() is not implemented in Phase 1A.');` — no transaction, no execute, no partial path. The correct guarded batch path (events + version context, same guarantees as `persist()`) is deferred to a future ADR that lands with the first batch-with-events caller. Recorded in `docs/ARCHITECTURE_DECISIONS.md` under FLAG-P1AS4-3.

---

## Session Log

<!-- Append one line per session: YYYY-MM-DD | session ID | what shipped | flags raised -->
2026-06-22 | P0-S1 | Shipped: headless-sync.php, bootstrap/ (Application, Bootstrapper, Environment, Constants, Version), config/ (7 skeletons), core/Container/ (Container, ContainerBuilder, ServiceRegistry, ServiceProvider, Definitions/CoreServiceProvider), core/Configuration/ConfigLoader, core/Psr/Container/ (PSR-11 stubs), composer.json (autoload only), vendor/autoload.php generated. Config hierarchy (Global→Module→Env), PSR-11 container, and ADR-012 constructor injection all verified via smoke tests. | FLAG-P0S1-1: PSR-11 stubs bundled locally (see flags section below)
2026-06-22 | housekeeping | Monorepo restructure (DECISION G v1.5): all plugin files moved into headless-sync/; CLAUDE.md, STATUS.md, docs/ remain at root; composer.json PSR-4 fixed to explicit per-prefix maps (FLAG-P0S1-2 resolved); vendor autoload stubs regenerated; workspace-root .gitignore added; git repo initialized, remote wired, committed. Push blocked: SSH key not on this machine (FLAG-MONOREPO-SSH).
2026-06-22 | P0-S2 | Shipped: core/Migrations/ engine (MigrationRunner with UUIDv7 per ADR-015, AbstractSqlMigration, MigrationRecord, ConnectionInterface + WpdbMysqlConnection + PgsqlConnection + ConnectionFactory, MigrationException); 12 concrete migration classes (2 MySQL, 10 PgSQL) in database/Core/; MigrationServiceProvider wired into ContainerBuilder; phpunit.xml; tests/Unit/Migrations/ (MigrationRunnerTest, AbstractSqlMigrationTest, FakeConnection, FakeMigration); composer.json + vendor stubs updated (HSP\Database\, HSP\Tests\ namespaces). Review corrections applied: UUIDv7 replaces UUIDv4, bootstrap() single-sourced to 0008 SQL file (no inline DDL copy), CHAR(64) confirmed correct per OPEN-6 v1.3 for MySQL only, numeric-prefix ordering guard test added, checksum prefix-stability tests added, idempotency tests added. All DoD Gates 1–6 verified and approved. | No new flags.
2026-06-22 | P0-S3 | Shipped: core/Module/ (ModuleManifest, ModuleDiscovery, ModuleLoader, ModuleRegistry, ModuleRegistrar, Exception/InvalidManifestException), core/Contracts/ModuleInterface.php (OPEN-9 union shape), core/Container/Definitions/ModuleServiceProvider.php, modules/Content/module.json fixture, tests/Unit/Module/ (35 tests). 57/57 unit tests pass. Two-phase register-then-boot ordering verified across modules. | Flags: FLAG-P0S3-1 (core/Module singular, session map wins — no action); FLAG-P0S3-2 (phpunit ^11.5 require-dev, Accepted); BOM fix in MigrationRunner.php (P0-S2 file, benign).
2026-06-22 | housekeeping | Committed P0-S2+P0-S3 (close ritual had been skipped; tree was dirty). SSH verified; pushed to origin/main (608fb27). FLAG-MONOREPO-SSH resolved.
2026-06-22 | P0-S4 | Shipped: core/Contracts/ (OutboxWriterInterface, AggregateVersionCounterInterface), core/Events/Outbox/ (OutboxEvent, OutboxWriter, AggregateVersionCounter, Exception/OutboxWriteException, Connection/OutboxConnectionInterface + MysqliOutboxConnection + PgsqlOutboxConnection), core/Workers/Strategies/RelayWorkerStrategy, core/Container/Definitions/OutboxServiceProvider (wired into ContainerBuilder), tests/bootstrap.php (wpdb stub), tests/Unit/Events/Outbox/ (FakeWpdb, FakeOutboxConnection, AggregateVersionCounterTest ×5, OutboxWriterTest ×8, RelayWorkerStrategyTest ×21), tests/Integration/Events/Outbox/ (ConcurrentAggregateVersionTest ×3 live MySQL, RelayEndToEndTest ×5 live MySQL + live PG). Bugs fixed: bare VALUES(1) → LAST_INSERT_ID(1) in AggregateVersionCounter; \wpdb type hint → object for structural test compatibility; bind_param type-string mismatch in test setup. All four P0-S4 DoD items proved against live DBs: happy-path relay, idempotent re-relay (ON CONFLICT DO NOTHING), crash-safety (CommitSaboteurMysqlConnection — PG row survives MySQL rollback, recovery tick produces no duplicate), SKIP LOCKED concurrency. RelayWorkerStrategy redesigned mid-session: removed 'relaying' intermediate status; MySQL FOR UPDATE lock spans entire batch (BEGIN→SELECT SKIP LOCKED→PG insert+mark-relayed→COMMIT). Full suite: 99/99 tests pass (91 unit + 8 integration). Reviewer approved. | FLAG-P0S4-1: resolved by redesign (no DDL change). FLAG-P0S4-2: resolved (live-DB integration tests pass). FLAG-P0S4-3: open — created_at UTC fidelity on relay binding and assertion; resolve by P0-S7 gate.
2026-06-22 | P0-S5 | Shipped: core/Queue/Exception/QueueException, core/Queue/Providers/Database/ (QueueConnectionInterface, DatabaseQueueConnection, DatabaseQueueProvider), core/Container/Definitions/QueueServiceProvider (wired into ContainerBuilder), tests/Unit/Queue/ (FakeQueueConnection, FakeEvent, DatabaseQueueProviderTest ×37), tests/Integration/Queue/DatabaseQueueProviderIntegrationTest ×10 (live PG). Bugs fixed: (1) DatabaseQueueConnection was final — extracted QueueConnectionInterface; (2) pg_connect() pooling on SKIP LOCKED test — fixed with PGSQL_CONNECT_FORCE_NEW; (3) ownership-fencing bug — complete(), release(), deadLetter() were fenced only on status='claimed', not worker_id — fixed by adding AND worker_id=$workerId fence, returning bool (false=lease lost, abandon), moving deadLetter() ownership UPDATE first inside the transaction. New integration test proves fencing: A claims, lease expires, requeueTimedOut revives, B claims, A's complete() and release() both return false, J remains owned by B with attempts=2. Full suite: 145/145 tests pass (127 unit + 10 PG integration + 8 pre-existing relay integration). | FLAG-P0S5-1 raised and resolved same session via DECISION E (v1.5): three pg_* wrappers are accepted temporary duplication; consolidate to shared DatabaseConnectionInterface in P0-S7; P0-S6 must introduce no new raw pg_* wrapper.
2026-06-22 | P0-S6 | Shipped: core/Workers/WorkerStrategyInterface, WorkerExecutionContext, HeartbeatRecord, HeartbeatPublisherInterface, NullHeartbeatPublisher, WorkerEngine; core/Workers/Strategies/ EventWorkerStrategy (full Doc 8 §7 pipeline: Claim→Load→Validate→Resolve→Execute→Commit→Ack, retry/backoff/deadLetter), ReplayWorkerStrategy stub, ReconciliationWorkerStrategy stub, MaintenanceWorkerStrategy stub; core/Events/EventRegistry (explicit registration, OPEN-1 naming validation); core/Delivery/AdapterRegistry (explicit registration, last-wins on duplicate); core/Container/Definitions/WorkerServiceProvider (wired into ContainerBuilder). Tests: tests/Unit/Workers/ (WorkerEngineTest ×14, EventWorkerStrategyTest ×14, FakeHeartbeatPublisher, FakeWorkerStrategy, FakeQueueProvider), tests/Unit/Events/EventRegistryTest ×15, tests/Unit/Delivery/ (AdapterRegistryTest ×12, FakeAdapter). Full suite: 179/179 unit tests pass. No new pg_* wrapper introduced (DECISION E enforced). Pre-existing PHPUnit 12 deprecation in DatabaseQueueProviderTest (@dataProvider doc-comment) carried over unchanged. | No new flags.
2026-06-22 | P0-S7 | Gate verification: all 6 DoD Gate items confirmed pass (type canon, LAST_INSERT_ID counter, no postmeta refs, TIMESTAMPTZ/DATETIME split, VARCHAR(64)/CHAR(64) checksums, UUID worker-identity). DECISION E consolidation: core/Database/ introduced (DatabaseConnectionInterface, PostgresDatabaseConnection, DatabaseException); OutboxConnectionInterface and QueueConnectionInterface collapsed to extend DatabaseConnectionInterface; PgsqlOutboxConnection and DatabaseQueueConnection now delegate to shared PostgresDatabaseConnection (no duplicate pg_* logic); migration engine untouched. FLAG-P0S4-3 resolved: RelayWorkerStrategy '+00:00' binding confirmed; RelayEndToEndTest assertion strengthened to full UTC datetime + explicit offset regex. Full suite: 198 tests, 180 pass, 18 skipped (integration, live DB not in CI), 0 failures. | FLAG-P0S7-1 raised: DECISION E collapse interpretation ambiguity.
2026-06-23 | P0-S7 (continued) | DECISION E v1.6 — Split ruling applied: OutboxConnectionInterface and QueueConnectionInterface deleted; QueueConnectionInterface collapsed fully into DatabaseConnectionInterface; MysqlOutboxConnectionInterface introduced (MySQL capture path, no PG dependency); PgsqlOutboxConnection now implements DatabaseConnectionInterface via composition; DatabaseQueueConnection implements DatabaseConnectionInterface via composition; RelayWorkerStrategy holds explicit MysqlOutboxConnectionInterface + DatabaseConnectionInterface; PostgresDatabaseConnection::rollback() swallow semantics verified and unit-tested; all fakes split (FakeMysqlOutboxConnection, FakePgsqlOutboxConnection, FakeQueueConnection updated); CommitSaboteurMysqlConnection + integration test QueueConnectionInterface references updated; ARCHITECTURE_DECISIONS.md DECISION E bumped to v1.6 with full ruling. PostgresDatabaseConnectionTest added (8 tests including rollback swallow invariant). Full suite: 204 unit / 18 integration — 222 total, 0 failed, 0 skipped. | FLAG-P0S7-1 closed — DECISION E v1.6.
2026-06-23 | P1A-S1 | Shipped: modules/Content/Events/ContentEventTypes.php (9 OPEN-1 constants + ALL list), modules/Content/EventProvider.php (implements EventProviderInterface, delegates to OutboxWriterInterface), modules/Content/HookWiring.php (7 WP hooks, membership-based public-set capture per OPEN-10), modules/Content/ContentModule.php (implements ModuleInterface). Tests: tests/Unit/Content/ (ContentEventTypesTest ×57, ContentEventProviderTest ×36, HookWiringTest ×48, FakeOutboxWriter). OPEN-10 ruling applied: transition matrix uses $wasPublic/$isPublic booleans; all exit transitions emit .deleted; wp_trash_post suppressed by $handledByTransition guard when transition already fired. Full suite: 363 unit, 0 failed. | FLAG-P1AS1-1 resolved (OPEN-10 Resolved).
2026-06-23 | P1A-S2 | Shipped: modules/Content/SourceModels/ (PageSourceModel, PostSourceModel, CategorySourceModel — all readonly/immutable, strongly typed, no canonical model shape); modules/Content/Extractors/ (PageExtractor, PostExtractor, CategoryExtractor — accept already-loaded raw data arrays, no global WP calls, no DB, delegate to validators); modules/Content/Validation/ (PageValidator, PostValidator, CategoryValidator — fail-fast on missing ID/slug/status/type, collect multiple violations into ValidationException.getViolations(); ValidationException typed exception). Tests: tests/Unit/Content/SourceModels/ (PageSourceModelTest ×4, PostSourceModelTest ×4, CategorySourceModelTest ×4); tests/Unit/Content/Extractors/ (PageExtractorTest ×20, PostExtractorTest ×22, CategoryExtractorTest ×17); tests/Unit/Content/Validation/ (PageValidatorTest ×10, PostValidatorTest ×13, CategoryValidatorTest ×11). P1A-S2 tests: 247 clean, 0 deprecations. Full unit suite: 451 tests, 0 failed, 1 pre-existing deprecation (@dataProvider doc-comment in DatabaseQueueProviderTest, carried from P0-S5). No DB dependency; no WordPress function calls in any unit path. | No new flags.
2026-06-23 | P1A-S3 | Shipped: CanonicalPost/Page/Category (implement CanonicalModelInterface; order-insensitive sha256 getChecksum — sort categoryIds, ksort meta, ATOM timestamps, \0 separator, pinned digests); PostTransformer/PageTransformer/CategoryTransformer (pure SourceModel→CanonicalModel, title trimmed, other strings verbatim); tests/Unit/Content/Transformers/ (PostTransformerTest ×13, PageTransformerTest ×14, CategoryTransformerTest ×14) + tests/Unit/Content/CanonicalModels/ (CanonicalPostTest ×11, CanonicalPageTest ×11, CanonicalCategoryTest ×10, incl. order-independence + pinned-digest tests). meta flat-scalar invariant confirmed enforced at extraction boundary (P1A-S2 extractors cast all values to string; ksort sufficient, no recursive normalisation needed). 528 tests, 0 failed, 0 skipped, 1 pre-existing deprecation. | FLAG-P1AS3-1 raised: open, deferred to P1A-S4 kickoff — DECISION 3 write-suppress compatibility: architect must rule Option A (adapter uses canonical.getChecksum() directly) or Option B (adapter computes separate projection-shaped checksum) before wiring write-suppress.
2026-06-23 | housekeeping | OPEN-11 recorded in ADR (Option A, lossless Phase 1A projection, canonical checksum authoritative); FLAG-P1AS3-1 resolved. No code change.
2026-06-23 | P1A-S4 | Shipped: modules/Content/Migrations/ (content schema; pages/posts/taxonomies/entity_taxonomies; TIMESTAMPTZ + VARCHAR(64) canon; entity_taxonomies pure join table) and modules/Content/Adapters/ (Page/Post/Category persist() — DECISION 3 three-op atomic txn; OPEN-11 Option A canonical-checksum write-suppress; in-txn lockAggregateVersion() FOR UPDATE guard; monotonic GREATEST() aggregate_versions; full-replace entity_taxonomies rewrite). bulkPersist() fail-fast LogicException stub (Phase 1A). Tests: unit adapter suites + live-PG atomicity/idempotency/join-rewrite/interleave integration. Item-6 TOCTOU race fixed (version guard moved inside txn behind FOR UPDATE). Suite 598/598. | Flags resolved: FLAG-P1AS4-1 (pure join table), FLAG-P1AS4-2 (monotonic guard), FLAG-P1AS4-3 (bulkPersist Phase 1A throwing stub) — all architect-ruled 2026-06-23.

