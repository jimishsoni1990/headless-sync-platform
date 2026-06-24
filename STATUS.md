# HSP — Progress Status

> **Standing instruction:** Update this file at the end of every working session: flip task states,
> set last-updated, set next action. This is the session-to-session source of progress truth.
>
> Rationale and architecture live in [`docs/IMPLEMENTATION_PLAN.md`](docs/IMPLEMENTATION_PLAN.md)
> and [`docs/ARCHITECTURE_DECISIONS.md`](docs/ARCHITECTURE_DECISIONS.md) — do not duplicate them here.

---

**Current phase:** Phase 1A — Blog MVP  
**Last updated:** 2026-06-24 (P1A-S6b approved)  
**Next session: P1A-S6 — Next.js validation + end-to-end DoD**

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
- [x] P1A-S5 REST Delivery API
- [x] P1A-S6a Bootstrap/DI fix — module boot + REST routes
- [x] P1A-S6b Content Subscriber/Handler spine
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

**Raised:** 2026-06-23 | **Session:** P1A-S4 | **Status:** Resolved — ARCHITECTURE_DECISIONS.md v1.8 (2026-06-23); amended by DECISION J v1.10 (2026-06-24)

**Resolution:** Monotonic guard adopted. The `system.aggregate_versions` upsert in all three adapters uses `GREATEST()` so `latest_processed_version` only ever advances. Worker Resolve step remains the primary stale-event guard; the database GREATEST() guard provides defense-in-depth. Integration test `test_aggregate_versions_never_regresses_on_out_of_order_delivery` confirms the guard holds against live PostgreSQL.

**Amendment (DECISION J, 2026-06-24 — `docs/ARCHITECTURE_DECISIONS.md` v1.10):** The v1.8 ruling said "worker owns stale-event detection" without specifying the exact location in the pipeline. DECISION J clarifies: the **Resolve stage** (Step 4 of the Doc 8 §7 pipeline) is the PRIMARY, authoritative stale-event gate — `EventWorkerStrategy` performs a PG read of `system.aggregate_versions` before handler invocation and terminates early if the event is stale. The adapter-side FOR UPDATE + GREATEST guard is MANDATORY defense-in-depth for the TOCTOU window. Both layers are binding; neither replaces the other.

---

### FLAG-P1AS4-3 — `bulkPersist()` version guard and event recording

**Raised:** 2026-06-23 | **Session:** P1A-S4 close | **Status:** Resolved — architect ruling 2026-06-23

**Resolution (architect, 2026-06-23):** Option B approved. `persist()` is the ONLY supported persistence entry point in Phase 1A. `bulkPersist()` stays on `AdapterInterface` (signature unchanged) but performs NO projection writes in Phase 1A. All three adapters implement it as `throw new \LogicException('bulkPersist() is not implemented in Phase 1A.');` — no transaction, no execute, no partial path. The correct guarded batch path (events + version context, same guarantees as `persist()`) is deferred to a future ADR that lands with the first batch-with-events caller. Recorded in `docs/ARCHITECTURE_DECISIONS.md` under FLAG-P1AS4-3.

---

### FLAG-P1AS5-1 — IMPLEMENTATION_PLAN.md §4 five-bullet undercount

**Raised:** 2026-06-24 | **Session:** P1A-S5

The Phase 1A deliverables list in IMPLEMENTATION_PLAN.md §4 shows only five REST endpoint bullets:
- GET /api/v1/pages (listing)
- GET /api/v1/pages/{slug}
- GET /api/v1/posts (listing)
- GET /api/v1/posts/{slug}
- GET /api/v1/categories (listing)

The Session Map row for P1A-S5 says "Six endpoints." The P1A-S5 session brief explicitly confirmed six, including `GET /api/v1/categories/{slug}`. The sixth endpoint was built and tested (Session Map is the authoritative source per IMPLEMENTATION_PLAN.md §1).

**Resolution trigger:** Reconcile IMPLEMENTATION_PLAN.md §4 to add the missing sixth bullet (`GET /api/v1/categories/{slug}`) at the next opportunity. No code change needed — the implementation is correct.

