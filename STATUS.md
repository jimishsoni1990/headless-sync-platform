# HSP — Progress Status

> **Standing instruction:** Update this file at the end of every working session: flip task states,
> set last-updated, set next action. This is the session-to-session source of progress truth.
>
> Rationale and architecture live in [`docs/IMPLEMENTATION_PLAN.md`](docs/IMPLEMENTATION_PLAN.md)
> and [`docs/ARCHITECTURE_DECISIONS.md`](docs/ARCHITECTURE_DECISIONS.md) — do not duplicate them here.

---

**Current phase:** Phase 1A — Blog MVP  
**Last updated:** 2026-07-12 (OPS-S2 opened to implement entity + date-range replay; STOPPED at pre-implementation — hit CRITICAL STOP CONDITION → FLAG-OPSS2-1: replay↔DECISION-J-stale-guard mechanism is unspecified in Doc 4 §24. No replay code written. OPS-S2 Session Map row inserted; GATE-S1 Depends-on amended to OPS-S1, OPS-S2.)  
**Next session:** OPS-S2 (blocked) — awaiting architect ruling on FLAG-OPSS2-1 (choose the replay reprojection mechanism; authorize any schema change it entails). Once ruled, OPS-S2 builds entity + date-range replay, then GATE-S1 criterion 2 is re-run (only). Pointer holds at OPS-S2 → GATE-S1 resume. GATE-S2 (Scalability) does not begin until GATE-S1 fully passes.

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
- [x] P1A-S6c Delivery connection isolation (DECISION K)
- [x] P1A-S6d Dispatcher stage (system.events → system.queue_jobs, DECISION L)
- [x] P1A-S6 Next.js validation + end-to-end DoD
- [x] P1A-S7 REST namespace rename: api/v1 → hsp/v1 (DECISION N)
- [x] P1A-S8 Env → define config resolution

### Early Operational Baseline

- [x] OPS-S1 Early Operational Baseline (DLQ inspect/replay, worker health, metrics)
- [ ] OPS-S2 Replay Engine — entity + date-range replay — **BLOCKED — FLAG-OPSS2-1 (replay↔DECISION-J stale-guard mechanism unspecified in Doc 4 §24; architect ruling required before code)**

---

## Architecture Validation Gate

- [ ] Reliability validation — **BLOCKED — FLAG-GATES1-1 (entity + date-range replay not implemented)** (GATE-S1: criteria 1 & 3 PASS; criterion 2 FAILS)
- [ ] Scalability validation
- [ ] Operability validation
- [ ] Extensibility validation

Gate failure blocks Phase 2 and all subsequent phases.

### GATE-S1 — Reliability Validation checklist (evidence: `tests/Integration/Gate/ReliabilityValidationTest.php`)

| # | §4 Reliability criterion | Result | Evidence (named integration test) |
|---|---|---|---|
| 1 | Successful sync processing under normal load | **PASS** | `test_criterion1_full_pipeline_syncs_a_batch_end_to_end_under_normal_load` (12-aggregate mixed batch: outbox → relay → dispatch → worker → `content.*` projection, live MySQL + PG); `test_criterion1_update_event_reprojects_the_aggregate_end_to_end` |
| 2 | Replay succeeds for single **event, entity, and date-range** replay modes | **FAIL — BLOCKED** | `test_criterion2_only_single_event_replay_is_implemented` (Incomplete): single-event replay proven present; entity + date-range modes proven ABSENT. **STOP-and-flag → FLAG-GATES1-1.** |
| 3 | DLQ recovery: failed job replays to correct final state | **PASS** | `test_criterion3_exhausted_job_dead_letters_then_replays_to_correct_final_state` (retry-limit exhaustion → `system.dead_letter_jobs` with full OPEN-3 context → `hsp dlq replay` → correct final projection state → `replayed_at` stamped; live PG) |

GATE-S1 DoD is **not met** (criterion 2 fails). Per the gate brief and CLAUDE.md session-close rule 1, the Reliability item is NOT marked done and GATE-S2 does not begin until FLAG-GATES1-1 is resolved by an authorized (non-gate) session.

---

## Flags

### FLAG-OPSS2-1 — Entity + date-range replay vs. DECISION J stale guard: reprojection mechanism unspecified

**Raised:** 2026-07-12 | **Session:** OPS-S2 (pre-implementation) | **Status:** OPEN — architect ruling required before any replay code is written

**STOP condition hit. No replay code was written.** OPS-S2 was scoped to un-stub `ReplayWorkerStrategy` and build entity + date-range replay (Doc 4 §24). Before writing code, the brief required determining how Doc 4 §24 specifies replay to interact with the DECISION J stale guard. It does not — the mechanism is unspecified across all frozen docs, and the three governing constraints are mutually incompatible for the already-processed case.

**The conflict (three frozen constraints that cannot all hold):**

