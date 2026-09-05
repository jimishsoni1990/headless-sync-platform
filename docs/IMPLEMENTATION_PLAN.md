# HSP Implementation Plan

**Version:** 1.0  
**Status:** Active  
**Scope:** Blog MVP only  
**Authoritative conflict resolution:** `docs/ARCHITECTURE_DECISIONS.md` and `CLAUDE.md` override this document and all Docs 1–11 where they conflict.

---

## 1. Goal & MVP Scope

### Goal

Deliver a production-capable Blog MVP that proves the full HSP synchronization pipeline end-to-end — WordPress editorial action through to a consumer-facing REST API backed by PostgreSQL — before introducing any commerce or domain complexity.

Source: PRD §7, Doc 11 §6.

### In Scope

| Area | Items |
|---|---|
| **Content** | Pages, Posts, Categories |
| **Platform pipeline** | Event system, outbox pattern, DB queue provider, worker engine, transformer pipeline, PostgreSQL delivery store, REST Delivery API |
| **Frontend validation** | Blog listing, single post, static pages (Next.js) |

### Out of Scope (MVP)

- WooCommerce, Membership, LMS, Directory, Booking modules
- GraphQL, OpenSearch, Typesense
- Redis as a hard requirement (optional cache layer only)
- Multi-site / multi-tenancy
- ACF, flexible content, tags, media sync, relationships (deferred to Phase 1B)
- Composition APIs, advanced filtering, caching enhancements (deferred to Phase 4)

Source: PRD §7 (Excluded), Doc 11 §6 (Explicitly Excluded).

---

## 2. Pipeline Summary

The resolved architecture (ARCHITECTURE_DECISIONS.md OPEN-6, DECISION 1) places the transactional outbox in MySQL and the relay copy in PostgreSQL. The diagram below reflects this — not the pre-resolution docs.

```text
WordPress Hook
      │
      ▼
Event Builder          (modules/Content/Events)
      │
      ▼
wp_hsp_outbox          ← MySQL transactional capture point (DECISION 1)
      │
      ▼  RelayWorkerStrategy — marks row 'relayed' only after PG commit
      │
      ▼
system.events          ← PostgreSQL durable relay copy (OPEN-6)
      │
      ▼
system.queue_jobs      ← DB queue (SKIP LOCKED claim, visibility timeout — OPEN-4)
      │
      ▼
Worker Engine (Core)   (stateless, ADR-044; UUIDv7 self-assigned worker_id)
      │
      ▼
Subscriber → Handler   (modules/Content/Subscribers, Handlers)
      │
      ▼
Extractor → Source Model → Validator   (modules/Content/Extractors, SourceModels, Validation)
      │
      ▼
Transformer            (modules/Content/Transformers — pure, no side effects)
      │
      ▼
Canonical Model        (modules/Content/CanonicalModels — delivery-target agnostic)
      │
      ▼
Postgres Adapter       (modules/Content/Adapters)
      │  ┌─ projection upsert ─┐
      │  │ processed_events    │  ← single PG transaction (DECISION 3)
      │  └─ aggregate_versions ┘
      ▼
content.pages / content.posts / content.taxonomies   (PostgreSQL delivery projections)
      │
      ▼
REST Delivery API      /hsp/v1/pages | /hsp/v1/posts | /hsp/v1/categories
      │
      ▼
Next.js Frontend       (blog listing / single post / static pages)
```

**Write-suppress rule (DECISION 3):** compare freshly-computed projection checksum against stored `content.*` checksum. Do not compare against the event's own checksum (traceability only).

---

## 3. Coding Standard

- **Plugin internals:** PSR-12.
- **WordPress integration boundary** (hooks, REST registration, `$wpdb` calls): additionally apply WPCS security rules — sanitize inputs, escape outputs, verify nonces.
- Coding standard TBD in `composer.json` (CLAUDE.md). Do not enforce either standard until confirmed; confirm before writing or enforcing style rules.

---

## 4. Phases

---

### Phase 0 — Foundation

**Objective:** Establish the entire platform infrastructure and freeze all schemas and contracts before any business-domain code is written. No module code may be written until the Phase 0 DoD gate passes.

**Source:** Doc 11 §5, Doc 2 (folder/namespace), ARCHITECTURE_DECISIONS.md (Implications table).

#### Deliverables

**FIRST TASK — Schema & Contract Freeze**

Before writing any PHP: produce and review migrations against the Implications table in `docs/ARCHITECTURE_DECISIONS.md`. Every table and column listed there must be present in a migration with types exactly matching the rulings. Nothing proceeds until the freeze check passes (see DoD Gate below).

**MySQL migrations** (`database/Core/`):

| Migration | Ruling |
|---|---|
| `wp_hsp_outbox` | OPEN-6 |
| `wp_hsp_aggregate_counters` — PK `(aggregate_type VARCHAR(100), aggregate_id VARCHAR(255))`, `version BIGINT`; atomic `INSERT … ON DUPLICATE KEY UPDATE version = LAST_INSERT_ID(version+1)` | DECISION 2 (v1.1) |

**PostgreSQL migrations** (`database/Core/`):

| Migration | Ruling |
|---|---|
| `system.events` with first-class columns: `aggregate_version BIGINT`, `source_updated_at TIMESTAMPTZ`, `checksum VARCHAR(64)`, `correlation_id UUID`, `causation_id UUID`; `event_type` accepting `<domain>.<aggregate>.<action>` | OPEN-5 (v1.1), OPEN-1 |
| `system.queue_jobs` + `worker_id UUID`, `visibility_timeout_at TIMESTAMPTZ` | OPEN-4 (v1.1) |
| `system.dead_letter_jobs` + `stack_trace TEXT`, `attempt_count INTEGER`, `worker_id UUID`, `payload_snapshot JSONB` | OPEN-3 (v1.1) |
| `system.aggregate_versions` — PK `(aggregate_type, aggregate_id)`, `latest_processed_version BIGINT`, `latest_processed_at TIMESTAMPTZ` | OPEN-2 |
| `system.processed_events` — PK `event_id`, `checksum VARCHAR(64)`, `processed_at TIMESTAMPTZ` | OPEN-7 (v1.1), DECISION 3 |
| `system.audit_log` | Doc 3 §23 |
| `system.schema_versions`, `system.module_versions`, `system.security_events` | OPEN-8 (v1.4) — DDL frozen; supersedes Doc 3 §4 intent-only description |

**Core platform** (`core/`):

- Service container & DI (PSR-11 compatible; constructor injection only — ADR-012; service-locator calls inside business logic are prohibited)
- Module registry, discovery (`modules/*/module.json`), lifecycle (`register → boot → activate → deactivate → upgrade`)
- Configuration system (`config/` hierarchy; environment overrides)
- Migration engine (versioned; tracks `system.schema_versions` and `system.module_versions`)

**All core contracts** (`core/Contracts/`):

`EventInterface`, `EventProviderInterface`, `CanonicalModelInterface`, `TransformerInterface`, `AdapterInterface` (persist() + bulkPersist() — DECISION D v1.4 / Doc 7 §19), `QueueProviderInterface`, `WorkerInterface`, `MigrationInterface`, `EntityProviderInterface`, `ServiceProviderInterface`, `ModuleInterface` (union shape: discovery + lifecycle — OPEN-9 v1.4, supersedes Doc 2 §12)

Source: Doc 2 §7.

**Outbox + relay** (`core/Events/Outbox/`, `core/Events/Dispatcher/`):

- `wp_hsp_outbox` write immediately after WordPress commit (DECISION 1; post-commit, not within WP transaction)
- `RelayWorkerStrategy`: SKIP LOCKED claim on outbox → insert to `system.events` → mark `relayed` only after PG commit
- Atomic aggregate-version counter via `INSERT … ON DUPLICATE KEY UPDATE` on `wp_hsp_aggregate_counters`

**DB queue provider** (`core/Queue/Providers/Database/`):

- `SELECT … FOR UPDATE SKIP LOCKED` claiming (OPEN-4, ADR-023)
- Visibility timeout column (`visibility_timeout_at TIMESTAMPTZ`) + recovery requeue
- Queue partitions: `content`, `commerce`, `system`

**Worker engine** (`core/Workers/`):

- Shared engine with pluggable strategies: `EventWorkerStrategy`, `ReplayWorkerStrategy`, `ReconciliationWorkerStrategy`, `MaintenanceWorkerStrategy`
- Standard execution pipeline: Claim → Load Event → Create `WorkerExecutionContext` → Validate → Resolve Subscriber → Execute Handler → Commit State → Acknowledge Job (Doc 8 §7)
- Stateless; UUIDv7 self-assigned at startup; heartbeat publication; graceful shutdown
- Workers managed externally (systemd / Supervisor / container); WP-Cron fallback only