---

### FLAG-P1AS6-2 — Plugin bootstrap incomplete: modules never boot; REST routes never register

**Raised:** 2026-06-24 | **Session:** P1A-S6 | **Status:** Resolved — P1A-S6a (2026-06-24)

**What is missing (three separate gaps):**

**Gap A — `Application::boot()` never calls `module.registrar::registerAll()`.**  
`Application::boot()` calls `$bootstrapper->bootstrap()` which builds the container, but `ModuleRegistrar::registerAll()` (which runs `discovery → register → boot`) is never called. Modules are never discovered; no hooks fire; no REST routes register.

**Gap B — `ModuleLoader::load()` calls `new $class()` with no constructor arguments.**  
`ContentModule` requires `(HookWiring $hookWiring, EventProviderInterface $eventProvider)` in its constructor. `ModuleLoader::load()` does `new $class()` — this would throw a fatal error if module loading were ever reached.

**Gap C — `ContentModule` has no REST wiring.**  
`ContentModule::register()` calls only `$this->hookWiring->register()`. There is no `add_action('rest_api_init', ...)` call anywhere in the Content module, so `ContentRestRegistrar::register()` is never invoked.

**Impact on P1A-S6 DoD:**

All E2E DoD items require the plugin to actually function:
- Without Gap A fixed: no WordPress hooks fire, no outbox events are captured.
- Without Gap B fixed: module loading crashes if discovery runs.
- Without Gap C fixed: REST API routes are never registered (`api/v1` namespace absent from WP REST index).

**Files involved (all out-of-scope per session brief):**
- `bootstrap/Application.php` — needs a call to `$container->get('module.registrar')->registerAll()` after `bootstrap()`
- `core/Module/ModuleLoader.php` — needs DI-aware construction (accept Container or a factory, not `new $class()`)
- `modules/Content/ContentModule.php` — needs REST registrar wired via `add_action('rest_api_init', ...)`

**Resolution (P1A-S6a, 2026-06-24 — Option B applied):**

Option B selected — new session P1A-S6a authorized for all three gaps:
- **Gap A:** `Application::boot()` now calls `$this->container->get('module.registrar')->registerAll()` after `bootstrap()`.
- **Gap B:** `ModuleLoader` refactored to accept `Container` via constructor injection; `load()` resolves via `$container->get($class)` when an explicit binding exists, falls back to `new $class()` only for zero-required-arg constructors, throws `InvalidManifestException` if required args exist but no binding. No reflection-based autowiring. `ContentModule` binding added in new `ContentServiceProvider`.
- **Gap C:** `ContentModule` receives a `ContentRestRegistrarFactory` (typed composition-root factory object, ADR-012 clean) via constructor injection; `boot()` wires `add_action('rest_api_init', ...)` with a closure that invokes the factory. `ContentModule` holds no `Container` reference (FLAG-P1AS6A-5 resolved). `ContentRestRegistrarFactory` is a new `final class` in `modules/Content/Rest/`; it receives per-dep factory closures from `ContentServiceProvider` and defers PG connection to first `__invoke()` call.
- `ContentServiceProvider` registered in `ContainerBuilder` (explicit, before `ModuleServiceProvider`).
- `ModuleServiceProvider` passes `Container` to `ModuleLoader`.
- Suite: 734/734 pass (12 new tests total: 3 `ModuleLoaderTest` + 6 `ContentModuleBootTest` original + 3 new ADR-012/lazy-deferral assertions). 0 skipped (with live DB env vars). 1 pre-existing deprecation unchanged.

---

### FLAG-P1AS6A-1 — QueueServiceProvider: new DatabaseConnectionInterface singleton touches P0-S5 / DECISION E v1.6

**Raised:** 2026-06-24 | **Session:** P1A-S6a (out-of-scope change) | **Status:** Open — E2E-blocking; must be resolved before P1A-S6 E2E runs