1. **Replay re-enqueues the original event version** — IMPLEMENTATION_PLAN.md §5b line 284: *"Replay re-enqueues the original event version; does not mutate historical contracts (Doc 5 §26)."* Doc 5 §26: *"Replay must use original event versions… Replay must never mutate historical contracts."*
2. **DECISION J stale guard is binding and may not be weakened/bypassed** — `EventWorkerStrategy::isStale()` ([EventWorkerStrategy.php:205](headless-sync/core/Workers/Strategies/EventWorkerStrategy.php#L205)): `event.aggregate_version <= stored latest_processed_version → stale → ack, ZERO writes`. Per the brief, this guard *"may not be weakened, bypassed, or made conditional without a DECISION."*
3. **DoD criterion 1/2 requires the entity to reproject to correct final state.**

For an aggregate that was **already successfully processed**, `system.aggregate_versions.latest_processed_version` already equals the newest event's `aggregate_version`. Re-enqueuing any existing `event_id` for that aggregate → the DECISION J guard sees `version <= stored` → **acks with zero projection writes**. The entity does **not** reproject. Constraints 1 + 2 + 3 cannot simultaneously hold.

**Why DLQ replay (DECISION S / OPS-S1) does NOT resolve this:** DLQ replay works precisely because a dead-lettered event's aggregate was *never successfully processed* — its version is still **ahead** of `latest_processed_version`, so the guard lets it through (DECISION S clause (c) even documents the "already-current → zero writes" case as acceptable *for that path*). Entity/date-range replay targets **already-current** aggregates, which is the opposite situation: the guard suppresses them.

**Why Doc 5 §26 does not disambiguate:** §26 governs `event_version` (the contract/schema version — "v1 stays v1"), a field distinct from the per-aggregate `aggregate_version` monotonic counter (Doc 5 lines 262 vs 268). §26 forbids rewriting event schema versions and mutating historical event contracts; it says nothing about the `aggregate_version`↔stale-guard interaction. IMPLEMENTATION_PLAN.md line 284's "original event version" is itself ambiguous between the two readings, and neither reading specifies the reprojection mechanism.

**Options (architect must choose ONE — I have NOT picked one, and none may be implemented without a DECISION):**

- **Option A — Fresh synthetic event via the outbox with a new counter version.** Replay re-captures current WP state (ADR-044/DECISION H) into `wp_hsp_outbox`, allocating a new `aggregate_version` via `wp_hsp_aggregate_counters` (DECISION 2). The new version is `> latest_processed_version`, so it passes the guard naturally and reprojects. *Tension:* appears to violate constraint 1 ("re-enqueues the **original** event version") if that phrase binds `aggregate_version`; must confirm §26 only binds `event_version`. Honors Rule 3 (goes through the outbox) and ADR-044 (reload current state). Requires no schema change.
- **Option B — Guard bypass for replay.** A replay-originated job carries a flag that instructs `EventWorkerStrategy` to skip the Resolve-stage guard (Layer 2 GREATEST guard still holds). *Tension:* directly weakens/makes-conditional the DECISION J guard — explicitly prohibited by the brief without a DECISION; also risks a replay racing a live higher-version event.
- **Option C — Version-state reset.** Before re-enqueue, delete/lower the `system.aggregate_versions` row for the target aggregate(s) so the original event is no longer `<=` stored. *Tension:* mutates version state (arguably "mutates historical" state), interacts with the GREATEST guard and `system.processed_events`, and risks regression flagged by DoD criterion 4.

**Also unspecified (dependent on the above):** whether date-range replay enumerates affected aggregates from `system.events` by `created_at`/`source_updated_at` window, or from another source; and the WP-CLI surface shape (`hsp replay entity <type> <id>` / `hsp replay range <from> <to>` — extending the OPS-S1 `hsp` command surface per the brief).

**Schema note:** If the ratified mechanism requires any new table or column (e.g. a replay-marker column, a replay-batch table), that is a **separate frozen-schema change requiring its own DECISION** before implementation — per the brief's out-of-scope clause. Option A requires none; Options B/C may.

**Resolution trigger:** Architect ratifies the replay↔stale-guard mechanism (one of the options above or another), records it as a DECISION in `docs/ARCHITECTURE_DECISIONS.md`, and authorizes any schema change it entails. Only then may OPS-S2 build entity + date-range replay. GATE-S1 criterion 2 remains BLOCKED (FLAG-GATES1-1) until OPS-S2 ships under that ruling.

---

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

**Raised:** 2026-06-24 | **Session:** P1A-S5 | **Status:** Resolved — P1A-S7 (2026-06-25)

The Phase 1A deliverables list in IMPLEMENTATION_PLAN.md §4 showed only five REST endpoint bullets (missing `GET /hsp/v1/categories/{slug}`). The sixth endpoint was built and tested in P1A-S5 (Session Map authoritative per §1).

**Resolution (P1A-S7, 2026-06-25):** IMPLEMENTATION_PLAN.md §4 endpoint list reconciled to six bullets (`/hsp/v1/…`) during the namespace rename pass. All six endpoints now appear in the deliverables list. No code change was needed — the implementation was already correct.

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

**Raised:** 2026-06-24 | **Session:** P1A-S6a (out-of-scope change) | **Status:** Resolved — P1A-S6c (2026-06-24)

**Resolution (P1A-S6c, 2026-06-24 — DECISION K applied):** `DatabaseConnectionInterface` singleton binding removed from `QueueServiceProvider`. New `DeliveryServiceProvider` (`core/Container/Definitions/DeliveryServiceProvider.php`) opens a dedicated connection with `PGSQL_CONNECT_FORCE_NEW`, wrapping `PostgresDatabaseConnection` (no new pg_* wrapper). `ContainerBuilder` registers `DeliveryServiceProvider` before `WorkerServiceProvider` and `ContentServiceProvider`. Integration test `DeliveryConnectionIsolationTest` (5 tests) proves all 5 DoD-4 items against live PostgreSQL. DECISION K recorded in `docs/ARCHITECTURE_DECISIONS.md` v1.11. IMPLEMENTATION_PLAN.md §5b: P1A-S6c inserted; P1A-S6 Depends-on = P1A-S6c. Full suite: 789/789, 0 failures, 0 skipped (live DB).

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

### FLAG-OPSS1-1 — Heartbeat/metrics persistence: no worker-health table was ever frozen

**Raised:** 2026-07-11 | **Session:** OPS-S1 (pre-implementation) | **Status:** Resolved — DECISION P + DECISION Q (ARCHITECTURE_DECISIONS.md v1.16, 2026-07-11)

**Resolution (architect rulings 1 & 2, 2026-07-11):** DECISION P freezes a single current-state table `system.worker_heartbeats` (worker_id UUID PK, worker_type TEXT, status TEXT, last_heartbeat_at TIMESTAMPTZ, started_at TIMESTAMPTZ), upserted per tick, no history; `DatabaseHeartbeatPublisher` implements the existing `HeartbeatPublisherInterface`, connection injected via constructor (ADR-012) using the worker-runtime handle (DECISION L Ruling 0 — no new handle/class/pg_* wrapper). A migration creating the table is authorized for OPS-S1. DECISION Q rules out any metrics table: derived metrics computed on demand from PostgreSQL; runtime counters emitted as structured worker log events; "metrics emit" DoD = queryable status + structured logs. The connection-binding question in (d) is answered by DECISION L Ruling 0: heartbeat is worker-runtime infrastructure on the existing worker-runtime connection, not the delivery handle. Both heartbeat "visible/updated per tick" and metrics "emit" DoD items are now buildable.

OPS-S1 DoD requires heartbeat to be "visible (worker_id UUID, status, last_heartbeat_at TIMESTAMPTZ) and updated per tick" and requires the Doc 8 §27 minimum metric set to "emit". Both imply durable, queryable, cross-process storage — a monitor detecting a crashed worker by heartbeat age (Doc 8 §15) must read a store, and the current `NullHeartbeatPublisher` (`core/Workers/NullHeartbeatPublisher.php`) discards records.

**The conflict:** No `system.worker_heartbeats` / `system.worker_metrics` table (or any heartbeat/metrics DDL) exists in the frozen schema, and none is present in the ARCHITECTURE_DECISIONS.md Implications table. OPEN-3 froze only the expansion of `system.dead_letter_jobs`. Doc 8 §15 (heartbeat field list) and §27 (metric list) describe *intent* only — no column-level DDL was ever frozen the way OPEN-8 froze schema/module/security tables.

The OPS-S1 session brief permits a new migration "ONLY if OPEN-3 v1.1 DLQ schema requires a column not yet present." Migration `0004_create_system_dead_letter_jobs.sql` already carries all four OPEN-3 columns (`stack_trace`, `attempt_count`, `worker_id`, `payload_snapshot`), so that clause is **not** triggered. A heartbeat/metrics table is a *separate* table, outside that narrow authorization. Writing it without a ruling would violate the CLAUDE.md freeze rule (§5: never silently resolve a conflict with a frozen doc; a migration diverging from a frozen ruling may not be left in the tree).

**Ruling needed:** (a) Freeze a heartbeat/metrics table contract (an OPEN-N / ADR analogous to OPEN-3), including whether it is one table or two (`worker_heartbeats` vs `worker_metrics`), the exact column set and types (Doc 8 §15 lists `worker_id, status, started_at, last_heartbeat_at, current_job, memory_usage, processed_count`; §27 lists `jobs_processed, jobs_failed, jobs_retried, jobs_dead_lettered, average_processing_time, memory_usage, worker_uptime`), and PK/upsert semantics (one row per `worker_id`, upserted per tick, vs append-only history). (b) Confirm the type canon applies (`worker_id UUID`, all timestamps `TIMESTAMPTZ`). (c) Authorize a new migration `0012_*` under this new ruling, OR direct a non-DDL alternative (in-process/log-only publisher) and accept the weaker "visible" semantics. (d) Confirm which container binding the real `HeartbeatPublisher` writes through — the connection topology is FROZEN pending FLAG-P1AS6D-1, so the publisher must reuse an existing FORCE_NEW handle via constructor injection (ADR-012); a ruling is needed on *which* one (delivery `DatabaseConnectionInterface` per DECISION K is a candidate, but heartbeat is system-side DML, not delivery — this may collide with the same relay/queue-vs-delivery split that produced FLAG-P1AS6D-1).

**Resolution trigger:** Architect freezes the heartbeat/metrics table DDL (or rules out a table) and names the connection binding. Until then, the heartbeat "visible/updated per tick" and metrics "emit" DoD items cannot be built without improvising a frozen-schema change.

---

### FLAG-OPSS1-2 — Metric counters have no source of truth in the current pipeline

**Raised:** 2026-07-11 | **Session:** OPS-S1 (pre-implementation) | **Status:** Resolved — DECISION Q (ARCHITECTURE_DECISIONS.md v1.16, 2026-07-11)

**Resolution (architect ruling 2, 2026-07-11):** DECISION Q defines the producer as (1) on-demand PostgreSQL aggregates for derived metrics (queue depth, DLQ depth, oldest-pending age, worker count) and (2) structured worker log events for runtime counters (processed/retry/failure/replay). No metrics table, no rollups, no `average_processing_time` timing column on the frozen `system.queue_jobs` schema, no external telemetry backend in MVP. The "no source of truth" gap is closed by defining the source as on-demand queries + log emission rather than a persisted counter sink.

The Doc 8 §27 minimum metric set (`jobs_processed`, `jobs_failed`, `jobs_retried`, `jobs_dead_lettered`, `average_processing_time`, `memory_usage`, `worker_uptime`) has no producer today. `EventWorkerStrategy::execute()` calls `complete()` / `release()` / `deadLetter()` on the queue but increments no counters, records no per-job timing, and the `WorkerEngine` tick loop measures no duration. There is no metrics sink.

**Ruling/direction needed:** (a) Where are counters accumulated — in-process on the `WorkerEngine` (reset per process, exposed via heartbeat row) or derived by aggregate query over `system.queue_jobs` / `system.dead_letter_jobs` (e.g. `jobs_dead_lettered = COUNT(*) FROM system.dead_letter_jobs`)? (b) Is `average_processing_time` a rolling in-process average or a stored per-job timing column (which would need the queue_jobs schema — frozen — to gain a column, itself a freeze conflict)? (c) Does the metric set persist to the same table decided in FLAG-OPSS1-1, or emit to logs/statsd only? These are coupled to FLAG-OPSS1-1's table decision and cannot be built until it is ruled.

---

### FLAG-OPSS1-3 — Live crash→visibility-timeout requeue has no runtime driver

**Raised:** 2026-07-11 | **Session:** OPS-S1 (pre-implementation) | **Status:** Resolved — DECISION R (ARCHITECTURE_DECISIONS.md v1.16, 2026-07-11)

**Resolution (architect ruling 3, 2026-07-11):** DECISION R names `MaintenanceWorkerStrategy` the runtime driver for `requeueTimedOut()`, un-stubbed in OPS-S1 (scope `core/Workers/`). Cadence is configuration-driven with a sensible default — no hardcoded timing values — following the OPEN-4 config-driven-timeout precedent. The recovery loop uses the worker-runtime handle (DECISION L Ruling 0), not a new binding. The DoD is satisfied by the maintenance-tick driver plus the integration test exercising `requeueTimedOut()`; no full production scheduler (WP-Cron/systemd wiring) is pulled into OPS-S1.

`DatabaseQueueProvider::requeueTimedOut($queueName)` is implemented and unit/integration-tested, but nothing invokes it on a runtime loop. `MaintenanceWorkerStrategy` (the natural home, per its own STUB comment) is a stub returning `false`; no scheduler, no WP-Cron fallback, and no worker tick calls `requeueTimedOut()`. The DoD requires a *simulated worker crash triggers visibility-timeout requeue, proven by integration test* — the proof is straightforward (claim, let visibility expire, call `requeueTimedOut`, assert re-`available`), but a **runtime** driver must own the recovery call in production.

**Ruling/direction needed:** (a) Does OPS-S1 implement `MaintenanceWorkerStrategy::execute()` to call `requeueTimedOut()` for each partition on a cadence (in scope: `core/Workers/`), and if so what cadence/config key? (b) Is the DoD satisfied by an integration test exercising `requeueTimedOut()` directly plus a thin maintenance-tick driver, or is a full production scheduler expected (which would pull in WP-Cron/systemd wiring that reads as out-of-scope for OPS-S1)? (c) Which connection binding does the maintenance/requeue loop use (same FROZEN-topology question as FLAG-OPSS1-1(d))?

---

### FLAG-OPSS1-4 — Single-event replay entry point and DLQ-inspect tooling surface unspecified

**Raised:** 2026-07-11 | **Session:** OPS-S1 (pre-implementation) | **Status:** Resolved — DECISION S (ARCHITECTURE_DECISIONS.md v1.16, 2026-07-11)

**Resolution (architect ruling 4, 2026-07-11):** DECISION S rules: (a) surface is **WP-CLI only** (`hsp dlq list | inspect | replay`), no admin UI — sidestepping the TBD WP-boundary coding-standard question. (b) DLQ rows are permanent audit records, never deleted; replay stamps a new `replayed_at TIMESTAMPTZ NULL` column. That column is **absent** from the OPEN-3 v1.1 schema (verified against migration 0004, which carries only the four OPEN-3 deltas) — adding it via a forward migration is authorized in OPS-S1 scope (0004 not edited). (c) Replay runs in ONE PG transaction: verify DLQ row exists → verify `replayed_at IS NULL` → **DELETE any `system.queue_jobs` row sharing the `event_id`** → INSERT fresh job `attempts = 0` → stamp `replayed_at`. The DELETE step is what defeats the `UNIQUE(event_id)` silent-no-op trap this flag identified: DECISION L (d) retains completed/dead-lettered rows, so a naive re-`enqueueIdempotent()` would `DO NOTHING`; clearing the prior row first makes the fresh insert take effect. Replay re-enters via the normal queue/claim path and passes the DECISION J Resolve-stage stale guard — an already-current aggregate acks with zero projection writes, which is correct behavior.

`ReplayWorkerStrategy` is a P0-S6 stub returning `false`. The brief requires replay to "re-enter the pipeline through the normal queue path (enqueueIdempotent / existing claim semantics) — never write projections directly." `enqueueIdempotent(eventId, queueName)` exists and is the correct re-entry primitive. However the operational *surface* is unspecified:

**Ruling/direction needed:** (a) What triggers a single-event replay — a WP-CLI command, a WP admin action, or a plain PHP tool under `headless-sync/tools/` (like `smoke_e2e.php`)? The brief lists "admin/CLI DLQ tooling" in scope but does not pick one; coding-standard/WPCS enforcement at the WP boundary is still TBD (CLAUDE.md), which affects an admin-page implementation. (b) On replay, is the DLQ row deleted, marked replayed, or left intact (audit)? `system.dead_letter_jobs` has no `status`/`replayed_at` column — marking it would be another frozen-schema change (ties to FLAG-OPSS1-1). (c) Does single-event replay re-`enqueueIdempotent()` the existing `event_id` (relying on the DECISION J Resolve-stage stale guard to no-op an already-current aggregate, and UNIQUE(event_id) to dedupe against a still-present queue row) — confirming the brief's "stale replay acking with zero writes is correct" — and is that the *entire* required behavior, or must replay also clear the completed/dead-lettered queue_jobs row so a fresh job can be claimed? (The existing `system.queue_jobs` UNIQUE(event_id) from migration 0011 means a replay of an event whose prior job row still exists will `DO NOTHING` — replay would then be a silent no-op unless the prior row is first cleared. This interaction must be ruled before building replay.)

---

### FLAG-P1AS6D-1 — Dispatcher connection topology: no relay/queue handle exposed separately from DECISION K delivery singleton

**Raised:** 2026-06-25 | **Session:** P1A-S6d | **Status:** Resolved — DECISION L Ruling 0 (ARCHITECTURE_DECISIONS.md v1.16, 2026-07-11)

**Resolution (architect ruling 0, 2026-07-11):** The four-connection topology is **ratified and FROZEN as final** — relay (`outbox.connection.pgsql`), queue/worker runtime (`queue.connection.pgsql`), delivery (`DatabaseConnectionInterface`, DECISION K), dispatcher (`dispatcher.connection.pgsql`, DECISION L). Answer to (a): **yes** — the fourth FORCE_NEW dispatcher handle is accepted as a pragmatic, ratified extension of the DECISION E (v1.6) temporary-duplication allowance. Answer to (b)/(c): consolidation remains a future-ADR concern, not required now; DECISION L is amended to record the four-handle topology explicitly. **No fifth handle may ever be introduced without a new ADR.** Heartbeat publication (DECISION P) reuses the existing worker-runtime handle and adds no new handle/class/pg_* wrapper.

After P1A-S6c (`DeliveryServiceProvider`) moved the `DatabaseConnectionInterface` singleton to the dedicated delivery FORCE_NEW handle, there is no container binding that exposes the relay/queue runtime PG handle separately from that delivery singleton.

The Dispatcher (relay/queue-side system DML) must NOT use the delivery singleton (DECISION K). Because no relay/queue-side handle was exposed as a resolvable binding, `DispatcherServiceProvider` opens its own third FORCE_NEW handle bound as `'dispatcher.connection.pgsql'`. This resolves the immediate connection isolation requirement and is what was built in P1A-S6d.

However, this is a connection-topology decision beyond the original P1A-S6d ruling:

- The accepted connection set after S6c was: relay handle (`outbox.connection.pgsql`), queue-claim handle (`queue.connection.pgsql`), delivery handle (`DatabaseConnectionInterface`).
- P1A-S6d adds a fourth handle: `'dispatcher.connection.pgsql'`.
- DECISION E v1.6 accepted three handles as "temporary duplication" and authorized consolidation in P0-S7. A fourth handle extends that accepted duplication without a new ruling.

**Ruling needed:** (a) Is opening a fourth FORCE_NEW handle for the Dispatcher accepted as a pragmatic extension of the DECISION E temporary-duplication allowance? (b) Should `'dispatcher.connection.pgsql'` eventually share the relay/queue handle once that handle is exposed as a container binding? (c) Does this topology require an amendment to DECISION E or DECISION K, or is it subsumed by DECISION L?

**Resolution trigger:** Architect ruling on the fourth-handle question. If accepted, update DECISION L to explicitly record the four-handle topology. If consolidation is preferred, expose `'outbox.connection.pgsql'` as a container binding and rewire `DispatcherServiceProvider` to resolve it.

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

### FLAG-GATES1-1 — Entity and date-range replay modes not implemented; GATE-S1 criterion 2 fails

**Raised:** 2026-07-12 | **Session:** GATE-S1 | **Status:** OPEN — direction (a) actioned: OPS-S2 opened to implement entity + date-range replay, but OPS-S2 itself STOPPED at pre-implementation on a deeper blocker → **FLAG-OPSS2-1**. GATE-S1 criterion 2 stays BLOCKED until OPS-S2 ships under an architect ruling on FLAG-OPSS2-1.

**Update (2026-07-12, OPS-S2):** Direction (a) below was actioned — a dedicated non-gate session (OPS-S2) was opened to build entity + date-range replay, and the Session Map row + GATE-S1 Depends-on were amended (now `OPS-S1, OPS-S2`). Before writing code, OPS-S2 hit a CRITICAL STOP CONDITION: direction (a)'s stated plan ("re-enqueue lifecycle analogous to DECISION S for each selected event, **passing the DECISION J stale guard**") does not actually hold for entity/date-range replay. Those modes target **already-successfully-processed** aggregates, whose `latest_processed_version` already equals the event's `aggregate_version`; the DECISION J guard therefore suppresses the re-enqueued event (`<=` → ack, zero writes) and **no reprojection occurs**. DLQ replay escapes this only because dead-lettered events were never processed (version still ahead of the guard). The reprojection mechanism is unspecified in Doc 4 §24 and the three governing constraints are mutually incompatible — see **FLAG-OPSS2-1** for the full analysis and the three options the architect must choose between. No replay code was written.

**What the gate requires (IMPLEMENTATION_PLAN.md §4 → Reliability Validation, criterion 2):**
> Replay succeeds for single event, entity, and date-range replay modes

**What exists:** Only **single-event** replay. Verified against current code:
- `core/Workers/Strategies/ReplayWorkerStrategy.php` is a **stub** (`execute()` returns `false`;
  comment: *"STUB — OPS-S1: implement single-event and entity replay modes."*).
- `core/Queue/DeadLetterRepository::replay(string $dlqId)` and the WP-CLI `hsp dlq replay <id>`
  (`core/Cli/DlqCommand::replay`) operate on a **single DLQ row id → single event_id** only
  (DECISION S lifecycle). There is no `replayEntity()` / `replayDateRange()` method anywhere in
  `core/`, `modules/`, or `tools/`.
- Doc 4 §24 lists four replay modes (Single Event, Entity, Date Range, Full) as *architecture*,
  but OPS-S1 shipped single-event only. The OPS-S1 Session Map row and Doc 11 §8 scope
  single-event + entity replay; **entity replay was not actually built**, and date-range is
  out of the Early Operational Baseline scope entirely.

**Note on the gate brief's parenthetical:** the GATE-S1 brief stated *"OPS-S1 shipped
single-event + entity only per Doc 4 §24 — verify."* Verification shows this is inaccurate:
**OPS-S1 shipped single-event only.** Entity replay is also absent.

**Why this is a STOP-and-flag, not a fix:** The gate session's scope is *test evidence only* —
"no production code changes unless a validation check fails (a failure is a STOP-and-flag, not a
fix-in-session)." Building entity + date-range replay is a feature-bearing change to `core/`
(`ReplayWorkerStrategy`, new repository methods, likely a replay-scope query over `system.events`
by aggregate / date range, and CLI surface). Per CLAUDE.md, replay features are not built in a
gate session, and the missing modes are not derivable from a frozen ruling.

**Ruling/direction needed:**
- (a) Authorize a dedicated (non-gate) session to implement **entity replay** and **date-range
  replay** (Doc 4 §24), including: the replay-scope selection query (all events for an aggregate;
  all events in a `[from, to]` window), the single-PG-transaction re-enqueue lifecycle
  (analogous to DECISION S for each selected event, passing the DECISION J stale guard), and the
  WP-CLI surface (`hsp replay entity <type> <id>` / `hsp replay range <from> <to>` or equivalent).
- (b) Confirm whether **Full Replay** (Doc 4 §24) is in or out of MVP gate scope (Phase 1A lists
  advanced replay under Phase 3 — the gate may accept single/entity/date-range and defer Full).
- (c) Until (a) ships and GATE-S1 criterion 2 turns PASS, the Architecture Validation Gate cannot
  pass; Phase 2 remains blocked.

**Resolution trigger:** An authorized session implements entity + date-range replay with named,
passing integration tests landing the correct final projection state for each mode; GATE-S1
criterion 2 is then re-run and flips to PASS.

---

## Session Log

<!-- Append one line per session: YYYY-MM-DD | session ID | what shipped | flags raised -->

2026-07-12 | OPS-S2 (pre-implementation) | Opened Replay Engine session; inserted OPS-S2 row in IMPLEMENTATION_PLAN.md §5b (after OPS-S1) and amended GATE-S1 Depends-on → "OPS-S1, OPS-S2". STOPPED before writing any replay code — hit CRITICAL STOP CONDITION: Doc 4 §24 does not specify how entity/date-range replay of already-processed aggregates interacts with the binding DECISION J stale guard (`version <= stored → ack, zero writes` suppresses reprojection). | **Raised FLAG-OPSS2-1** (architect ruling required: choose reprojection mechanism — Option A fresh synthetic event / B guard bypass / C version-state reset — and authorize any schema change). No code written; DoD not met by design (blocked on ruling).
2026-07-12 | GATE-S1 | Architecture Validation Gate — Reliability Validation (evidence-only gate session, no production code changed). IMPLEMENTATION_PLAN.md §5b: added GATE-S1..GATE-S4 rows (Reliability/Scalability/Operability/Extensibility, criteria copied verbatim from §4; GATE-S1 depends-on OPS-S1, each subsequent gate depends on the prior); §4 not altered. New test tests/Integration/Gate/ReliabilityValidationTest.php (4 tests, live MySQL 127.0.0.1:10053 + live PG 127.0.0.1:5432): criterion 1 (successful sync under normal load) PASS — proven end-to-end by assembling the REAL runtime pipeline (RelayWorkerStrategy → EventDispatcher → EventWorkerStrategy → ContentSubscriber → Page/Post/CategoryAdapter) over a 12-aggregate mixed batch (6 posts + 4 pages + 2 categories): outbox → relay → system.events → dispatch → system.queue_jobs → worker drain → content.* projection, with completed/aggregate_versions/processed_events all correct and zero dead-letters; plus an update-reprojection test (version advance, idempotent upsert). Criterion 3 (DLQ recovery to correct final state) PASS — retry-limit exhaustion → system.dead_letter_jobs with full OPEN-3 context (stack_trace, payload_snapshot) → DeadLetterRepository::replay (the `hsp dlq replay` path, DECISION S lifecycle) → healthy worker drives replayed job to correct final content.posts projection → replayed_at stamped, DLQ row retained. Criterion 2 (single + entity + date-range replay) FAIL/BLOCKED — test_criterion2 proves single-event replay present and entity + date-range modes ABSENT (ReplayWorkerStrategy stub; DeadLetterRepository/DlqCommand single-DLQ-id only), marked Incomplete = STOP-and-flag per gate brief + CLAUDE.md freeze rule. Only substitution on the pipeline is GateReloadingLoader (WP state-reload boundary, DECISION H, ADR-044) — relay/dispatch/worker/adapters/PG are the real components. STATUS.md: gate checklist added with per-criterion PASS/FAIL + named tests; Reliability item marked BLOCKED (not done — DoD unmet). Full suite: 852 tests, 1908 assertions, 0 failures, 0 errors, 1 pre-existing PHPUnit deprecation (P0-S5 carry), 1 intentional Incomplete (GATE-S1 criterion 2). | FLAG-GATES1-1 raised (entity + date-range replay not implemented — GATE-S1 criterion 2 fails; STOP-and-flag; blocks the Architecture Validation Gate; needs an authorized non-gate session to build entity + date-range replay per Doc 4 §24). GATE-S2 does NOT begin until resolved.
2026-07-12 | OPS-S1 | Early Operational Baseline shipped. (1) Migrations: 0012_create_system_worker_heartbeats.sql (DECISION P frozen DDL — single current-state table, worker_id UUID PK, worker_type/status TEXT, last_heartbeat_at/started_at TIMESTAMPTZ) + 0013_add_replayed_at_to_dead_letter_jobs.sql (DECISION S clause (e) — replayed_at TIMESTAMPTZ NULL forward-add; 0004 untouched); both registered in MigrationServiceProvider migrations.core; applied + idempotency-proven on live PG (hsp DB), column shapes verified. (2) DatabaseHeartbeatPublisher (implements existing HeartbeatPublisherInterface; ctor-injected connection = worker-runtime handle 'queue.connection.pgsql' per DECISION L Ruling 0 — no new handle/class/pg_* wrapper; INSERT…ON CONFLICT(worker_id) DO UPDATE upsert per tick, µs-precision TIMESTAMPTZ so advances are visible); HeartbeatRecord extended with worker_type/started_at (defaulted, back-compat); WorkerEngine carries workerType + startedAt; WorkerServiceProvider swaps NullHeartbeatPublisher→DatabaseHeartbeatPublisher on runtime path. (3) MaintenanceWorkerStrategy un-stubbed — drives requeueTimedOut() per partition on a config-driven cadence (config/worker.php maintenance.recovery_interval_seconds default 30; no hardcoded timing at call site; 'worker' added to ConfigLoader CONFIG_FILES); worker.engine.maintenance bound. (4) Derived-metrics surface OperationalMetricsQuery (queue depth total+per-partition, DLQ depth, oldest-pending age, worker count — on-demand SQL aggregates, no metrics table, DECISION Q) + WorkerCounters (processed/retry/failure/replay in-process) + StructuredLogger (JSON metric lines, error_log sink) emitted by WorkerEngine on worked ticks; EventWorkerStrategy increments counters at complete/release/deadLetter; a successful WP-CLI `hsp dlq replay` emits the `replay` counter as a structured `dlq.replay` log line (event_id + ts) from DlqCommand (StructuredLogger ctor-injected, ADR-012) — replay runs outside the WorkerEngine tick loop so the emission lives there. (5) DLQ read/replay: DeadLetterRepository (list/inspect/replay — single-PG-txn lifecycle verify-exists FOR UPDATE → verify replayed_at IS NULL → DELETE queue row by event_id → INSERT fresh job attempts=0 → stamp replayed_at; DLQ rows never deleted) + DeadLetterReplayException; WP-CLI DlqCommand + WpCliDlqRegistrar ('hsp dlq list|inspect|replay', 'hsp status'), registered in headless-sync.php under WP_CLI guard (in-scope CLI registration). Tests: 19 new unit (MaintenanceWorkerStrategy cadence, DatabaseHeartbeatPublisher SQL, DeadLetterRepository lifecycle+guards, DlqCommand incl. replay-emits-structured-log + failed-replay-emits-nothing, WorkerCounters/StructuredLogger) + OperationalBaselineIntegrationTest (7 live-PG: DLQ populate w/ OPEN-3 context, replay→fresh-claimable-job through UNIQUE(event_id) + naive-no-op trap proven + double-replay rejected, stale replay = ack+zero writes, heartbeat visible+advances per tick, crash→visibility-timeout→requeue THROUGH real WorkerEngine+MaintenanceWorkerStrategy runtime driver, derived metrics, structured counter emission). Full suite: 848 tests, 1864 assertions, 0 failures, 0 skipped (MySQL 127.0.0.1:10053 + PG 127.0.0.1:5432 live), 1 pre-existing PHPUnit deprecation carried from P0-S5. ADR-012 clean (no service-locator in new business logic); DECISION 3 atomicity untouched; four-connection topology unchanged. | no new flags. FLAG-OPSS1-1..4 + FLAG-P1AS6D-1 remain Resolved (built to DECISIONS P/Q/R/S + Ruling 0).
2026-07-11 | housekeeping | Recorded architect's 2026-07-11 OPS-S1 rulings; flags resolved. ARCHITECTURE_DECISIONS.md bumped to v1.16: DECISION L amended with Ruling 0 (four-connection topology FROZEN as final — relay/queue-worker/delivery/dispatcher; no fifth handle without new ADR; heartbeat on existing worker-runtime handle); new DECISION P (Worker Heartbeat Storage — single current-state `system.worker_heartbeats` table, upsert per tick, DatabaseHeartbeatPublisher via existing interface + ctor injection, migration authorized); DECISION Q (Metrics Without Persistence — no metrics table; derived-on-demand + structured logs; "metrics emit" DoD defined); DECISION R (Visibility-Timeout Recovery Driver — MaintenanceWorkerStrategy drives requeueTimedOut(), config-driven cadence); DECISION S (DLQ Replay Lifecycle — permanent audit rows, one-txn verify→delete-queue-row→insert-attempts-0→stamp replayed_at, passes DECISION J guard, WP-CLI only; `replayed_at` confirmed absent from migration 0004, forward-migration add authorized). Implications table updated (system.worker_heartbeats, dead_letter_jobs.replayed_at, PHP contracts rows). STATUS.md: FLAG-P1AS6D-1 + FLAG-OPSS1-1..4 marked Resolved with ruling refs; OPS-S1 pointer unblocked. IMPLEMENTATION_PLAN.md §5b OPS-S1 row: added DECISION P/Q/R/S refs to Authority; widened migration clause. NO code, NO migrations written. | flags resolved: FLAG-P1AS6D-1, FLAG-OPSS1-1, FLAG-OPSS1-2, FLAG-OPSS1-3, FLAG-OPSS1-4.
2026-07-11 | OPS-S1 (pre-implementation review) | No code written. Read STATUS.md, IMPLEMENTATION_PLAN.md §5b OPS-S1 row, ARCHITECTURE_DECISIONS.md OPEN-3/OPEN-4/DECISION E/K/L, Doc 8 §15/§27, Doc 4 §24. Audited existing surface: DLQ schema (0004) complete with all four OPEN-3 columns; DatabaseQueueProvider has deadLetter()/requeueTimedOut()/enqueueIdempotent(); EventWorkerStrategy dead-letters at attempts>=retryLimit; ReplayWorkerStrategy + MaintenanceWorkerStrategy are stubs; HeartbeatPublisher is NullHeartbeatPublisher (discards); no heartbeat/metrics table exists in frozen schema or Implications table; no metric counters produced anywhere; nothing invokes requeueTimedOut() on a runtime loop. STOPPED before implementation and recorded all ruling-needed conflicts as open flags per instruction. | FLAG-OPSS1-1 (heartbeat/metrics table never frozen — blocks heartbeat+metrics DoD; needs OPEN-N-style DDL freeze + connection-binding ruling, entangled with frozen topology / FLAG-P1AS6D-1); FLAG-OPSS1-2 (metric counters have no source of truth); FLAG-OPSS1-3 (crash→visibility-timeout requeue has no runtime driver — MaintenanceWorkerStrategy stub); FLAG-OPSS1-4 (replay entry-point + DLQ-inspect surface unspecified; UNIQUE(event_id) on queue_jobs makes naive re-enqueue a silent no-op unless prior job row cleared — must be ruled).
2026-06-25 | P1A-S8 | shipped (DECISION O v1.15 credential resolution: define→getenv→default, fail-loud, MySQL inherits WP DB_*; bootstrap/CredentialResolver + 25 unit tests; four runtime providers + MigrationServiceProvider rewired to resolver via constructor injection; Environment::overrides() DB keys removed; wp-config define()s; FLAG-P1AS8-1 found+fixed at source — dbname/name mismatch, migration engine now connects live; topology + test injection unchanged; 822 tests + smoke 39/39 + live migration connect green) | FLAG-P1AS8-1 closed; FLAG-P1AS6D-1 open (unchanged); no new flags.
2026-06-25 | P1A-S7 | REST namespace rename: api/v1 → hsp/v1. DECISION N recorded (ARCHITECTURE_DECISIONS.md v1.14). ContentRestRegistrar::NAMESPACE = 'hsp/v1'; all six register_rest_route() calls reference constant. hsp-blog/lib/api.ts: six fetch paths updated. smoke_e2e.php: four curl/echo strings updated. Doc reconciliation: DECISION F Implements table, IMPLEMENTATION_PLAN.md §4 endpoint bullets + pipeline diagram + P1A-S5/S6a session rows, Phase 1A DoD Next.js bullets, docs/09-delivery-api-and-consumption-architecture.md §7/§17 examples, FLAG-P1AS5-1 resolved. P1A-S7 + P1A-S8 rows inserted in Session Map; OPS-S1 Depends-on updated to P1A-S8. PHPUnit: 710/710 unit tests pass, 0 failures, 1 pre-existing deprecation. grep api/v1 in modules/, tests/, hsp-blog/, tools/ = 0 hits; docs/ = 4 meta-references in DECISION N text itself (Supersedes/Rationale/amendment log) + P1A-S7 session row name — all intentional. | FLAG-P1AS5-1 resolved.
2026-06-25 | P1A-S6 | shipped (live PG migration apply 16 migrations; smoke_e2e.php dispatcher-tick + grep/BOM/replay fixes; 39/39 smoke; 797/797 tests; DoD-4 via AdapterAtomicityIntegrationTest 11/11, DoD-5 worker-level via HandlerSpineIntegrationTest, DoD-2 inline-tick latency ~50–160ms, production loop latency deferred to OPS-S1) | flags: FLAG-P1AS6D-1 open (carry-over).
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
2026-06-24 | P1A-S6c | Delivery connection isolation (DECISION K). Shipped: DeliveryServiceProvider (core/Container/Definitions/DeliveryServiceProvider.php — PGSQL_CONNECT_FORCE_NEW, wraps PostgresDatabaseConnection, no new pg_* wrapper); QueueServiceProvider DatabaseConnectionInterface singleton binding removed; ContainerBuilder registers DeliveryServiceProvider before WorkerServiceProvider and ContentServiceProvider; DeliveryConnectionIsolationTest (5 tests, 23 assertions — DoD-4 items i–v: PID-verified physical handle distinctness via SELECT pg_backend_pid() on each handle (relay PID ≠ delivery PID, delivery PID stable across sequential reads); Resolve-stage reads via delivery connection; relay uncommitted INSERT cannot expose state or block a Resolve-stage read (non-blocking wall-clock bound < 1 s confirmed; B(3) synthetic lock-contention negative control — throwaway row INSERT+COMMIT, relay UPDATE holds row lock, delivery SELECT FOR UPDATE blocked and cancelled by statement_timeout=300ms, SQLSTATE 57014 "statement timeout" confirmed, elapsed >= 300 ms); query provider executes via delivery connection; adapter persist under DECISION 3 txn boundary). DECISION K recorded in ARCHITECTURE_DECISIONS.md v1.11 (constrains DECISION E, satisfies DECISION J). IMPLEMENTATION_PLAN.md §5b: P1A-S6c inserted, P1A-S6 Depends-on = P1A-S6c. Suite: 789/789, 0 failures, 0 skipped (live DB). FLAG-P1AS6A-1 resolved. | no new flags.
2026-06-25 | P1A-S6d | Dispatcher stage (system.events → system.queue_jobs, DECISION L v1.12→v1.13). Shipped: DECISION L recorded (v1.12, 2026-06-25) + P1A-S6d inserted in IMPLEMENTATION_PLAN.md §5b before P1A-S6; migration 0011_add_unique_event_id_to_queue_jobs.sql (UNIQUE(event_id) on system.queue_jobs — frozen 0003 untouched); DatabaseQueueProvider::enqueueIdempotent() (ON CONFLICT(event_id) DO NOTHING); QueueServiceProvider DatabaseQueueProvider concrete alias; DispatchBatch, EventDispatcher (anti-join claim: NOT EXISTS + FOR UPDATE SKIP LOCKED + LIMIT N; queue name hardcoded 'content'; no event_type-prefix routing); DispatcherWorkerStrategy (WorkerStrategyInterface); DispatcherServiceProvider (own FORCE_NEW 'dispatcher.connection.pgsql' — NOT DatabaseConnectionInterface/DECISION K delivery handle, no new pg_* wrapper class); ContainerBuilder registers DispatcherServiceProvider after QueueServiceProvider. Integration tests: DispatcherIntegrationTest (8 tests — happy path, empty-queue false-return, NOT EXISTS idempotency, completed-event-not-redispatched, SKIP LOCKED concurrency, relay→dispatcher→queue link (MySQL+PG), worker-claim routing proof, FORCE_NEW PID-distinctness assertion). Blockers resolved: (1) CONNECTION — dispatcher opens own FORCE_NEW handle, does not reuse delivery singleton; (2) QUEUE ROUTING — prefix routing removed, hardcoded 'content'; (3) TEST ISOLATION — all integration test tearDown()s fixed: $pgConn = null after pg_close(); AdapterAtomicityIntegrationTest::createSchema() adds defensive DROP SCHEMA at start to eliminate cross-run residue. Suite: 797 tests, 1716 assertions, 0 failures, 0 skipped, 1 pre-existing deprecation — confirmed deterministically green on two consecutive cold runs with both MySQL (127.0.0.1:10053) and PG (127.0.0.1:5432) live. ARCHITECTURE_DECISIONS.md bumped to v1.13 (amendment-log v1.12 summary corrected; v1.13 entry records reconciliation). | FLAG-P1AS6D-1 raised: no relay/queue runtime PG handle exposed as container binding post-S6c; dispatcher opens its own fourth FORCE_NEW handle ('dispatcher.connection.pgsql') — connection-topology decision pending architect ratification (DECISION K follow-up).
2026-06-24 | P1A-S6b | Shipped: WpContentLoader contract + WpContentLoaderImpl (get_post/get_post_meta/get_term/wp_get_post_terms); AdapterInterface::tombstone() + PageAdapter/PostAdapter/CategoryAdapter tombstone impls (soft-delete, DECISION I, DECISION 3 atomicity, deleted_at = source_updated_at); WpContentLoaderImpl shape matched by FakeWpContentLoader (all extractor-consumed keys identical); ContentSubscriber (9-type routing, OPEN-1 types, RuntimeException on missing handler); ContentUpsertHandlerInterface; Page/Post/CategoryUpsertHandler (loader→extractor→transformer→adapter pipeline, DECISION H Option B); Page/Post/CategoryTombstoneHandler (event-envelope-only, no WP reload); EventWorkerStrategy::executeHandler() un-stubbed (EventRegistry handler dispatch); EventWorkerStrategy Resolve-stage stale guard added (PRIMARY gate, DECISION J Layer 1 — non-locking SELECT on system.aggregate_versions, <=stored → ack + zero writes); WorkerServiceProvider wired DatabaseConnectionInterface to EventWorkerStrategy (DECISION E, no new pg_connect, queue FORCE_NEW handle not entangled); ContentModule + ContentServiceProvider wired all 9 handlers + ContentSubscriber into EventRegistry. Integration tests: HandlerSpineIntegrationTest (12 tests — persist×3, tombstone×4, idempotent re-delivery×2, adapter GREATEST guard×1, subscriber routing×2; adapter stale write-set assertions strengthened: stale event's own processed_events row proven by ID, aggregate_versions row count asserted); ResolveStageGuardIntegrationTest (4 tests — zero-writes+no-handler+job-acked on stale event proven on live PG, non-stale does not fire, equal-version treated as stale, missing aggregate_versions row not stale). Unit tests: ContentHandlerTest (9), ContentSubscriberTest (3), adapter tombstone unit tests added to Page/Post/CategoryAdapterTest (6 each = 18 total). Suite: 784 tests, 1664 assertions, 0 failures, 0 errors, 0 skipped, 1 pre-existing deprecation. DECISIONS H/I/J recorded in ARCHITECTURE_DECISIONS.md (step 0 of session). FLAG-P1AS6A-1 blast radius extended: now covers worker Resolve-stage PG read path in addition to REST delivery; still open, still E2E-blocking. FLAG-P1AS6-1 fully resolved. | no new flags.
2026-06-23 | P1A-S4 | Shipped: modules/Content/Migrations/ (content schema; pages/posts/taxonomies/entity_taxonomies; TIMESTAMPTZ + VARCHAR(64) canon; entity_taxonomies pure join table) and modules/Content/Adapters/ (Page/Post/Category persist() — DECISION 3 three-op atomic txn; OPEN-11 Option A canonical-checksum write-suppress; in-txn lockAggregateVersion() FOR UPDATE guard; monotonic GREATEST() aggregate_versions; full-replace entity_taxonomies rewrite). bulkPersist() fail-fast LogicException stub (Phase 1A). Tests: unit adapter suites + live-PG atomicity/idempotency/join-rewrite/interleave integration. Item-6 TOCTOU race fixed (version guard moved inside txn behind FOR UPDATE). Suite 598/598. | Flags resolved: FLAG-P1AS4-1 (pure join table), FLAG-P1AS4-2 (monotonic guard), FLAG-P1AS4-3 (bulkPersist Phase 1A throwing stub) — all architect-ruled 2026-06-23.

