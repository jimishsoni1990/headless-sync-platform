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
REST Delivery API      /api/v1/pages | /api/v1/posts | /api/v1/categories
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

- `GET /api/v1/pages` — listing with cursor pagination, slug/status/published_after filters
- `GET /api/v1/pages/{slug}` — single page by slug
- `GET /api/v1/posts` — listing with cursor pagination, category/status/published_after filters
- `GET /api/v1/posts/{slug}` — single post by slug
- `GET /api/v1/categories` — category listing
- Query providers query `content.*` projections; endpoints do not query tables directly (Doc 9 §10). Resources handle serialization; no business logic in resources. Versioning from day one: `/api/v1/` prefix (Doc 9 §7).

**Next.js frontend validation:**

- Blog listing page (`/api/v1/posts`)
- Single post page (`/api/v1/posts/{slug}`)
- Static pages (`/api/v1/pages/{slug}`)
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
| **P1A-S5** | REST Delivery API | `modules/Content/` (query providers, resources, REST registration) | Doc 9 §7 (versioned `/api/v1/` prefix); Doc 9 §10 (query providers; no direct table queries in resources); ADR-040 (no WP reads on consumer path) | Six endpoints respond correctly from `content.*` projections; cursor pagination works; no WordPress queries on consumer path; versioning prefix present from day one | P1A-S4 |
| **P1A-S6** | Next.js validation + end-to-end DoD | Next.js consumer (external); full pipeline smoke | Doc 11 §24 (30-second SLA); Phase 1A DoD checklist | All Phase 1A DoD criteria pass: end-to-end sync, < 30s delay, atomicity, idempotency, stale-event skip, Next.js renders, module isolation, type-canon check | P1A-S5 |
| **OPS-S1** | Early Operational Baseline | `core/Workers/` (heartbeat); `system.dead_letter_jobs`; admin DLQ tooling; metrics | Doc 11 §8; Doc 4 §24 (single-event + entity replay); Doc 8 §15 (heartbeat); Doc 8 §27 (metrics minimum set); OPEN-3 v1.1 (DLQ schema); ADR-022 (retry limit) | DLQ populated on retry-limit exhaustion with full context; single-event replay re-processes cleanly; heartbeat visible; simulated crash triggers visibility-timeout requeue; minimum metric set emits | P1A-S4 |

> **No conflicts detected** between the session breakdown and frozen decisions. All session authority references point to accepted ADRs, versioned OPENs, or Doc sources already reconciled in `ARCHITECTURE_DECISIONS.md`. If a future session surfaces a conflict with a frozen ruling, flag it in `ARCHITECTURE_DECISIONS.md` rather than resolving it silently here.

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