**What changed:** `QueueServiceProvider` received a new `singleton(DatabaseConnectionInterface::class, ...)` binding (lines 38–55) that opens a `\pg_connect()` connection without `PGSQL_CONNECT_FORCE_NEW` and returns a `PostgresDatabaseConnection` wrapper. This was added to satisfy `ContentServiceProvider`'s query-provider dependencies, which need `DatabaseConnectionInterface`.

**Scope extended (P1A-S6b, 2026-06-24):** This binding is now also exercised at runtime by `EventWorkerStrategy`'s Resolve-stage stale-guard read. `WorkerServiceProvider` injects `$c->get(DatabaseConnectionInterface::class)` as the third constructor arg to `EventWorkerStrategy`; the Resolve-stage `isStale()` call performs a non-locking `SELECT` on `system.aggregate_versions` through this shared connection. The blast radius of this flag therefore covers not only REST delivery queries (`ContentRestRegistrar` / query providers) but also the worker Resolve-stage PG read path — both are live in P1A-S6b.

**Frozen sessions / docs touched:**
- **P0-S5:** Introduced `DatabaseQueueConnection` wrapping a `PGSQL_CONNECT_FORCE_NEW` handle specifically to prevent connection-pool sharing on the `SKIP LOCKED` claim path. The new binding opens a *second* connection via the same DSN without `FORCE_NEW`. PHP's libpq may return a pooled handle for the same DSN if one already exists in the process (e.g., `'outbox.connection.pgsql'` in `OutboxServiceProvider` uses the same DSN). Connection sharing between `DatabaseConnectionInterface` (REST delivery OR Resolve-stage worker read) and any concurrent transactional consumer in the same process would be unsafe.
- **DECISION E v1.6:** Authorized `DatabaseConnectionInterface` as the shared runtime PG abstraction but scoped consolidation to P0-S7 and declared: *"no new raw `pg_*` wrapper may be introduced in P0-S6."* This binding introduces a new `pg_connect()` call outside the three previously accepted wrappers.

**Ruling needed:** (a) Is opening a second `pg_connect()` without `FORCE_NEW` for REST delivery queries and the worker Resolve-stage read acceptable, given that libpq may pool it with `outbox.connection.pgsql`? (b) Should `DatabaseConnectionInterface` be sourced from an already-authorized connection instead (e.g., share `outbox.connection.pgsql` or a dedicated delivery connection with `FORCE_NEW`)? (c) Does this placement in `QueueServiceProvider` (rather than a dedicated `DeliveryServiceProvider`) violate DECISION E's allocation intent?

---

### FLAG-P1AS6A-2 — pg_connect prefix fixes in QueueServiceProvider and OutboxServiceProvider

**Raised:** 2026-06-24 | **Session:** P1A-S6a (out-of-scope change) | **Status:** Recorded and kept — no runtime regression; provider factory closures remain integration-test-uncovered