**Event registry & adapter registry** (`core/Events/`, `core/Delivery/`):

- Explicit registration only; no reflection-based discovery
- Event validation before persistence (required fields, contract version, aggregate metadata, checksum, timestamp integrity)

#### Dependencies

None — this phase has no predecessors.

#### Definition of Done

**DoD Gate — Schema & Contract Freeze Check:**

1. Every table/column in the Implications table (`docs/ARCHITECTURE_DECISIONS.md` §"Implications Carried into Schema") is present in a generated migration with the exact type specified by the referenced ruling.
2. The `wp_hsp_aggregate_counters` migration uses `INSERT … ON DUPLICATE KEY UPDATE version = LAST_INSERT_ID(version+1)` (DECISION 2 v1.1).
3. No migration references `wp_postmeta._hsp_version` or `wp_termmeta._hsp_version` (superseded by DECISION 2 v1.1).
4. All PostgreSQL timestamp columns are `TIMESTAMPTZ`; all MySQL timestamp columns (`wp_hsp_outbox.created_at`, `wp_hsp_outbox.relayed_at`) are `DATETIME`-UTC. `TIMESTAMPTZ` must **not** appear in any MySQL migration (OPEN-3 v1.2).
5. All checksum columns are `VARCHAR(64)` (OPEN-3 amendment).
6. All worker-identity columns are `UUID` (OPEN-3 amendment).

Gate failure blocks all subsequent phases.

Additional DoD:
- Platform boots, modules register, infrastructure unit tests pass (Doc 11 §5 Success Criteria)
- All contracts implemented; DI container resolves all bindings without service-locator calls
- Relay worker successfully copies a test outbox row to `system.events` and marks it `relayed`
- `aggregate_version` counter increments atomically under concurrent test inserts (no duplicates)

---

### Phase 1A — Blog MVP

**Objective:** Validate the complete pipeline using the smallest possible domain (Pages, Posts, Categories). End-to-end sync under real WordPress editing must pass the 30-second SLA.

**Source:** PRD §7, §10 (Performance), Doc 11 §6, Doc 11 §24 (Success Metrics).

#### Deliverables

**Content module events** (`modules/Content/Events/`):

Fully-qualified event types (OPEN-1):

```
content.page.created   content.page.updated   content.page.deleted
content.post.created   content.post.updated   content.post.deleted
content.category.created  content.category.updated  content.category.deleted
```

WordPress hooks: `save_post`, `transition_post_status`, `wp_trash_post`, `after_delete_post`, `created_term`, `edited_term`, `delete_term`.

**Extractors & source models** (`modules/Content/Extractors/`, `modules/Content/SourceModels/`):

`PageExtractor → PageSourceModel`, `PostExtractor → PostSourceModel`, `CategoryExtractor → CategorySourceModel`. Extractors normalize `WP_Post` / `WP_Term`; source models are immutable and strongly typed. No canonical model creation in extractors.

**Validators** (`modules/Content/Validation/`): required fields, structural integrity; fail-fast on failure → retry workflow.

**Transformers** (`modules/Content/Transformers/`): `PageTransformer`, `PostTransformer`, `CategoryTransformer`. Pure functions — no DB calls, no API calls, no side effects. Produce canonical models.

**Canonical models** (`modules/Content/CanonicalModels/`): `CanonicalPage`, `CanonicalPost`, `CanonicalCategory`. Implement `CanonicalModelInterface`. Delivery-target agnostic.

**PostgreSQL adapters** (`modules/Content/Adapters/`): `PagePostgresAdapter`, `PostPostgresAdapter`, `CategoryPostgresAdapter`. Schema-aware (`content.*`). Support `persist()` and `bulkPersist()`. Three-operation atomic PG transaction per DECISION 3: projection upsert + `system.processed_events` insert + `system.aggregate_versions` upsert.

**Content PostgreSQL migrations** (`modules/Content/Migrations/`):

- `content.pages` — `id UUID PK`, `source_post_id BIGINT UNIQUE`, `source_entity_type VARCHAR(50)`, `slug VARCHAR(255)`, `uri VARCHAR(500)`, `title TEXT`, `status VARCHAR(50)`, `published_at TIMESTAMPTZ`, `updated_at TIMESTAMPTZ`, `deleted_at TIMESTAMPTZ NULL`, `checksum VARCHAR(64)`, `structure_jsonb JSONB`, `meta_jsonb JSONB`, `created_at TIMESTAMPTZ`, `synced_at TIMESTAMPTZ`. Indexes: slug, uri, status, published_at, updated_at. GIN: structure_jsonb, meta_jsonb.
- `content.posts` — same shape plus `excerpt TEXT`.
- `content.taxonomies` — `id UUID PK`, `source_term_id BIGINT UNIQUE`, `taxonomy_type VARCHAR(50)`, `slug VARCHAR(255)`, `name VARCHAR(255)`, `description TEXT`, `deleted_at TIMESTAMPTZ NULL`, `created_at TIMESTAMPTZ`, `updated_at TIMESTAMPTZ`.
- `content.entity_taxonomies` — `(entity_id UUID, taxonomy_id UUID) PK`.

Note: Doc 3 §9–11 shows bare `TIMESTAMP`; the OPEN-3 amendment (ARCHITECTURE_DECISIONS.md v1.2) supersedes this. All `content.*` PostgreSQL timestamp columns must be `TIMESTAMPTZ` and all checksum columns `VARCHAR(64)`. This is the v1.2 type canon applied platform-wide including module-owned tables.

**REST Delivery API** (`modules/Content/`):

- `GET /hsp/v1/pages` — listing with cursor pagination, slug/status/published_after filters
- `GET /hsp/v1/pages/{slug}` — single page by slug
- `GET /hsp/v1/posts` — listing with cursor pagination, category/status/published_after filters
- `GET /hsp/v1/posts/{slug}` — single post by slug
- `GET /hsp/v1/categories` — category listing
- `GET /hsp/v1/categories/{slug}` — single category by slug
- Query providers query `content.*` projections; endpoints do not query tables directly (Doc 9 §10). Resources handle serialization; no business logic in resources. Versioning from day one: `hsp/v1` vendor-prefixed namespace (DECISION N; Doc 9 §7).

**Next.js frontend validation:**

- Blog listing page (`/hsp/v1/posts`)
- Single post page (`/hsp/v1/posts/{slug}`)
- Static pages (`/hsp/v1/pages/{slug}`)
- No WordPress reads on the consumer request path (ADR-040)

#### Dependencies

Phase 0 DoD gate must pass in full.

#### Definition of Done