**What changed:** All occurrences of `pg_connect(` in `QueueServiceProvider` and `OutboxServiceProvider` were prefixed with `\` to produce `\pg_connect(`. The change is correctness cleanup: in PHP, an unqualified function call inside a namespaced class resolves to the current namespace first, then falls back to global. Since `HSP\Core\Container\Definitions\pg_connect` does not exist, PHP's fallback to `\pg_connect()` applied at runtime in all prior sessions — the calls were never broken. The prefix eliminates the fallback lookup.

**Frozen sessions / docs touched:**
- **P0-S4 / P0-S5 "live DB proofs":** The integration tests for those sessions (`RelayEndToEndTest`, `DatabaseQueueProviderIntegrationTest`) bypassed the service-provider factory closures entirely — they injected raw `pg_connect()` handles directly in the test setup and never invoked `OutboxServiceProvider` or `QueueServiceProvider` factory code. The prefix fix does not invalidate those test results, but it means the factory closures themselves were never exercised by the integration test suite.

**Ruling needed:** Confirm the fix is accepted as a correctness cleanup. No runtime regression possible (PHP's global-namespace fallback was always in effect). Note for the record that P0-S4/P0-S5 provider factory closures remain integration-test-uncovered.

---

### FLAG-P1AS6A-3 — Bootstrapper.php: modulesBasePath parameter surfaces a P0-S3 discovery gap

**Raised:** 2026-06-24 | **Session:** P1A-S6a (out-of-scope change) | **Status:** Recorded and kept — fix accepted; P0-S3 fixture-only discovery noted; real-filesystem integration test deferred

**What changed:** `Bootstrapper::bootstrap()` gained a `string $modulesBasePath = ''` parameter (passed from `Application::boot()` which derives it from `HSP_PLUGIN_DIR . 'modules/'`). `ContainerBuilder::build()` already accepted `$modulesBasePath` as its second parameter; the gap was that `Bootstrapper` was not forwarding it.

**Frozen sessions / docs touched:**
- **P0-S3 DoD:** P0-S3 claimed "module registry discovers modules via module.json." That was proven only via unit tests that inject a fake `ModuleLoader` and never invoke `ModuleDiscovery::discover()` against the real filesystem. Without the `modulesBasePath` fix, `glob('/*/module.json')` on the real boot path returns `[]` silently — no modules were ever discovered in production. The P0-S3 live-boot DoD item was not actually proven.

**Ruling needed:** (a) Confirm the fix is accepted. (b) Rule on whether P0-S3's DoD is considered retroactively satisfied (modules now discover correctly at live boot), or whether a P0-S3 integration test covering real-filesystem discovery should be added before P1A-S6a is approved.

---

### FLAG-P1AS6A-4 — headless-sync.php: pgsql extension guard

**Raised:** 2026-06-24 | **Session:** P1A-S6a (out-of-scope change) | **Status:** Recorded and kept — accepted as justified defensive measure at entry point

**What changed:** `headless-sync.php` received an `extension_loaded('pgsql')` check immediately after the `ABSPATH` guard. If `pgsql` is not loaded, an `admin_notices` action is registered to show an error banner, and the file returns early — the autoloader and `Application` are never initialized.

**Frozen sessions / docs touched:**
- No frozen ADR is directly contradicted. The change is a plugin-activation guard. The scope concern is that `headless-sync.php` is the plugin entry point; changes to it were not in the P1A-S6a brief. The CLAUDE.md session-close rule requires out-of-scope file changes to be "reverted or flagged."

**Ruling needed:** Confirm whether the pgsql guard is accepted as a justified defensive measure at the entry point, or whether it should be reverted and re-introduced in a dedicated session with a test covering the early-return path.

---

### FLAG-P1AS6A-5 — Gap C lazy closure: deferred $c->get() in business logic (ADR-012 boundary)

**Raised:** 2026-06-24 | **Session:** P1A-S6a | **Status:** Resolved — P1A-S6a review pass (2026-06-24)

**Resolution:** Replaced the bare `\Closure` capturing `$c` with a typed `ContentRestRegistrarFactory` final class (`modules/Content/Rest/ContentRestRegistrarFactory.php`). The factory receives six per-dep factory closures via constructor injection (defined in `ContentServiceProvider`); it holds no `Container` reference. `ContentModule::$restRegistrarFactory` is typed `ContentRestRegistrarFactory` — grep of `ContentModule.php` finds no `Container::get`, `$container->get`, or `global $container`. Lazy deferral preserved: `DatabaseConnectionInterface` (PG connection) is not resolved until `ContentRestRegistrarFactory::__invoke()` is called at `rest_api_init` time. Proven by `testContentRestRegistrarIsNotConstructedAtModuleLoadTime` (spy counter = 0 at module-load, = 1 at first factory invocation).

---

### FLAG-P1AS6-1 — Missing Content event handler layer: queue → PG projection never implemented

**Raised:** 2026-06-24 | **Session:** P1A-S6 | **Status:** Partially resolved — architect rulings issued 2026-06-24; implementation deferred to P1A-S6b

**What is missing:**

`EventWorkerStrategy::executeHandler()` (introduced in P0-S6) contains an explicit stub with comment:
> *"P1A-S1 TODO: resolve subscriber from EventRegistry; invoke handler; handler commits its own PG transaction (DECISION 3)."*

No Subscribers, Handlers, or event-handler wiring exists anywhere in `modules/Content/`. The P1A-S1 session log records HookWiring and EventProvider only — the handler layer was not built. No session P1A-S2 through P1A-S5 added it either. The `EventRegistry` API (`register(eventType, callable)`) is fully implemented in core but nothing registers content event handlers. `ContentModule::register()` calls only `$this->hookWiring->register()`.

**Impact on P1A-S6 DoD:**

The following E2E DoD items **cannot be satisfied** without the handler layer:
- End-to-end sync (WP edit → outbox → relay → queue → worker → PG projection)
- Sync delay < 30s SLA
- API endpoints return correct data (no projection rows exist without the handler)
- Three-op atomicity under live conditions
- Idempotency under live conditions
- Stale-event skip under live conditions
- Next.js pages reflect live WP content (depends on projection data)

The live infrastructure (MySQL, PostgreSQL Docker, WordPress, worker engine) is fully available. The gap is in the business logic layer within `modules/Content/` scope.

**Architect rulings issued 2026-06-24 (pre-P1A-S6b):**

- **Worker State Loading (DECISION H — `docs/ARCHITECTURE_DECISIONS.md` v1.10):** Option B approved. Workers reload current WordPress state via a defined WP bootstrap path in the worker runtime. Event payload enrichment (Option A) rejected. Direct-MySQL reload (Option C) rejected. ADR-044 reaffirmed. Operational bootstrap details deferred to Doc 10 / ops session.

- **Delete Processing (DECISION I — `docs/ARCHITECTURE_DECISIONS.md` v1.10):** Option C approved. `content.*.deleted` events follow a dedicated tombstone path consuming only the event envelope (aggregate identity + metadata); performs soft-delete projection; no reload, no extract, no transform. `AdapterInterface` gains `tombstone()` method. Canonical models and OPEN-11 checksum surface UNCHANGED. DECISION 3 three-op atomicity applies to the tombstone path.

- **Stale-Event Guard (DECISION J — `docs/ARCHITECTURE_DECISIONS.md` v1.10):** Resolve-stage guard is PRIMARY, authoritative stale-event gate. Adapter in-txn FOR UPDATE + GREATEST guard is MANDATORY defense-in-depth. Authorized for P1A-S6b: PG read dependency on `EventWorkerStrategy`, `WorkerServiceProvider` wiring, Resolve-stage aggregate-version lookup, early termination before handler execution.

**Resolution trigger:** FLAG is fully resolved when P1A-S6b ships the Content Subscriber/Handler spine implementing all three rulings above.

---

## Session Log

<!-- Append one line per session: YYYY-MM-DD | session ID | what shipped | flags raised -->
2026-06-24 | P1A-S6a (review pass) | ADR-012 fix for FLAG-P1AS6A-5: replaced bare \Closure capturing Container with typed ContentRestRegistrarFactory (final class, modules/Content/Rest/ContentRestRegistrarFactory.php). Factory receives six per-dep \Closure factories from ContentServiceProvider; holds no Container reference; memoizes ContentRestRegistrar on first __invoke(). ContentModule::$restRegistrarFactory typed ContentRestRegistrarFactory — grep-clean of ContentModule.php for Container::get/global $container. Lazy deferral preserved: DatabaseConnectionInterface not resolved until rest_api_init fires, proven by testContentRestRegistrarIsNotConstructedAtModuleLoadTime (spy counter). ContentModuleBootTest gains 3 new assertions (no-Container-reference reflection test, typed-factory assertion, lazy-deferral spy). Suite: 734/734, 0 failures, 0 skipped, 1 pre-existing deprecation. Live WP: api/v1 ✓, 6 routes ✓. FLAG-P1AS6A-5 resolved. FLAG-P1AS6-2 resolution text updated. FLAG-P1AS6A-1 marked E2E-blocking. FLAG-P1AS6A-2/-3/-4 recorded-and-kept. | no new flags.
2026-06-24 | P1A-S6a | Bootstrap/DI fix (FLAG-P1AS6-2 Gaps A/B/C resolved). Shipped: ContentServiceProvider (PageQueryProvider, PostQueryProvider, CategoryQueryProvider, PageResource, PostResource, CategoryResource, ContentRestRegistrar, HookWiring, EventProvider, ContentModule — all container-bound); ModuleLoader refactored to inject Container + resolve via explicit bindings first, fallback new $class() for zero-arg, throw for required-arg without binding (no reflection autowiring); Application::boot() now calls registerAll() after bootstrap(); ContentModule gains lazy \Closure ContentRestRegistrar factory dep + boot() wires add_action('rest_api_init') lazily (PG connection deferred to rest_api_init hook); headless-sync.php gains extension_loaded('pgsql') guard (graceful admin notice + bail); ModuleServiceProvider passes Container to ModuleLoader; ContainerBuilder registers ContentServiceProvider; DatabaseConnectionInterface binding added in QueueServiceProvider; \pg_connect() global prefix fixed in OutboxServiceProvider and QueueServiceProvider; wp-config.php HSP env vars added for local dev PG/MySQL credentials; php.ini php_pgsql.dll enabled in web server. Tests: 731 total (722 prior + 9 new: ModuleLoaderTest ×3 new, ContentModuleBootTest ×6), 0 failures, 0 skipped (with live DB env vars), 1 pre-existing deprecation. Live WP DoD: api/v1 present in wp-json namespaces ✓; all 6 content routes registered (/posts, /posts/{slug}, /pages, /pages/{slug}, /categories, /categories/{slug}) ✓; routes 500 on missing content.* schema (P1A-S4 migrations not applied to local Docker PG — expected; not a code bug). FLAG-P1AS6-2 resolved. Session Map updated: P1A-S6a + P1A-S6b inserted; P1A-S6 E2E now depends on P1A-S6b. | no new flags.
2026-06-24 | P1A-S6 (partial — flags block E2E DoD) | Next.js consumer app built and verified (hsp-blog/: lib/api.ts, app/posts/page.tsx, app/posts/[slug]/page.tsx, app/pages/[slug]/page.tsx, not-found.tsx; TypeScript clean; production build passes; HTTP 200 on all consumer routes against running server). Type-canon check PASS (all content.* migrations: TIMESTAMPTZ timestamps, VARCHAR(64) checksums). Module isolation check PASS (no cross-module imports, no service-locator calls in business logic). IMPLEMENTATION_PLAN.md §4 reconciled: added missing GET /api/v1/categories/{slug} bullet (FLAG-P1AS5-1 resolved). PHP test suite: 722/722. E2E DoD blocked by two flags requiring architect rulings. | flags: FLAG-P1AS6-2 (plugin bootstrap incomplete — Application::boot() never calls module.registrar::registerAll(); ModuleLoader uses new $class() without args; ContentModule has no rest_api_init wiring — REST routes never register); FLAG-P1AS6-1 (EventWorkerStrategy::executeHandler() is a P0-S6 stub — no Content Subscribers/Handlers exist; queue → PG projection pipeline is unimplemented).
2026-06-24 | P1A-S5 | REST Delivery API — 6 endpoints, core QueryProvider/Resource/FilterSet/CursorPage contracts (DECISION F v1.9), cursor pagination proven on live PG, status/cursor 400s, single-fetch publish+not-deleted 404 guard, limit clamps. Shipped: core/Contracts/ (QueryProviderInterface, ResourceInterface, FilterSet, CursorPage); modules/Content/Queries/ (PageQueryProvider, PostQueryProvider, CategoryQueryProvider — (sort,id) tiebreaker cursor, DEFAULT/MAX limits, projection-side category join); modules/Content/Resources/ (PageResource, PostResource, CategoryResource — contract fields only, no internal columns); modules/Content/Rest/ContentRestRegistrar (WP-only boundary: sanitize inputs, 400 non-public status, 400 malformed cursor, 404 missing/soft-deleted/non-publish slug, six /api/v1/ routes); tests/bootstrap.php (WP REST stubs). Tests: 664 unit + 58 integration = 722 total, 0 failures. Shared-sort-value cursor edge case proven against live PostgreSQL (pages/posts: shared published_at; categories: shared name). DECISION F recorded in ARCHITECTURE_DECISIONS.md v1.9. | flags: FLAG-P1AS5-1 (IMPLEMENTATION_PLAN.md §4 five-bullet undercount — categories/{slug} missing; Session Map authoritative; reconcile plan text).
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
2026-06-24 | P1A-S6b | Shipped: WpContentLoader contract + WpContentLoaderImpl (get_post/get_post_meta/get_term/wp_get_post_terms); AdapterInterface::tombstone() + PageAdapter/PostAdapter/CategoryAdapter tombstone impls (soft-delete, DECISION I, DECISION 3 atomicity, deleted_at = source_updated_at); WpContentLoaderImpl shape matched by FakeWpContentLoader (all extractor-consumed keys identical); ContentSubscriber (9-type routing, OPEN-1 types, RuntimeException on missing handler); ContentUpsertHandlerInterface; Page/Post/CategoryUpsertHandler (loader→extractor→transformer→adapter pipeline, DECISION H Option B); Page/Post/CategoryTombstoneHandler (event-envelope-only, no WP reload); EventWorkerStrategy::executeHandler() un-stubbed (EventRegistry handler dispatch); EventWorkerStrategy Resolve-stage stale guard added (PRIMARY gate, DECISION J Layer 1 — non-locking SELECT on system.aggregate_versions, <=stored → ack + zero writes); WorkerServiceProvider wired DatabaseConnectionInterface to EventWorkerStrategy (DECISION E, no new pg_connect, queue FORCE_NEW handle not entangled); ContentModule + ContentServiceProvider wired all 9 handlers + ContentSubscriber into EventRegistry. Integration tests: HandlerSpineIntegrationTest (12 tests — persist×3, tombstone×4, idempotent re-delivery×2, adapter GREATEST guard×1, subscriber routing×2; adapter stale write-set assertions strengthened: stale event's own processed_events row proven by ID, aggregate_versions row count asserted); ResolveStageGuardIntegrationTest (4 tests — zero-writes+no-handler+job-acked on stale event proven on live PG, non-stale does not fire, equal-version treated as stale, missing aggregate_versions row not stale). Unit tests: ContentHandlerTest (9), ContentSubscriberTest (3), adapter tombstone unit tests added to Page/Post/CategoryAdapterTest (6 each = 18 total). Suite: 784 tests, 1664 assertions, 0 failures, 0 errors, 0 skipped, 1 pre-existing deprecation. DECISIONS H/I/J recorded in ARCHITECTURE_DECISIONS.md (step 0 of session). FLAG-P1AS6A-1 blast radius extended: now covers worker Resolve-stage PG read path in addition to REST delivery; still open, still E2E-blocking. FLAG-P1AS6-1 fully resolved. | no new flags.
2026-06-23 | P1A-S4 | Shipped: modules/Content/Migrations/ (content schema; pages/posts/taxonomies/entity_taxonomies; TIMESTAMPTZ + VARCHAR(64) canon; entity_taxonomies pure join table) and modules/Content/Adapters/ (Page/Post/Category persist() — DECISION 3 three-op atomic txn; OPEN-11 Option A canonical-checksum write-suppress; in-txn lockAggregateVersion() FOR UPDATE guard; monotonic GREATEST() aggregate_versions; full-replace entity_taxonomies rewrite). bulkPersist() fail-fast LogicException stub (Phase 1A). Tests: unit adapter suites + live-PG atomicity/idempotency/join-rewrite/interleave integration. Item-6 TOCTOU race fixed (version guard moved inside txn behind FOR UPDATE). Suite 598/598. | Flags resolved: FLAG-P1AS4-1 (pure join table), FLAG-P1AS4-2 (monotonic guard), FLAG-P1AS4-3 (bulkPersist Phase 1A throwing stub) — all architect-ruled 2026-06-23.