- End-to-end sync verified under real WordPress editing: create/update/delete a page, post, and category; confirm projection appears in PostgreSQL within the SLA window
- Sync delay < 30 seconds under normal operating conditions (PRD §10 Performance; Doc 10 §24; Doc 11 §24)
- API endpoints return correct data from PostgreSQL projections; no WordPress queries on the consumer path
- Three-operation atomicity verified: force a mid-transaction failure; confirm no partial writes persist
- Idempotency verified: replay the same event twice; confirm no duplicate projection rows or checksum divergence
- Stale-event skipping verified: deliver an event with `aggregate_version` ≤ `latest_processed_version`; confirm it is skipped cleanly
- Next.js pages render without errors and reflect live WordPress content within the SLA
- Module isolation verified: no `HSP\Modules\Content\` import from any other module; no service-locator calls in module business logic
- Type-canon check (`content.*` tables): every `content.*` migration uses `TIMESTAMPTZ` for all timestamp columns (no bare `TIMESTAMP` carried over from Doc 3 §9–11) and `VARCHAR(64)` for all checksum columns. Verified against `docs/ARCHITECTURE_DECISIONS.md` v1.2 type canon (OPEN-3 v1.2).

---

### Early Operational Baseline

**Timing:** Delivered alongside or immediately after Phase 1A — before any later phase begins. The first synchronization failures will occur during content development; visibility must exist before complexity grows (Doc 11 §8).

**Source:** Doc 11 §8.

#### Deliverables

- **Dead Letter Queue** — `system.dead_letter_jobs` populated with `stack_trace`, `attempt_count`, `worker_id UUID`, `payload_snapshot JSONB` (OPEN-3). Admin trigger available to inspect DLQ contents.
- **Basic replay** — single-event replay and entity replay modes (Doc 4 §24). Replay re-enqueues the original event version; does not mutate historical contracts (Doc 5 §26).
- **Worker health monitoring** — heartbeat publication (`worker_id`, `status`, `last_heartbeat_at`, `current_job`, `memory_usage`, `processed_count`); crash detection via heartbeat age (Doc 8 §15).
- **Basic metrics** — minimum set: `jobs_processed`, `jobs_failed`, `jobs_retried`, `jobs_dead_lettered`, `average_processing_time`, `memory_usage` (Doc 8 §27).

#### Definition of Done

- Force a processing failure to exceed the retry limit (default 10, ADR-022); confirm the job lands in `system.dead_letter_jobs` with full failure context (stack trace, attempt count, worker_id, payload snapshot)
- Trigger a single-event replay from DLQ; confirm the event is re-processed cleanly and the projection is corrected
- Worker heartbeat visible; simulated worker crash causes visibility-timeout recovery to requeue the in-flight job
- Basic metrics emit at least the minimum set above

---

### Architecture Validation Gate

**Timing:** Must complete before Phase 2 (WooCommerce) begins. Gate failure blocks Phase 2 and all subsequent phases (Doc 11 §9–10).

**Source:** Doc 11 §9–10.

#### Reliability Validation

- Successful sync processing under normal load
- Replay succeeds for single event, entity, and date-range replay modes
- DLQ recovery: failed job replays to correct final state

#### Scalability Validation

- Multiple concurrent worker processes claim jobs without collision (SKIP LOCKED verified)
- Queue growth handled without head-blocking
- Replay under load does not corrupt normal processing

#### Operability Validation

- Worker health visible; failure detection within one heartbeat cycle
- Failure diagnostics available via DLQ payload snapshot and stack trace
- Reconciliation executes: hourly drift detection, incremental validation, full reconciliation (ADR-026); WordPress always wins divergence (ADR-027, ADR-045)

#### Extensibility Validation

- Add a new content field to a projection without modifying `core/`
- Add a new projection column without modifying transformer or canonical model
- Add a new API resource without modifying existing endpoints

#### Gate failure rule

If any validation check above fails, do not start Phase 2. Resolve architectural weaknesses first.

---

## 5. Deferred — Post-MVP

These are listed as pointers only. No detail is provided here; do not implement during MVP.

**Phase 1B — Content Enhancement** (Doc 11 §7): Featured images, media sync (`content.media`), tags, basic ACF, pagination enhancements, PostgreSQL full-text search.

**Phase 2 — WooCommerce Catalog** (Doc 11 §11): Products, variations, categories, attributes, attribute terms, inventory. Explicitly excludes orders and customers.

**Phase 3 — Operational Hardening** (Doc 11 §12): Advanced replay, advanced reconciliation, improved monitoring, alerting, operational runbooks.

**Phase 4 — API Expansion** (Doc 11 §13): Composition APIs (`/compose/homepage` etc.), advanced filtering, caching enhancements, resource versioning improvements.

**Phase 5 — Search Expansion** (Doc 11 §14): Search provider contract; optional OpenSearch / Typesense providers. PostgreSQL search remains supported.

**Phase 6 — Future Domain Modules** (Doc 11 §15): Membership, LMS, Directory, Booking, Events, custom business applications. New domains as modules only; no core modifications.

**Queue provider expansion** (Doc 11 §16): Redis, RabbitMQ, Kafka, Amazon SQS — via existing `QueueProviderInterface`.

**API transport expansion** (Doc 11 §18): GraphQL, gRPC, SDKs — transport-agnostic architecture unchanged.

---

## 5b. Session Map

Ordered execution plan derived from Phase 0, Phase 1A, and Early Operational Baseline scope above. Each session is the smallest independently shippable unit of work. A session may not begin until all listed dependencies pass their DoD.

| ID | Name | Scope (files / dirs) | Authority | Definition of Done | Depends-on |
|---|---|---|---|---|---|
| **P0-S1** | Bootstrap + DI container + configuration system | `bootstrap/`, `config/`, `core/Container/`, `headless-sync.php` | ADR-012 (constructor injection only), Doc 2 §7, OPEN-9 v1.4 | Container resolves all core bindings without service-locator calls; env/config hierarchy loads correctly; plugin boots without fatal errors | — |
| **P0-S2** | Migration engine | `core/Migrations/`, `database/Core/` | OPEN-8 v1.4 (DDL for `system.schema_versions`, `system.module_versions`); DECISION 2 v1.1 | Engine runs frozen migrations in order; writes to `system.schema_versions` and `system.module_versions`; idempotent re-run produces no duplicate rows; all MySQL/PG core migrations pass DoD Gate items 1–6 (§4 Phase 0 DoD) | P0-S1 |
| **P0-S3** | Module registry / discovery / lifecycle | `core/Module/`, `modules/*/module.json`, `core/Contracts/ModuleInterface.php` | OPEN-9 v1.4 (ModuleInterface union shape: discovery + lifecycle); Doc 2 §12 (superseded shape) | Registry discovers modules via `module.json`; lifecycle callbacks (`register → boot → activate → deactivate → upgrade`) fire in correct order; unit tests pass | P0-S1 |
| **P0-S4** | Outbox capture + RelayWorkerStrategy | `core/Events/Outbox/`, `core/Events/Dispatcher/`, `core/Workers/Strategies/RelayWorkerStrategy.php` | DECISION 1 (post-commit write; no cross-DB transaction); DECISION 2 v1.1 (atomic counter); OPEN-6 (relay copy to `system.events`); OPEN-4 | Outbox row written after WP commit; `RelayWorkerStrategy` claims row (SKIP LOCKED), inserts to `system.events` with `ON CONFLICT DO NOTHING`, marks `relayed` only after PG commit; `aggregate_version` counter increments atomically under concurrent test inserts; relay integration test passes | P0-S2 |
| **P0-S5** | DB queue provider | `core/Queue/Providers/Database/`, `core/Contracts/QueueProviderInterface.php` | OPEN-4 v1.1 (SKIP LOCKED, `visibility_timeout_at TIMESTAMPTZ`, `worker_id UUID`); OPEN-3 v1.1 (dead-letter schema); ADR-023 | Claim acquires `FOR UPDATE SKIP LOCKED`; visibility-timeout recovery requeues stale in-flight jobs; job dead-letters after retry limit; `system.dead_letter_jobs` populated with required columns; partitions `content`, `commerce`, `system` exist | P0-S2 |
| **P0-S6** | Worker engine + strategies + event/adapter registries | `core/Workers/`, `core/Events/` (registry), `core/Delivery/` (adapter registry) | Doc 8 §7 (Claim→Load→Context→Validate→Resolve→Execute→Commit→Ack pipeline); ADR-044 (stateless); UUIDv7 v1.1 canon; ADR-022 (retry limit 10) | Worker ticks through standard pipeline; heartbeat publishes `worker_id`, `status`, `last_heartbeat_at`; graceful shutdown completes current job; event/adapter registries accept explicit registration only (no reflection); unit tests pass | P0-S3, P0-S4, P0-S5 |
| **P0-S7** | Phase 0 DoD gate verification | `docs/ARCHITECTURE_DECISIONS.md` Implications table; all `database/Core/` migrations | Full DoD Gate §4 Phase 0 (items 1–6) + additional DoD criteria | Every item in the DoD gate checklist passes: type canon, counter query, no superseded postmeta references, platform boots, relay smoke test, concurrent-counter test | P0-S1 – P0-S6 |
| **P1A-S1** | Content events + WP hook wiring + EventProvider | `modules/Content/Events/`, `modules/Content/EventProvider.php` | OPEN-1 (fully-qualified event names `<domain>.<aggregate>.<action>`); Doc 5 §26; DECISION 1 | Nine event types registered; WP hooks (`save_post`, `transition_post_status`, `wp_trash_post`, `after_delete_post`, `created_term`, `edited_term`, `delete_term`) fire outbox write; unit tests confirm event names match OPEN-1 canon | P0-S7 |
| **P1A-S2** | Extractors + source models + validators | `modules/Content/Extractors/`, `modules/Content/SourceModels/`, `modules/Content/Validation/` | Doc 6 §24 (extractors normalize; no canonical model creation); Doc 11 §6 | `PageExtractor → PageSourceModel`, `PostExtractor → PostSourceModel`, `CategoryExtractor → CategorySourceModel`; all source models immutable and strongly typed; validators fail-fast on required-field or structural failure; unit tests pass with no DB/WP dependency | P1A-S1 |
| **P1A-S3** | Transformers + canonical models | `modules/Content/Transformers/`, `modules/Content/CanonicalModels/` | Doc 6 §24 (pure; no side effects); `CanonicalModelInterface`; Doc 11 §21 Tier-1 testing | `PageTransformer`, `PostTransformer`, `CategoryTransformer` are pure functions; `CanonicalPage`, `CanonicalPost`, `CanonicalCategory` implement `CanonicalModelInterface`; unit tests (`PageSourceModel → PageTransformer → expected PageCanonicalModel`) pass without DB or WordPress | P1A-S2 |
| **P1A-S4** | Content migrations + PostgreSQL adapters | `modules/Content/Migrations/`, `modules/Content/Adapters/` | DECISION 3 (three-op PG transaction: projection upsert + `system.processed_events` + `system.aggregate_versions`); OPEN-3 v1.2 type canon; OPEN-2; Doc 7 §19 (`persist()` + `bulkPersist()`) | `content.pages`, `content.posts`, `content.taxonomies`, `content.entity_taxonomies` migrations use `TIMESTAMPTZ`/`VARCHAR(64)` canon; adapters commit all three ops atomically; forced mid-transaction failure leaves no partial writes; idempotency test (same event twice) produces no duplicate rows | P1A-S3, P0-S7 |
| **P1A-S5** | REST Delivery API | `modules/Content/` (query providers, resources, REST registration) | Doc 9 §7 (versioned namespace prefix); Doc 9 §10 (query providers; no direct table queries in resources); ADR-040 (no WP reads on consumer path) | Six endpoints respond correctly from `content.*` projections; cursor pagination works; no WordPress queries on consumer path; versioning prefix present from day one | P1A-S4 |
| **P1A-S6a** | Bootstrap/DI fix — module boot + REST routes | `core/Module/ModuleLoader.php`, `bootstrap/Application.php`, `modules/Content/ContentModule.php`, `modules/Content/ContentServiceProvider.php` | ADR-012 (constructor injection only); OPEN-9 v1.4 (register→boot lifecycle); Doc 9 §7 (REST namespace registration boundary); FLAG-P1AS6-2 | ModuleLoader resolves via container (no `new $class()`); Application::boot() calls registerAll(); ContentModule::boot() wires rest_api_init; plugin boots in live WP with zero fatals; six hsp/v1 routes present; WP hooks attached; 731/731 tests pass | P1A-S5 |
| **P1A-S6b** | Content Subscriber/Handler spine | `modules/Content/` (Subscribers/, Handlers/, EventWorkerStrategy un-stub, EventRegistry handler registration) | Doc 8 §7 (worker pipeline); DECISION 3 (three-op atomic PG transaction); ADR-012 | Subscriber resolves from EventRegistry; Handler invokes Extractor→Transformer→Adapter pipeline; executeHandler() un-stubbed; content event handler registered; worker processes queue job to PG projection | P1A-S6a |
| **P1A-S6c** | Delivery connection isolation | `core/Container/Definitions/DeliveryServiceProvider.php` (NEW); `core/Container/Definitions/QueueServiceProvider.php` (remove DatabaseConnectionInterface binding); `core/Container/ContainerBuilder.php` (register DeliveryServiceProvider); `tests/Integration/Core/DeliveryConnectionIsolationTest.php` (NEW) | DECISION K (v1.11); DECISION E (v1.6); DECISION J (v1.10); FLAG-P0S5-1 FORCE_NEW precedent; ADR-012 | DatabaseConnectionInterface binding removed from QueueServiceProvider; DeliveryServiceProvider opens FORCE_NEW connection; all consumers (query providers, adapters, EventWorkerStrategy Resolve-stage) resolve through dedicated delivery connection; integration test proves relay/queue and delivery handles are distinct physical links; zero failures | P1A-S6b |
| **P1A-S6d** | Dispatcher stage (system.events → system.queue_jobs) | `core/Events/Dispatcher/` (NEW: DispatcherWorkerStrategy, EventDispatcher, DispatchBatch); `database/Core/pgsql/0011_add_unique_event_id_to_queue_jobs.sql` (NEW); `core/Queue/Providers/Database/DatabaseQueueProvider.php` (add enqueueIdempotent()); `core/Container/Definitions/DispatcherServiceProvider.php` (NEW); `core/Container/ContainerBuilder.php` (register DispatcherServiceProvider); `tests/Integration/Core/DispatcherIntegrationTest.php` (NEW) | DECISION L (v1.12); DECISION E (v1.6) no new pg_* wrapper; DECISION K (v1.11) delivery connection for system.events read; ADR-012 constructor injection | DispatcherWorkerStrategy claims undispatched system.events rows (anti-join NOT EXISTS + FOR UPDATE SKIP LOCKED, LIMIT N); enqueues into system.queue_jobs via DatabaseQueueProvider::enqueueIdempotent() (ON CONFLICT(event_id) DO NOTHING); UNIQUE(event_id) migration delivered; integration tests: relay→dispatch→queue_jobs row appears; idempotency (run twice → one row); concurrency (SKIP LOCKED + UNIQUE); relay→dispatcher link; 0 skipped on live PG | P1A-S6c |
| **P1A-S6** | Next.js validation + end-to-end DoD | Next.js consumer (external); full pipeline smoke | Doc 11 §24 (30-second SLA); Phase 1A DoD checklist | All Phase 1A DoD criteria pass: end-to-end sync, < 30s delay, atomicity, idempotency, stale-event skip, Next.js renders, module isolation, type-canon check | P1A-S6d |
| **P1A-S7** | REST namespace rename: `api/v1` → `hsp/v1` | `modules/Content/Rest/ContentRestRegistrar.php`; `hsp-blog/lib/api.ts`; `headless-sync/tools/smoke_e2e.php`; doc reconciliation (DECISION F, IMPLEMENTATION_PLAN.md §4, Phase 1A DoD text, FLAG-P1AS5-1 text) | DECISION N (v1.14); Doc 9 §7 (versioned prefix); WP REST vendor-prefix convention | `grep api/v1` returns zero hits across `modules/`, `tests/`, `hsp-blog/`, `tools/`, `docs/`; namespace constant defined in exactly one place; WP REST index exposes `hsp/v1` with all six routes; `api/v1` absent; Next.js renders against new base; smoke_e2e.php green; full PHPUnit suite green | P1A-S6 |
| **P1A-S8** | Env → define config resolution | `bootstrap/`, `config/`, `core/Configuration/`; environment-variable → WordPress `define()` resolution layer | CLAUDE.md §"Build / Test / Run / Lint"; Doc 2 §7 (config hierarchy); ADR-012 | Environment configuration resolves correctly through define → env → default chain; plugin boots with and without env vars; unit tests cover resolution order | P1A-S7 |
| **OPS-S1** | Early Operational Baseline | `core/Workers/` (heartbeat + `DatabaseHeartbeatPublisher` + `MaintenanceWorkerStrategy`); `database/…` migrations (NEW `system.worker_heartbeats`; forward migration adding `system.dead_letter_jobs.replayed_at`); WP-CLI DLQ tooling (`hsp dlq list\|inspect\|replay`); metrics (derived-on-demand + structured logs) | Doc 11 §8; Doc 4 §24 (single-event + entity replay); Doc 8 §15 (heartbeat); Doc 8 §27 (metrics minimum set); OPEN-3 v1.1 (DLQ schema); ADR-022 (retry limit); DECISION L Ruling 0 v1.16 (four-connection topology frozen); DECISION P v1.16 (worker_heartbeats table); DECISION Q v1.16 (metrics without persistence); DECISION R v1.16 (visibility-timeout recovery driver); DECISION S v1.16 (DLQ replay lifecycle) | DLQ populated on retry-limit exhaustion with full context; single-event replay re-processes cleanly (one PG txn: verify→delete prior queue_jobs row for event_id→insert fresh job attempts=0→stamp replayed_at; passes DECISION J stale guard); heartbeat visible via `system.worker_heartbeats` (upsert per tick); simulated crash triggers visibility-timeout requeue via `MaintenanceWorkerStrategy` (config-driven cadence); metrics emit = queryable on-demand PostgreSQL aggregates + structured worker log output (no metrics table). **Migrations authorized in scope:** NEW `system.worker_heartbeats` table (DECISION P); forward migration adding `replayed_at TIMESTAMPTZ NULL` to `system.dead_letter_jobs` (DECISION S — 0004 not edited). | P1A-S8 |
| **OPS-S2** | Replay Engine — entity + date-range replay | `core/Workers/Strategies/ReplayWorkerStrategy.php` (un-stub); replay support code under `core/` as required by Doc 4 §24; WP-CLI replay commands (extend the OPS-S1 `hsp` command surface); `tests/Unit` + `tests/Integration` for replay; IMPLEMENTATION_PLAN.md §5b (this row + GATE-S1 Depends-on only); STATUS.md | Doc 4 §24 (entity + date-range replay modes); DECISION S (replay lifecycle pattern — verify→clean→fresh-job→stamp); DECISION J (Resolve-stage stale guard is binding — may not be weakened/bypassed without a DECISION); ADR-044 / DECISION H (state sync — workers reload current WP state); DECISION L Ruling 0 (four-connection topology frozen — no new PG handle); CLAUDE.md Rule 3 (no outbox bypass) | (1) Entity replay: given `(aggregate_type, aggregate_id)`, the entity reprojects to correct final state through the normal pipeline; integration test proves projection correctness on live MySQL + PG. (2) Date-range replay: given a time window, all affected aggregates reproject correctly; integration test includes aggregates whose events span the window boundary. (3) Both modes idempotent (same replay twice → identical final state, no duplicate rows) and pass THROUGH — never around — the DECISION J guard as specified by the ratified mechanism. (4) `system.processed_events` / `system.aggregate_versions` remain consistent (GREATEST guard holds). (5) Unit + integration suites green; no new `pg_*` wrapper; no new PG connection handle. **Blocked pending architect ruling on FLAG-OPSS2-1** (how replay of already-processed events interacts with the DECISION J stale guard — mechanism unspecified in Doc 4 §24). No new table/column may be introduced without a DECISION. | OPS-S1 |
| **GATE-S1** | Architecture Validation Gate — Reliability | `tests/Integration/Gate/` (test evidence only — no production code changes unless a validation check fails, which is a STOP-and-flag) | §4 Architecture Validation Gate → Reliability Validation; Doc 11 §9–10; Doc 4 §24 (replay modes); DECISION S (DLQ replay lifecycle); DECISION J (stale guard on replay) | Successful sync processing under normal load; Replay succeeds for single event, entity, and date-range replay modes; DLQ recovery: failed job replays to correct final state | OPS-S1, OPS-S2 |
| **GATE-S2** | Architecture Validation Gate — Scalability | `tests/Integration/Gate/` (test evidence only — no production code changes unless a validation check fails, which is a STOP-and-flag) | §4 Architecture Validation Gate → Scalability Validation; Doc 11 §9–10; OPEN-4 (SKIP LOCKED) | Multiple concurrent worker processes claim jobs without collision (SKIP LOCKED verified); Queue growth handled without head-blocking; Replay under load does not corrupt normal processing | GATE-S1 |
| **OPS-S3** | Reconciliation MVP | `core/Workers/Strategies/ReconciliationWorkerStrategy.php` (un-stub); reconciliation detection/orchestration under `core/` as required by ADR-026; WP-Cron trigger wiring; WP-CLI reconcile command (extend the OPS-S1 `hsp` surface); module-side WP-state comparison behind the existing `ReplayEmitterInterface`; `tests/Unit` + `tests/Integration` for reconciliation; IMPLEMENTATION_PLAN.md §5b (this row + GATE-S3 Depends-on only); STATUS.md | DECISION U (v1.18 — repair via DECISION T re-emission ONLY; no direct PG writes; WordPress wins by construction); ADR-026 (drift detection / incremental validation / full reconciliation); ADR-027/ADR-045 (WordPress wins); DECISION 1 (missed-capture backstop); DECISION T (re-emission primitive); DECISION I (tombstone path for orphans); DECISION J (Resolve-stage stale guard binding); DECISION L Ruling 0 (four-connection topology — no new handle); DECISION E (no new `pg_*` wrapper); CLAUDE.md Rule 1 + recovery-jobs WP-Cron carve-out | (1) Drift detection identifies missed captures (WP entity newer than / absent from delivery state — DECISION 1 backstop) and orphans (present in delivery, deleted/non-public in WP); integration test proves both classes on live MySQL + PG. (2) Repair re-emits through the normal pipeline via `ReplayService`/`ReplayEmitterInterface` (DECISION T) ONLY — no direct `content.*`/`system.*` projection writes; missed captures reproject to current WP state, orphans drive the DECISION I tombstone (`deleted_at`) path. (3) WordPress-wins holds by construction (never writes WP from PG). (4) Incremental and full reconciliation modes both run; repair is idempotent (a second pass over already-consistent state emits no spurious repair / converges without duplicate rows). (5) In-flight events (aggregates whose events are still queued/unprocessed) are not falsely flagged as drift (false-positive suppression). (6) WP-Cron triggers passes; the worker runtime executes them. (7) Unit + integration suites green; no new PG handle; no new `pg_*` wrapper; core never imports a module. **Design step required before implementation** (DECISION U point 6): drift-detection query shape, entry points, and false-positive suppression are ratified in the OPS-S3 design note before any code. | GATE-S2 |
| **GATE-S3** | Architecture Validation Gate — Operability | `tests/Integration/Gate/` (test evidence only — no production code changes unless a validation check fails, which is a STOP-and-flag) | §4 Architecture Validation Gate → Operability Validation; Doc 11 §9–10; DECISION P (heartbeat); OPEN-3 (DLQ payload snapshot + stack trace); ADR-026/ADR-027/ADR-045 (reconciliation, WordPress wins); DECISION U (reconciliation built in OPS-S3) | Worker health visible; failure detection within one heartbeat cycle; Failure diagnostics available via DLQ payload snapshot and stack trace; Reconciliation executes: hourly drift detection, incremental validation, full reconciliation (ADR-026); WordPress always wins divergence (ADR-027, ADR-045) | OPS-S3 |
| **GATE-S4** | Architecture Validation Gate — Extensibility | `tests/Integration/Gate/` (test evidence only — no production code changes unless a validation check fails, which is a STOP-and-flag) | §4 Architecture Validation Gate → Extensibility Validation; Doc 11 §9–10; Rule 5 (module isolation); Rule 6 (consumers depend on API contracts only) | Add a new content field to a projection without modifying `core/`; Add a new projection column without modifying transformer or canonical model; Add a new API resource without modifying existing endpoints | GATE-S3 |
| **OPSC-S0** | Doc 12 Ratification (docs-only) | `docs/ARCHITECTURE_DECISIONS.md`, `docs/11-…md`, `docs/12-admin-operations-console-architecture.md`, `CLAUDE.md`, `docs/IMPLEMENTATION_PLAN.md` §5b, `docs/notes/DOC12-ADOPTION-AUDIT.md` | Architect ruling 2026-07-15 (FLAG-PLANOPS1-1..11); PLAN-OPS-1 audit; precedence rule | DECISION V recorded (ARCHITECTURE_DECISIONS.md v1.20) ratifying FLAG-PLANOPS1-1..11; ADR-047/048/049/050/052/053 ratified, ADR-051 HELD; Doc 11 gains Phase 1A – Expanded; Doc 12 → Accepted (as amended by DECISION V), §21 self-freeze removed; CLAUDE.md folder + coding standard + SETTLED updated; OPSC-S1..S4 rows inserted; STATUS pointer → OPSC-S1. Docs-only — no production code. | GATE-S4 |
| **OPSC-S1** | Operations Console core scaffolding — `core/Operations/` subtree, Operations Registry (Page/Nav/Widget/Action/Asset), provider contracts (Health/Metrics/Worker/Queue/Endpoint), Operations Service layer — read-only, no UI actions | `core/Operations/{Registries,Providers,Services}/`, `core/Contracts/Operations/` | DECISION V (a),(h),(i); ADR-047/048/052/053; CLAUDE.md Rule 5/6; OPEN-9 | Registries discover pages/widgets/providers via **explicit registration** (no reflection); operations contracts live in `core/Contracts/Operations/` (Rule 5 verbatim); providers resolve via constructor injection (ADR-012); unit tests for registry + provider contracts; **no** node toolchain, no shipped JS bundle | OPSC-S0 |
| **OPSC-S2** | Diagnostics + Metrics providers (current-state, derived-only) — Health, Worker Status, Queue Status, Metrics providers computed **on-demand per DECISION Q**; System Information (Doc 12 §13); Module Inspector (§14). No persistence, no rollups, no history | `core/Operations/{Providers,Diagnostics,Services}/`, `modules/Content/Operations/` (module-provided diagnostics/metrics behind `core/Contracts/Operations/`) | DECISION V (c),(g),(h); DECISION Q (no persistence); DECISION P (heartbeat current-state); OPEN-8 (`module_versions`/`schema_versions` reads); Rule 5 | Providers return live queue depth / DLQ depth / worker count / oldest-pending age from existing tables via the **delivery `DatabaseConnectionInterface`** (DECISION K — no fifth handle, no new `pg_*` wrapper); processing-rate / replay-status / reconciliation-status derived point-in-time (zero persistence); worker-offline = heartbeat-age query; integration test on live PG | OPSC-S1 |
| **OPSC-S3** | Operations Console UI (server-rendered PHP) + Operations + API Playground pages — MVP nav (Doc 12 §6): Operations dashboard (read-only widgets over OPSC-S2 providers) + API Playground (§15: Endpoint Explorer/Request Builder/Execution/Response Viewer over the six `hsp/v1` endpoints) | `core/Operations/{Admin,UI}/`, `modules/Content/Operations/` (endpoint metadata) | DECISION V (a) server-rendered PHP + minimal vanilla JS, **no node/npm/bundler**; DECISION V (b) PSR-12 + WPCS security at the WP-admin boundary; ADR-047/052/053; DECISION N/F (endpoints) | wp-admin page renders the console server-side (minimal vanilla JS for polling only — no build step, no shipped bundle, no `resources/` build config); widgets read from providers (no direct infra calls — ADR-053); API Playground executes live GETs against `hsp/v1` and renders responses; escaping/sanitization/capability/nonce applied at the admin boundary (DECISION V (b)); no state-changing actions present | OPSC-S2 |
| **OPSC-S4** | Operational Actions — **Replay + Reconcile ONLY** — registry-driven actions routed through Operations Services that delegate **exclusively** to `ReplayService`/`ReplayWorkerStrategy` (DECISION T/S) and `ReconciliationService`/`ReconciliationWorkerStrategy` (DECISION U). Worker status + heartbeat + runbook links are a **read-only S2/S3 surface, not an action**. **No Flush Queue, no Restart Workers.** | `core/Operations/{Services,Registries}/`, `modules/Content/Operations/` | DECISION V (d) thin delegators + write-spy; DECISION V (e) no Flush Queue; DECISION V (f) no Restart Workers; DECISION T (replay re-emission), DECISION S (DLQ replay), DECISION U (reconciliation); ADR-053 (ADR-051 HELD — not cited) | Replay + Reconcile actions invoke the ratified services only, proven by a **write-spy**: zero direct `content.*`/`system.*` writes on the action path (mirrors GATE-S3); capability + confirmation + audit enforced; worker status/heartbeat surfaced read-only with runbook links (no lifecycle action); integration test on live MySQL+PG; **no Flush Queue / Restart Workers action exists** | OPSC-S3 |
| **ONB-S1a** | Onboarding / First-Run — **frontend toolchain bootstrap + onboarding page skeleton** (STEP 0 subdivision of ONB-S1; architect-ruled 2026-07-16). React+shadcn admin-UI toolchain under `resources/admin-ui/` (npm; Vite build → committed `dist/`; Tailwind + shadcn with **dark theme as the DEFAULT scoped to the plugin mount root** — see DECISION W (a) styling addendum); a minimal `AdminPageController`-style registrar registering ONE capability-gated "HSP Onboarding" wp-admin page that renders a server-side shell enqueuing the committed `dist/` assets and mounting the React app. **No preflight checks, no nav gating, no `hsp_onboarding_state` flag, no backfill** (all ONB-S1b / ONB-S2). | `resources/admin-ui/` (package.json + package-lock.json [npm], vite/tailwind/shadcn config, `src/`, committed `dist/`), `.gitignore` (ignore `node_modules/`; do NOT ignore `resources/admin-ui/dist/`), `core/Onboarding/` (registrar: one page, capability-gated, enqueues committed `dist/`, mounts React), `headless-sync.php` / container wiring for that registrar only, `CLAUDE.md` (real frontend build-command rows), `IMPLEMENTATION_PLAN.md` §5b (STEP 0), `STATUS.md` | DECISION W (a) React+shadcn UI + commit `dist/` (build in dev/CI, deploy = file copy, no host build step) + styling addendum (dark default on mount root only); DECISION V (b) WPCS at the WP boundary (escaping, capability check on the page, nonce localized for future endpoints); DECISION V (j) console unaffected; DECISION K reuse / DECISION L Ruling 0 / DECISION E — onboarding opens NO PG handle, NO new `pg_*` wrapper; ADR-012; Rule 5 | `npm run build` inside `resources/admin-ui/` produces `dist/`, and `dist/` is committed; the HSP Onboarding wp-admin page renders the React shell from committed `dist/` with no host build step, dark-themed, capability-gated, output-escaped at the PHP boundary; wp-admin styling outside the mount is unaffected (Tailwind scoped to the mount container); no PG handle, no new `pg_*` wrapper, no schema change, no OPSC file modified; CLAUDE.md build-command rows updated; unit tests for the registrar (page registration, capability deny, asset enqueue) green; full suite green. | OPSC-S4 |
| **ONB-S1b** | Onboarding / First-Run — **four preflight checks + nav gating + completion flag** (STEP 0 subdivision of ONB-S1; architect-ruled 2026-07-16; migration check moved to ONB-S2 per DECISION W (f) amendment v1.22, 2026-07-17). Four hard-blocking **environment** prerequisite checks; nav gating so Operations + API Playground pages are hidden until onboarding completes; the `hsp_onboarding_state` WP option (MySQL, no schema change). **No backfill yet** (that is ONB-S2); **the migration-engine-state check is ONB-S2** (backfill prerequisite — DECISION W (f) amended v1.22). | `core/Onboarding/` (preflight/prerequisite checks, `AdminPageController` nav gating, `hsp_onboarding_state` option read/write, onboarding page wiring), `core/Contracts/` (any onboarding contract); `core/Operations/Admin/` (gate the Operations + Playground menu registration on the completion flag) | DECISION W (a) React+shadcn UI + commit `dist/` + WPCS at the REST/ajax boundary; DECISION W (d) completion state = single WP option, no schema; DECISION W (e) `core/Onboarding/` placement, delegate-only, no PG handle of its own (DECISION K reuse; DECISION L Ruling 0; DECISION E); DECISION W (f) nav gating + hard-block preflight (amended v1.22 — migration check → ONB-S2); DECISION O (`HSP_PG_*` constants); DECISION V (b) WPCS at WP entry points; DECISION V (j) console unaffected; ADR-012; Rule 5 | Onboarding page renders (React, from committed `dist/` — no host build step); the **four environment prerequisite checks hard-block** progression when unmet (pgsql extension, PG constants defined, PG reachable, PHP ≥ min) with remediation guidance; **Operations + API Playground admin pages are NOT registered/visible** until `hsp_onboarding_state = complete`; onboarding is the only HSP admin surface pre-completion; the completion flag round-trips via the WP option (no migration, no new table/column); escaping/sanitization/capability/nonce enforced at every REST/ajax endpoint the page calls (DECISION V (b) / W (a)); no PG handle opened by onboarding (reuses delivery handle); no state-changing backfill action present (ONB-S2); unit tests for preflight + gating + flag. | ONB-S1a |
| **ONB-S2** | Onboarding / First-Run — backfill trigger + derived live progress + redirect to Operations. Trigger a **full-reconciliation re-emission** (`ReconciliationService::reconcileFull()`, DECISION U) as the first-run content migration; **live worker heartbeat is a HARD PREREQUISITE**; **derived** on-demand progress (DECISION Q); on completion set `hsp_onboarding_state = complete`, un-gate the console, and redirect to Operations. **Also runs the migration-engine-state hard-block** (required core+content migrations applied per `system.schema_versions`/`system.module_versions`, OPEN-8) as a backfill prerequisite, reusing the ONB-S1b `MigrationsAppliedCheck` (moved here by DECISION W (f) amendment v1.22). | `core/Onboarding/` (backfill trigger as a thin delegator to `ReconciliationService`; worker-heartbeat prerequisite check reusing the DECISION P read path; migration-state check reusing the ONB-S1b `MigrationsAppliedCheck`/`OnboardingConnectionProbe`; derived-progress computation; completion transition + redirect), `modules/Content/Operations/` only if module-provided expected-count reads are needed behind existing `core/Contracts/` (Rule 5) | DECISION W (b) backfill = `ReconciliationService` full re-emission, thin delegator, **no direct WP→PG copy / no second repair path**, write-spy proof; DECISION W (c) live-worker-heartbeat hard prerequisite, no in-request tick drain; DECISION W (d) derived progress (DECISION Q), zero new persistence; DECISION W (e) delegate-only, no new handle; DECISION W (f) migration-state check (amended v1.22 — evaluated here); DECISION U (reconciliation), DECISION T (re-emission), DECISION P (heartbeat), OPEN-8 (migration-state reads), DECISION 1 + Rule 4 (never lose a sync); Rule 5; ADR-012 | Backfill triggers **only** when a fresh worker heartbeat exists (else blocked with worker-status/runbook guidance — no Restart Workers) **and the required core+content migrations are applied** (migration-state hard-block, remediation guidance if not); backfill routes **exclusively** through `ReconciliationService` re-emission — a **write-spy proves zero direct `content.*`/`system.*` writes** on the backfill path (mirrors GATE-S3 / DECISION V (d)); all in-scope content projects to the delivery tables via the normal outbox→relay→dispatch→worker pipeline (WordPress-wins by construction); **live progress is derived on-demand** (expected-count scan vs processed/projection counts) with **zero new PG persistence**; on convergence `hsp_onboarding_state` flips to `complete`, the Operations + API Playground pages register/appear, and the operator is redirected to Operations; integration test on live MySQL + PG proving backfill convergence + zero direct writes + the heartbeat gate; unit tests for progress derivation + completion transition. | ONB-S1b |

| **ALIGN-S0** | ADR-054 Implementation Alignment Audit (interstitial; analysis/planning ONLY — architect ruling 2026-07-17, inserted before Phase 1B). Audit the shipped implementation (which predates ADR-054 and assumes the v1.0 daemon model) against Doc 8 v2.0 / ADR-054 (WP-Cron Processing Engine: bounded, stateless, time-budgeted cycles; overlap-safe via existing guarantees only; heartbeat = per-cycle cycle-freshness; metrics minus worker_uptime/restart_count; class names retained). Produce the five audit deliverables; **NO code / architecture-doc / test / config changes** (sibling-doc wording fixes remain FLAG-DOC8V2-1; the four `config/worker.php` processing keys remain FLAG-DOC8V2-2 — record, do not add). | `docs/ADR054-IMPLEMENTATION-AUDIT.md` (NEW — five deliverables); `docs/IMPLEMENTATION_PLAN.md` §5b (this row); `STATUS.md` (interstitial item + log line) | ADR-054 (authoritative execution-model ruling); Doc 8 v2.0 (entire); DECISION P/Q/L Ruling 0/R/U/V (f)/W (c); FLAG-DOC8V2-1/-2; CLAUDE.md (except the superseded systemd/Supervisor + WP-CLI-worker notes — ADR-054 wins by precedence) | `docs/ADR054-IMPLEMENTATION-AUDIT.md` exists with all five deliverables (Architecture Compliance Audit; Implementation Alignment Plan; Conflict Report; Impact Analysis; Session Regeneration Review); every finding carries a file/class/method + authority citation; every audited location classified in the Impact Analysis; all needed-ruling items surfaced as NEW FLAGS (not improvised resolutions); no new locking mechanism / fifth PG handle / new `pg_*` wrapper / schema change appears in the plan (ADR-054 §9). `git status` shows ONLY `docs/ADR054-IMPLEMENTATION-AUDIT.md`, `docs/IMPLEMENTATION_PLAN.md`, `STATUS.md` changed. Next session = alignment-implementation, pending architect review of this audit (do NOT advance to Phase 1B). | ARCH-DOC8-V2 |
| **ALIGN-S1** | ADR-054 Alignment Implementation, **part 1 of 2** — Processing Engine cycle + WP-Cron trigger + `WorkerInterface` contract + per-cycle heartbeat. Build the bounded, stateless, time-budgeted **WP-Cron Processing Engine cycle** (relay → dispatch → projection → maintenance → persist heartbeat/metrics → clean exit) reusing the existing stage primitives verbatim (`RelayWorkerStrategy::tick()`, `DispatcherWorkerStrategy::execute()`, `EventWorkerStrategy::execute()` in a bounded loop, `MaintenanceWorkerStrategy` sweep). Correct `WorkerInterface` (remove `run()`/`shutdown()` → one bounded-cycle method + result — DECISION X (3)); retire the per-strategy daemon-engine bindings + `run()`/`shutdown()`/`usleep`/`idleWaitMs` from the execution path; bind ONE cycle engine. Register the processing-cycle WP-Cron event + callback (`ReconciliationCronRegistrar` precedent) + activation scheduling / deactivation cleanup; **no `hsp worker` daemon CLI**. Durable/derived maintenance cadence (no new persistence). Per-cycle heartbeat = fresh UUIDv7, status ∈ `{running,idle}` (DECISION X (1)/(2)). Add the four `processing.*` config keys (FLAG-DOC8V2-2). Purge daemon docblocks in touched files (T7 for touched files only). | `core/Workers/` (engine + strategies), `core/Contracts/WorkerInterface.php` (+ its result type), `core/Container/Definitions/{Worker,Dispatcher}ServiceProvider.php`, `bootstrap/Application.php`, `headless-sync.php`, `config/worker.php`, cron-registrar file(s), `tests/` (Unit + live-PG Integration), `docs/{ARCHITECTURE_DECISIONS.md,ADR054-IMPLEMENTATION-AUDIT.md,IMPLEMENTATION_PLAN.md}`, `STATUS.md` | ADR-054 (§1/§9 constraints binding); Doc 8 v2.0 §9/§12/§15/§16/§23/§24/§25/§29; DECISION X (v1.24 — rulings 1/2/3; ruling 4 recorded, implemented ALIGN-S2); DECISION 3 (three-op commit preserved); DECISION P (heartbeat schema verbatim); DECISION Q (derived metrics); DECISION R (config-driven cadence precedent); DECISION L Ruling 0 (no fifth handle); DECISION E (no new `pg_*` wrapper); ADR-012; FLAG-DOC8V2-2 (config keys) | Unit + live-PG integration prove: a cycle runs bounded per-stage batches and exits (no loop-to-empty, no sleep); work exceeding one cycle continues across a SECOND cycle (durable progress); on budget exhaustion the in-flight event's DECISION 3 transaction completes and the cycle exits cleanly mid-backlog; two overlapping cycles on independent PG connections process a shared queue with no double-claim / no duplicate `processed_events` / monotonic `aggregate_versions` (existing guarantees only — extends GATE-S2); a cycle killed mid-batch leaves the claimed job recoverable via visibility timeout on a later cycle; each cycle upserts one fresh-UUID heartbeat row, status ∈ {running,idle}, two cycles → two distinct `worker_id` rows; activation schedules the processing event (`wp_next_scheduled` truthy), deactivation clears it, the cron callback runs exactly one bounded cycle; grep proves `run(`/`shutdown(`/`usleep`/`idleWaitMs` absent from `core/Workers/` + `WorkerInterface.php` and no daemon bindings remain; full suite green + deterministic on two runs; `git status` shows only in-scope paths. **BackfillGate / OPSC providers untouched** (ALIGN-S2). | ALIGN-S0 |
| **ALIGN-S2** | **✅ SHIPPED 2026-07-18 (see STATUS.md).** ADR-054 Alignment Implementation, **part 2 of 2** — console/metrics reinterpretation + backfill gate + remaining docblocks (T4/T4a/T6 + T7-rest). Reinterpret `HealthProvider`/`WorkerStatusProvider`/`WorkerStatus`/`DashboardView`/`OperationsQueryReader` from daemon online/offline to **cycle-freshness** (age-query mechanism preserved; labels/summaries change); add the DECISION Q cycle metrics (cycles_completed / avg_cycle_duration / per_stage_throughput) to `MetricsProvider` (feasible under DECISION X (1) fresh-UUID cardinality); reconcile the DECISION V (f) "process supervisor" wording. Realign `BackfillGate::workerGate()` to the DECISION X (4) Option-C prerequisite (processing cron scheduled AND recent heartbeat) + WP-Cron-only remediation (FLAG-ALIGN-2). Purge remaining daemon docblocks in the non-ALIGN-S1 touched files (T7-rest). **Also (carried from ALIGN-S1): (i)** add an **activation smoke test** driving the real `Application::activate()` and asserting the processing cron event becomes scheduled (resolves **FLAG-ALIGNS1-1** — or a documented ruling that the container-boot cost makes registrar-level coverage sufficient); **(ii) remove the now-inert `maintenance.recovery_interval_seconds` key** from `config/worker.php` (recorded as superseded in DECISION X — the dead key must not remain in the tree past ALIGN-S2). | `core/Operations/{Providers,UI,Diagnostics}/`, `core/Contracts/Operations/WorkerStatus.php`, `core/Onboarding/Backfill/BackfillGate.php` (+ reader as needed), `config/worker.php` (remove inert key), `tests/` (+ activation smoke test), docs, `STATUS.md` | ADR-054 §5/§6/§17/§27; DECISION X (v1.24 — ruling 4; ruling 1 metrics-feasibility consequence; maintenance-cadence note); DECISION Q; DECISION P; DECISION R (cadence relocation); DECISION V (f); DECISION W (c); FLAG-ALIGN-2; FLAG-ALIGNS1-1 | Console health reframed ("processing stalled: heartbeat stale WHILE queue non-empty" — not "worker offline"); cycle metrics emitted derived-on-demand (zero persistence); `BackfillGate` Option-C prerequisite + WP-Cron-only remediation (no supervisor/systemd/restart wording anywhere); no daemon docblocks remain in touched files; **FLAG-ALIGNS1-1 resolved (real `Application::activate()` scheduling smoke test, or documented ruling); `maintenance.recovery_interval_seconds` removed from config**; unit + integration green; `git status` shows only in-scope paths. | ALIGN-S1 |
| **OAPI-S1** | **✅ SHIPPED 2026-07-20 (see STATUS.md).** OpenAPI Specification, Registry-Generated — **interstitial inserted BEFORE P1B-S0** (architect ruling 2026-07-20, ADR-055; drift-guard enumeration scoped v1.28 "A-modified"; meta-schema gate = Node ajv, ruling D v1.29 — opis removed). Additively enrich the endpoint metadata contract, build a request-time registry-driven OpenAPI 3.1 generator, expose `GET /hsp/v1/openapi.json`, and add the CI drift guard. **(1)** Additively enrich `EndpointDescriptor` + `EndpointProviderInterface` (params, request/response schema, auth requirement, cursor-pagination envelope [Doc 9 §13 / DECISION F `CursorPage`], deprecation status [Doc 9 §26], version [Doc 9 §7], module owner [Doc 9 §6]) — **no field removed**; core owns the contract (`core/Contracts/Operations/`, Rule 5), the Content module populates its own descriptors (`modules/Content/Operations/ContentEndpointProvider`). **(2)** New core generator produces the OpenAPI 3.1 document **from the registry only** — never hand-authored, never reflection/scan-derived from WP routes. **(3)** Register `GET /hsp/v1/openapi.json` on the `hsp/v1` namespace (DECISION N) at the REST boundary (WPCS per DECISION V (b)/W (a)); public MVP surface (all six endpoints public). **(4)** Drift-guard test. **Do NOT alter the P1B-S0 row or any Phase 1B text** — P1B-S0 (Phase 1B planning) runs immediately AFTER OAPI-S1. | `core/Contracts/Operations/{EndpointDescriptor,EndpointProviderInterface}.php` (additive), OpenAPI generator + REST registrar under `core/` (+ `modules/Content/Operations/ContentEndpointProvider` enrichment), container wiring, `tests/` (Unit + drift-guard), `docs/{ARCHITECTURE_DECISIONS.md,12-…,IMPLEMENTATION_PLAN.md}`, `STATUS.md` | **ADR-055** (v1.26 — registry-generated OpenAPI 3.1); Doc 9 §7 (versioning), §13 (cursor pagination), §22 (public/authenticated), §26 (deprecation lifecycle), §6 (module API ownership); Doc 12 §15 (endpoint metadata registry, as amended by ADR-055); Rule 5 (core owns contracts; module isolation); Rule 6 (API-contract-only consumers); ADR-048/052 (registry-driven, explicit registration); ADR-050 (validate the published contract); ADR-038 (transport-agnostic contracts); DECISION N/F (`hsp/v1` + REST contracts); DECISION E (no new `pg_*` wrapper); DECISION L Ruling 0 (four-connection topology); ADR-054 (generator NOT in a processing cycle); ADR-055 (d) v1.27 (public-only scoping — FLAG-OAPI-1 RESOLVED) | Enriched descriptor carries all seven metadata classes with no existing field removed and no existing consumer (API Playground OPSC-S3) broken; `GET /hsp/v1/openapi.json` returns an OpenAPI **3.1** document **derived at request time from the current registrations** — add/edit/remove a registration and the served spec follows with no separate edit (proven by test); the document is **not** hand-authored and **not** reflection/scan-derived from WP routes; **drift guard: (a)** every registered `hsp/v1` REST route has a complete metadata entry (a route without metadata **fails CI**) **and (b)** the generated document validates against the **OpenAPI 3.1 meta-schema**; generated document contains **zero endpoints whose metadata marks them non-public** (public-only scoping per ADR-055 (d) — exclusion asserted by test; exclusion driven by the metadata auth field, not route inspection); generation does **zero persistence, zero PG read, opens no new handle, adds no `pg_*` wrapper, and never runs inside an ADR-054 processing cycle**; the generator endpoint is public + stateless (no capability check inside generation — ADR-055 (e)); no schema change, no new event contract; WPCS (capability/nonce/sanitize/escape) applied at the REST registration boundary; unit + drift-guard suites green; `git status` shows only in-scope paths. **FLAG-OAPI-1 RESOLVED (ADR-055 (d), v1.27 — public endpoints only).** | ALIGN-S2 |
| **LAZYPG-S1** | **✅ SHIPPED 2026-09-05 (see STATUS.md).** Lazy PostgreSQL connections at the container boundary — **interstitial inserted BEFORE P1B-S0** (resolves the ONB-S1b "lazy-connection ruling pre-Phase-1B" carry-forward; **DECISION Z**). All four runtime PG handles called `pg_connect()` inside their singleton factory, so RESOLVING a binding threw a raw `\RuntimeException` when PostgreSQL was unreachable or unconfigured; since `rest_api_init` fires on **every** REST request to the site (`wp/v2` + block editor) and building the content registrar resolves the query providers, an unreachable PostgreSQL fatalled **every REST request**, not just `hsp/v1`. **(1)** `PostgresDatabaseConnection` accepts a handle **or** a `\Closure(): handle` CONNECTOR, invokes it at most once on first real use, memoizes it, and translates connect failure to `DatabaseException` at that boundary; `rollback()` on a never-opened connection is a no-op. **(2)** The four providers pass connectors instead of connecting. **(3)** Regression guard + connector unit tests. Mirrors the existing `MysqliOutboxConnection` connector hotfix on the capture path. **Do NOT alter the P1B-S0 row or any Phase 1B text.** | `core/Database/PostgresDatabaseConnection.php`; `core/Container/Definitions/{Delivery,Queue,Dispatcher,Outbox}ServiceProvider.php`; `core/{Events/Outbox/Connection/PgsqlOutboxConnection,Queue/Providers/Database/DatabaseQueueConnection}.php` (docblocks only); `tests/Unit/{Database,Container}/`; `docs/{ARCHITECTURE_DECISIONS.md,IMPLEMENTATION_PLAN.md}`, `STATUS.md` | **DECISION Z** (v1.31 — lazy PG connections); ONB-S1b OBSERVATION (lazy-connection ruling due pre-Phase-1B); DECISION E (v1.6 — shared runtime PG layer, no new `pg_*` wrapper, boundary error translation); DECISION K (v1.11 — delivery connection isolation); DECISION L Ruling 0 (four-handle topology); ADR-012 (constructor injection); ADR-054 Principle 8 (never fatal on an unconfigured site) | Resolving each of the four PG bindings opens **no socket** and throws nothing even with **no `HSP_PG_*` configured at all** (proven by unit test — `CredentialResolver::pgHost()` throws for a missing credential, so any DSN read at resolution time fails the guard); the connector is **not** invoked at construction; a connect failure surfaces as `DatabaseException` (never a raw `\RuntimeException` from a factory) on first real use; `rollback()` on an unopened connection does not connect; the four handles remain **four distinct objects** with their existing flags (FORCE_NEW on delivery/queue/dispatcher, none on relay) — **no fifth handle, no new `pg_*` wrapper, no persistence, no schema change, no contract change**; Unit + Integration suites, PHPStan level 8 and PHPCS all green; `git status` shows only in-scope paths. | OAPI-S1 |

> **No unresolved conflicts.** All session authority references point to accepted ADRs, versioned OPENs, or Doc sources already reconciled in `ARCHITECTURE_DECISIONS.md`. **Note (DECISION W, v1.21):** the ONB-S1a/ONB-S1b/ONB-S2 rows adopt React + shadcn for the admin UI, which **amends DECISION V (a)** (server-rendered PHP, React deferred). This is an **explicit, ratified architect amendment recorded in DECISION W** — not a silent conflict; DECISION V (a) carries a pointer to it, and DECISION V's provider/registry architecture is unchanged. If a future session surfaces a conflict with a frozen ruling, flag it in `ARCHITECTURE_DECISIONS.md` rather than resolving it silently here.

---

## 6. Testing Priorities

Priority order per Doc 11 §21:

1. Transformers & canonical models
2. Adapters & event processing
3. Workers & queue providers
4. API layer
5. Admin UI

Test types per Doc 2 §30: Unit (class-level), Integration (infrastructure), Contract (interface compliance), Module (module-level), Performance (scalability), End-to-End (full sync workflow).

Transformers are tested without infrastructure: `PageSourceModel → PageTransformer → expected PageCanonicalModel`, no DB or WordPress required (Doc 6 §24).

---

## 7. Technical Debt Policy

No implementation shortcut may violate module boundaries, event flow, canonical models, adapter separation, queue contracts, or core dependency rules (Doc 11 §22). Short-term speed must never create long-term architectural debt. No exception exists for MVP expediency.
