# HSP Architecture Decisions — Authoritative Conflict-Resolution Record

**Precedence: when this document conflicts with the PRD or Docs 1–11, THIS document wins. These resolutions are Accepted and frozen. Do not re-open or re-derive them.**

Version: 1.14  
Status: Accepted  
Owner: Architecture  

---

## Amendment Log

| Version | Date | Items changed |
|---|---|---|
| 1.1 | 2026-06-21 | OPEN-3, OPEN-4, OPEN-5, OPEN-7: column-type canon (TIMESTAMPTZ / VARCHAR(64) / UUID). DECISION 2: counter storage moved from postmeta/termmeta to dedicated `wp_hsp_aggregate_counters` table. Implications table updated. |
| 1.2 | 2026-06-21 | Timestamp canon scoped by engine (PostgreSQL `TIMESTAMPTZ` vs MySQL `DATETIME`-UTC); type canon bound explicitly to ALL tables including module-owned `content.*`, superseding Doc 3 §9–11. Phase 0 freeze-check wording corrected so MySQL `DATETIME` columns are not flagged as violations. Implications table annotated with MySQL timestamp types and a note that `content.*` tables inherit v1.2 canon with freeze check at Phase 1A DoD. |
| 1.3 | 2026-06-21 | OPEN-6: froze `wp_hsp_outbox` column-level DDL (previously "new table" only). Added `source_updated_at` (was missing — required to populate `system.events` OPEN-5 column). Pinned relay fidelity: `event_id` and `created_at` (capture time) are preserved unchanged from outbox into `system.events`. Implications table MySQL row updated to reference v1.3 frozen DDL. |
| 1.4 | 2026-06-21 | DECISION A: `dead_letter_jobs.payload_snapshot` changed to `NOT NULL`; raw payload must always be preserved. OPEN-8: froze `system.schema_versions`, `system.module_versions`, `system.security_events` DDL (were Doc-3-underspecified). OPEN-9: `ModuleInterface` is the union of declarative discovery + WP lifecycle methods, supersedes Doc 2 §12. DECISION D: `AdapterInterface` adds `bulkPersist()` per Doc 7 §19. |
| 1.5 | 2026-06-22 | DECISION E: shared runtime PostgreSQL connection layer; resolves FLAG-P0S5-1. Consolidation deferred to P0-S7; P0-S6 binding constraint (no new raw `pg_*` wrapper). |
| 1.6 | 2026-06-23 | DECISION E: resolved FLAG-P0S7-1 (Option 1 — Split). Queue collapses fully into `DatabaseConnectionInterface`. Outbox splits by persistence technology: PG delivery path on shared `DatabaseConnectionInterface`; MySQL capture path on a new `MysqlOutboxConnectionInterface` that does NOT extend or reference `DatabaseConnectionInterface`. `OutboxConnectionInterface` and `QueueConnectionInterface` deleted. |
| 1.7 | 2026-06-23 | OPEN-11: Option A — Phase 1A projection is a lossless representation of the canonical model; adapter persists the canonical checksum directly; no second checksum path; divergent projections require a future ADR. Resolves FLAG-P1AS3-1. |
| 1.8 | 2026-06-23 | FLAG-P1AS4-1 resolved (architect ruling): content.entity_taxonomies is a pure join table — (entity_id UUID, taxonomy_id UUID) composite PK only; no timestamps/checksums/metadata unless a future ADR adds relationship attributes. FLAG-P1AS4-2 resolved (architect ruling): system.aggregate_versions uses a monotonic guarded upsert — stored version only ever advances (max(current, incoming)); worker owns stale-event detection; DB guard is defense-in-depth. |
| 1.9 | 2026-06-24 | DECISION F: REST Delivery API contracts — scoped Option A (P1A-S5). Four core contracts added to `core/Contracts/`: `QueryProviderInterface`, `ResourceInterface`, `FilterSet`, `CursorPage`. ADR-038 transport-agnosticism enforced: no WP/HTTP types in contracts, Query Providers, or Resources — WP types confined to REST route registration only. Cursor pagination uses (primary_sort, id) deterministic tiebreaker. status filter constrained to public set {publish} (OPEN-10); non-public values return 400. category filter on /posts resolves via projection-side join (content.taxonomies.slug); never by WP term_id. IMPLEMENTATION_PLAN.md §4 five-bullet undercount flagged (categories/{slug} missing). |
| 1.10 | 2026-06-24 | DECISION H: Worker State Loading — Option B approved; reaffirms ADR-044 (state-sync, not event-sourcing); workers reload current WordPress state via defined WP bootstrap path in worker runtime; event payload enrichment (Option A) rejected (contradicts ADR-044 + reconciliation principle); direct-MySQL reload (Option C) rejected (bypasses WordPress as authoritative access layer). Resolves FLAG-P1AS6-1 (worker state question). DECISION I: Delete Processing — Option C approved; content.*.deleted events follow dedicated tombstone path consuming only event envelope (aggregate identity + metadata); soft-delete projection performed; no reload, no extract, no transform; canonical models and canonical-checksum surface UNCHANGED; OPEN-11 intact; AdapterInterface gains tombstone/soft-delete method (contract change). DECISION J: Stale-Event Guard — amends FLAG-P1AS4-2; Resolve-stage guard is PRIMARY, authoritative stale-event gate; adapter in-txn FOR UPDATE + GREATEST guard is MANDATORY defense-in-depth (Resolve reads outside write txn and cannot close the Resolve→write TOCTOU window alone); authorizes for P1A-S6b: PG read dependency on EventWorkerStrategy, WorkerServiceProvider wiring, Resolve-stage aggregate-version lookup, early termination before handler execution. |
| 1.11 | 2026-06-24 | DECISION K: Delivery Connection Isolation — resolves FLAG-P1AS6A-1. A shared non-FORCE_NEW connection that can libpq-reuse the relay/queue handle is not acceptable where it can undermine the Resolve-stage gate (DECISION J). Delivery reads, Resolve-stage reads, and adapter persistence use one dedicated delivery connection with guaranteed physical separation from relay/queue handles (PGSQL_CONNECT_FORCE_NEW). Sequential reuse within a worker tick is acceptable; cross-sharing with relay and queue-claim handles is prohibited. The binding lives in a new `DeliveryServiceProvider`, not `QueueServiceProvider`. No new raw pg_* wrapper — reuses `PostgresDatabaseConnection`. Constrains DECISION E (v1.6) connection-ownership allocation; satisfies DECISION J (v1.10) Resolve-read isolation requirement. |
| 1.12 | 2026-06-25 | DECISION L: Dispatcher stage — architect ruling 2026-06-25. Dispatcher is a distinct stage in the pipeline (Outbox → Dispatcher → Queue → Worker), implemented as a `WorkerStrategyInterface` on the existing Worker Engine under `core/Events/Dispatcher/`. Dedup via `UNIQUE(event_id)` on `system.queue_jobs` + `ON CONFLICT(event_id) DO NOTHING` on enqueue. Undispatched events claimed by anti-join (`NOT EXISTS (SELECT 1 FROM system.queue_jobs q WHERE q.event_id = e.event_id)`) against `system.queue_jobs`; no dispatch-status column on `system.events`; no watermark. Correct-final-state ordering; no FIFO requirement. <30s SLA unchanged. Dispatcher opens its own dedicated FORCE_NEW handle (`'dispatcher.connection.pgsql'`, via `PostgresDatabaseConnection`) physically distinct from the DECISION K delivery singleton and relay/queue handles; enqueues via `DatabaseQueueProvider::enqueueIdempotent()` (queue-claim handle). No new raw `pg_*` wrapper class (DECISION E). PID-distinctness asserted in integration test. |
| 1.13 | 2026-06-25 | DECISION L clause (g) reconciled: amended amendment-log entry for v1.12 to correctly state dispatcher opens its own FORCE_NEW `'dispatcher.connection.pgsql'` handle (NOT the DECISION K delivery singleton). The full DECISION L text in §DECISION L already stated this correctly (clause g); only the amendment-log summary row was wrong. Raises FLAG-P1AS6D-1: no container binding exposes a relay/queue runtime PG handle separately from the delivery singleton after S6c; the dispatcher's dedicated handle is a connection-topology decision pending architect ratification. |
| 1.14 | 2026-06-25 | DECISION N: delivery REST namespace is `hsp/v1` (vendor-prefixed WP convention). Renames `api/v1` to `hsp/v1` in `ContentRestRegistrar::NAMESPACE` constant, `hsp-blog/lib/api.ts` fetch paths, and `tools/smoke_e2e.php` curl paths. Doc sites reconciled (DECISION F Implements table, IMPLEMENTATION_PLAN.md §4 endpoint bullets and pipeline diagram, Phase 1A DoD, FLAG-P1AS5-1 flag text). |

---

## Table of Contents

1. [Open Items (OPEN-1 through OPEN-11)](#open-items)
2. [Decisions (DECISION 1 through DECISION 3, DECISION A, DECISION D through DECISION J)](#decisions)
3. [Implications Carried into Schema](#implications-carried-into-schema)

---

## Open Items

### OPEN-1 — Event Naming Convention

| Field | Value |
|---|---|
| **Status** | Accepted |
| **Supersedes** | Doc 1 §6, Doc 4 §8 (bare-name event examples) |

**Ruling:** All events use fully-qualified `<domain>.<aggregate>.<action>` naming.

MVP event types:
- `content.page.created` / `content.page.updated` / `content.page.deleted`
- `content.post.created` / `content.post.updated` / `content.post.deleted`
- `content.category.created` / `content.category.updated` / `content.category.deleted`

**Rationale:** Namespaced names eliminate collision risk across domains and make routing rules unambiguous without inspecting payload.

---

### OPEN-2 — system.aggregate_versions Table

| Field | Value |
|---|---|
| **Status** | Accepted |
| **Supersedes** | — |
| **Adds to** | Doc 3 §4 |

**Ruling:** Add table `system.aggregate_versions` with primary key `(aggregate_type, aggregate_id)` and columns `latest_processed_version BIGINT` and `latest_processed_at TIMESTAMPTZ`.

**Rationale:** Enables stale-event skipping at the worker level without a full scan of `system.processed_events`.

---

### OPEN-3 — Expanded system.dead_letter_jobs

| Field | Value |
|---|---|
| **Status** | Accepted |
| **Supersedes** | Doc 3 §22 |

**Ruling:** `system.dead_letter_jobs` gains four additional columns: `stack_trace TEXT`, `attempt_count INTEGER`, `worker_id UUID`, `payload_snapshot JSONB`.

**Rationale:** Operational debuggability requires the full failure context at the time of terminal failure, not just a message.

> **Amendment (v1.1 — 2026-06-21):** `worker_id` type changed from `TEXT` to `UUID`. Platform-wide column-type canon (supersedes Doc 3): all timestamps use `TIMESTAMPTZ` (bare `TIMESTAMP` drops the UTC offset); all checksums use `VARCHAR(64)` (sha256 is fixed-width); all worker identity columns use `UUID` (consistent with UUIDv7 identity per ADR-015). Workers self-assign a UUIDv7 at startup.

> **Amendment (v1.2 — 2026-06-21):** The v1.1 timestamp canon is engine-scoped. `TIMESTAMPTZ` is a PostgreSQL type and **must not** appear in MySQL migrations. The corrected platform-wide canon is:
>
> - **PostgreSQL timestamp columns** → `TIMESTAMPTZ`. No bare `TIMESTAMP` permitted.
> - **MySQL timestamp columns** (`wp_hsp_outbox.created_at`, `wp_hsp_outbox.relayed_at`, and any future MySQL timestamp columns) → `DATETIME`, written and read as UTC. UTC discipline is enforced at the application layer. MySQL `TIMESTAMP` is acceptable only if UTC auto-normalization is explicitly desired; default to `DATETIME`-UTC.
>
> The checksum canon (`VARCHAR(64)`) and worker-identity canon (`UUID`) are unchanged; both apply only on the PostgreSQL side where those column types are meaningful.
>
> **Scope:** The type canon applies platform-wide to **all** tables, including **module-owned delivery tables** (`content.pages`, `content.posts`, `content.taxonomies`, `content.media`, and any future module projection tables). It supersedes Doc 3 §9–11, which show bare `TIMESTAMP`. Module-owned tables are not enumerated in the Implications table below because they are generated in Phase 1A, but they inherit this canon and are subject to the same freeze rule. Their freeze check occurs at the Phase 1A DoD gate.

> **Amendment (v1.4 — 2026-06-21):** `payload_snapshot` is `NOT NULL` (see DECISION A). If a payload cannot be parsed to structured JSON, the raw captured representation must be persisted in a serializable form rather than omitted. Rationale: every DLQ entry must be self-contained and replayable without access to any external store.

---

### OPEN-4 — system.queue_jobs Claiming Protocol

| Field | Value |
|---|---|
| **Status** | Accepted |
| **Supersedes** | — |
| **Adds to** | Doc 3 §21 |

**Ruling:** `system.queue_jobs` gains columns `worker_id UUID` and `visibility_timeout_at TIMESTAMPTZ`. Job claiming uses `SELECT … FOR UPDATE SKIP LOCKED`. Visibility timeout duration is config-driven. A recovery process requeues jobs whose `visibility_timeout_at` has expired without completion.

**Rationale:** `SKIP LOCKED` eliminates queue-head blocking under concurrent workers; visibility timeout prevents permanent job loss from worker crashes.

> **Amendment (v1.1 — 2026-06-21):** `worker_id` type changed from `TEXT` to `UUID`. See OPEN-3 amendment for full column-type canon.

---

### OPEN-5 — Hybrid Event Store Schema

| Field | Value |
|---|---|
| **Status** | Accepted |
| **Supersedes** | Doc 3 §20 |

**Ruling:** `system.events` uses a hybrid layout. The following fields are **first-class columns**: `aggregate_version BIGINT`, `source_updated_at TIMESTAMPTZ`, `checksum VARCHAR(64)`, `correlation_id UUID`, `causation_id UUID`. All remaining metadata stays inside the `payload JSONB` column.

**Rationale:** Promotes the fields needed for indexing, dedup, and traceability to queryable columns while avoiding schema churn for ad-hoc metadata.

> **Amendment (v1.1 — 2026-06-21):** `checksum` type changed from `TEXT` to `VARCHAR(64)`. See OPEN-3 amendment for full column-type canon.

---

### OPEN-6 — Transactional Outbox and Cross-DB Relay

| Field | Value |
|---|---|
| **Status** | Accepted |
| **Supersedes** | — |
| **Adds to** | Docs 3 and 5 |

**Ruling:** The transactional outbox lives in WordPress MySQL as `wp_hsp_outbox`. A `RelayWorkerStrategy` copies rows to `system.events` in PostgreSQL. A row is marked `relayed` on `wp_hsp_outbox` **only after** the PostgreSQL commit succeeds. The MySQL claim query uses `SKIP LOCKED`. `system.events` is the **durable relayed copy**, not the capture point.

**Rationale:** Resolves the cross-database transaction boundary: write durability is achieved via the WP-side outbox; PG-side events are the authoritative relay target for all downstream consumers.

> **Amendment (v1.3 — 2026-06-21):** The original ruling established the outbox's role and relay behaviour but left the column-level DDL unspecified. This amendment freezes it.
>
> The outbox must be a **superset** of every first-class column `system.events` requires (OPEN-5), so the relay is a pure copy with no field reconstruction. Frozen `wp_hsp_outbox` DDL (MySQL, `{$wpdb->prefix}hsp_outbox`):
>
> ```sql
> id                CHAR(36)     NOT NULL,                 -- the event_id; born here
> event_type        VARCHAR(255) NOT NULL,
> event_version     INT          NOT NULL,
> aggregate_type    VARCHAR(100) NOT NULL,
> aggregate_id      VARCHAR(255) NOT NULL,
> aggregate_version BIGINT       NOT NULL,
> source_updated_at DATETIME     NOT NULL,                 -- UTC; populates system.events.source_updated_at
> checksum          CHAR(64)     NOT NULL,
> correlation_id    CHAR(36)     NOT NULL,
> causation_id      CHAR(36)     NULL,                     -- NULL for root events (Doc 8 §19–20)
> payload           JSON         NOT NULL,
> status            ENUM('pending','relayed') NOT NULL DEFAULT 'pending',
> created_at        DATETIME     NOT NULL,                 -- capture time (UTC)
> relayed_at        DATETIME     NULL,
> PRIMARY KEY (id),
> INDEX idx_relay_claim (status, created_at)              -- relay claim path
> ```
>
> **Relay fidelity rules** (`RelayWorkerStrategy`):
>
> - `system.events.id` := `wp_hsp_outbox.id` — the `event_id` is **preserved unchanged**. Do NOT generate a new UUID on relay; dedup in `system.processed_events` is keyed on `event_id`.
> - `system.events.created_at` := `wp_hsp_outbox.created_at` — this is the **capture time**, not the relay time. Relay time is recorded only in `wp_hsp_outbox.relayed_at`.
> - All OPEN-5 first-class columns (`aggregate_version`, `source_updated_at`, `checksum`, `correlation_id`, `causation_id`) copy straight across. Type casts on relay: MySQL `CHAR(36)` → PG `UUID`; MySQL `CHAR(64)` → PG `VARCHAR(64)`.
> - `wp_hsp_outbox.relayed_at` is set to the relay capture time **only after** the PostgreSQL commit succeeds (original OPEN-6 ruling preserved).
>
> **Note on `source_updated_at`:** this field was absent from prior OPEN-6 descriptions but is required by OPEN-5 as a first-class column on `system.events`. Its addition here closes that gap; no other ruling is changed.

---

### OPEN-7 — system.processed_events for Exact-Event Dedup

| Field | Value |
|---|---|
| **Status** | Accepted |
| **Supersedes** | — |
| **Adds to** | Doc 3 |

**Ruling:** Add table `system.processed_events` with primary key `event_id`, plus columns `checksum VARCHAR(64)` and `processed_at TIMESTAMPTZ`. This table serves exact-event idempotency and is distinct from `system.aggregate_versions` (which serves stale-version skipping).

**Rationale:** Two orthogonal dedup concerns require two distinct mechanisms; conflating them produces incorrect behaviour for out-of-order replays.

> **Amendment (v1.1 — 2026-06-21):** `checksum` type changed from `TEXT` to `VARCHAR(64)`. See OPEN-3 amendment for full column-type canon.

---

### OPEN-8 — Frozen DDL for system.schema_versions, system.module_versions, system.security_events

| Field | Value |
|---|---|
| **Status** | Accepted |
| **Supersedes** | — |
| **Adds to** | Doc 3 §4/§24 |

**Ruling:** Doc 3 §4/§24 described the intent of these three tables but provided no column-level DDL. This entry freezes their DDL. All timestamps are `TIMESTAMPTZ` (v1.2 canon); all checksums are `VARCHAR(64)` (v1.1 canon).

**system.schema_versions** — tracks applied migrations and rollback state (Doc 3 §24):

```sql
id             UUID         NOT NULL,
migration_name VARCHAR(255) NOT NULL,
schema_context VARCHAR(100) NOT NULL,  -- engine-qualified: 'core/mysql', 'core/pgsql', 'content/pgsql', etc.
applied_at     TIMESTAMPTZ  NOT NULL,
rolled_back_at TIMESTAMPTZ  NULL,      -- NULL = currently applied
checksum       VARCHAR(64)  NOT NULL,  -- sha256 of migration file at apply time
PRIMARY KEY (id),
UNIQUE (migration_name, schema_context)
```

**system.module_versions** — tracks module schema version history (Doc 3 §24):

```sql
id             UUID         NOT NULL,
module_name    VARCHAR(100) NOT NULL,  -- e.g. 'content', 'commerce'
schema_version VARCHAR(50)  NOT NULL,  -- semantic version string, e.g. '1.0.0'
applied_at     TIMESTAMPTZ  NOT NULL,
notes          TEXT         NULL,
PRIMARY KEY (id),
UNIQUE (module_name, schema_version),
INDEX (module_name, applied_at DESC)
```

**system.security_events** — infrastructure security audit trail (Doc 3 §4):

```sql
id          UUID         NOT NULL,
event_type  VARCHAR(100) NOT NULL,  -- fully-qualified security.<aggregate>.<action>
severity    VARCHAR(20)  NOT NULL,  -- e.g. 'low', 'medium', 'high', 'critical'
actor_type  VARCHAR(50)  NULL,      -- e.g. 'user', 'worker', 'api_key'; NULL for unauthenticated
actor_id    VARCHAR(255) NULL,      -- platform actor identifier; VARCHAR to support non-UUID actors
ip_address  VARCHAR(45)  NULL,      -- IPv4 or IPv6
metadata    JSONB        NOT NULL,
created_at  TIMESTAMPTZ  NOT NULL,
PRIMARY KEY (id),
INDEX (event_type, created_at)
```

**Rationale:** `actor_id` uses `VARCHAR(255)` rather than `UUID` because the security event log must accommodate unauthenticated actors, API keys, and external identifiers that are not UUIDs. `severity` is a required triage field. `actor_type` disambiguates the actor_id namespace.

---

### OPEN-9 — ModuleInterface Union Shape

| Field | Value |
|---|---|
| **Status** | Accepted |
| **Supersedes** | Doc 2 §12 |
| **Adds to** | — |

**Ruling:** `ModuleInterface` is the **union** of declarative discovery methods and WordPress lifecycle methods. Neither set replaces the other; both must be present.

Declarative discovery (used by module registry at boot):
- `getName(): string`
- `getServiceProvider(): ServiceProviderInterface`
- `getMigrations(): array`
- `getEventTypes(): array`

WordPress lifecycle (called by the module registry in order):
- `register(): void` — register DI bindings and WordPress hooks; called before `boot()`
- `boot(): void` — called after all modules have registered; use for cross-module-safe initialization
- `activate(): void` — called on plugin activation (install migrations, seed config, register capabilities)
- `deactivate(): void` — called on plugin deactivation (remove runtime registrations; do NOT drop data)
- `upgrade(): void` — called on plugin version bump (run pending migrations, apply version-specific transforms)

**Rationale:** Discovery and lifecycle solve different problems. Separating them into two interfaces would require the registry to hold two references per module and keep them in sync. The union interface keeps the module as a single cohesive unit while making all registry obligations explicit at the type level. Doc 2 §12 described lifecycle only; this ruling adds the declarative side.

---

### OPEN-11 — Canonical Checksum vs Projection Checksum

| Field | Value |
|---|---|
| **Status** | **Accepted (2026-06-23)** |
| **Raised** | 2026-06-23 — P1A-S3 close |
| **Resolves** | FLAG-P1AS3-1 |

#### Ruling (Option A — Phase 1A projection is a lossless representation of the canonical model)

**Phase 1A projection rule:** The delivery projection contains exactly the delivery fields represented by the canonical model — no canonical field omitted, no derived columns added. Explicitly excluded from Phase 1A projections: precomputed URI variants, search vectors, denormalized aggregates, analytics/ranking columns.

**Checksum rule:** The adapter persists `model.getChecksum()` (the canonical checksum) **directly** as the stored `content.*` checksum. Write-suppression compares the stored `content.*` checksum against the canonical checksum. No second/projection-shaped checksum path is permitted in Phase 1A.

**Scope and limits:** This ruling does NOT establish that all schemas must always mirror canonical models. It establishes that WHERE a projection is a lossless representation of the canonical model, the canonical checksum is the authoritative checksum. When a future projection intentionally diverges (search/analytics/cache/reporting/denormalized read models), that projection becomes responsible for computing and persisting its own projection checksum — and that divergence requires a future ADR before implementation.

**Relationship to DECISION 3:** OPEN-11 clarifies, does not supersede, DECISION 3. DECISION 3's "freshly-computed projection checksum" equals the canonical checksum for Phase 1A precisely because the projection is lossless. The three-op single-PG-transaction rule (projection upsert + `system.processed_events` insert + `system.aggregate_versions` upsert) is unchanged.

---

### FLAG-P1AS4-1 — content.entity_taxonomies Column Shape

| Field | Value |
|---|---|
| **Status** | **Resolved — architect ruling 2026-06-23** |
| **Raised** | 2026-06-23 — P1A-S4 kickoff |

**Ruling (architect, 2026-06-23):** `content.entity_taxonomies` is a pure join table for Phase 1A — exactly `(entity_id UUID, taxonomy_id UUID)`, composite PK, no timestamps/checksums/metadata unless a future ADR adds relationship attributes.

---

### FLAG-P1AS4-2 — aggregate_versions Upsert Monotonicity

| Field | Value |
|---|---|
| **Status** | **Resolved — architect ruling 2026-06-23** |
| **Raised** | 2026-06-23 — P1A-S4 kickoff |

**Ruling (architect, 2026-06-23):** `system.aggregate_versions` uses a monotonic guarded upsert — stored version only ever advances (max(current, incoming)). Worker owns stale-event detection; the DB guard is defense-in-depth so aggregate progress can never regress.

---

### FLAG-P1AS4-3 — `bulkPersist()` version guard and event recording

| Field | Value |
|---|---|
| **Status** | **Resolved — architect ruling 2026-06-23** |
| **Raised** | 2026-06-23 — P1A-S4 close |

**Ruling (architect, 2026-06-23):** `persist()` is the ONLY supported persistence entry point in Phase 1A. `bulkPersist()` stays on `AdapterInterface` as a declared future capability (signature unchanged) but performs NO projection writes in Phase 1A. All three Phase 1A adapters (PageAdapter, PostAdapter, CategoryAdapter) implement `bulkPersist()` as a fail-fast stub: `throw new \LogicException('bulkPersist() is not implemented in Phase 1A.');` — no transaction, no projection write, no partial path. The correct guarded batch path (events + version context, same guarantees as `persist()`) is deferred to a future ADR that lands with the first batch-with-events caller. No reconciliation, replay, or worker path may call `bulkPersist()` in Phase 1A.

---

## Decisions

### DECISION 1 — Near-Atomic Capture + Reconciliation Backstop (ADR-029 Revised)

| Field | Value |
|---|---|
| **Status** | Accepted |
| **Supersedes** | ADR-029 (Doc 5) |

**Ruling:** WordPress exposes no universal transaction boundary that can wrap an editorial write and an outbox insert atomically. Therefore: capture writes to `wp_hsp_outbox` immediately **after** the WordPress commit completes. The "never lose a sync" guarantee rests on four pillars in sequence: (1) durable outbox write, (2) at-least-once relay to `system.events`, (3) event replay capability, and (4) periodic reconciliation where WordPress is the system of record (ADR-027, ADR-045). ADR-029's assumption of a true atomic capture is revised away.

**Rationale:** Post-commit outbox write is the only safe option given WordPress's plugin hook architecture; reconciliation closes the narrow gap between WP commit and outbox write.

---

### DECISION 2 — aggregate_version as Per-Aggregate Source Counter (ADR-021 Clarification)

| Field | Value |
|---|---|
| **Status** | Accepted |
| **Supersedes** | — |
| **Clarifies** | ADR-021 |

**Ruling (v1.0 — superseded storage only):** ~~`aggregate_version` is a per-aggregate monotonic counter stored in WordPress source metadata: key `_hsp_version` in `wp_postmeta` (for pages and posts) and `wp_termmeta` (for categories). The increment **must** be atomic at the database level (e.g., `UPDATE … SET meta_value = meta_value + 1`). Using `update_post_meta` / `update_term_meta` read-modify-write is prohibited because the race condition produces duplicate version numbers and breaks the stale-skip logic in `system.aggregate_versions`.~~

> **Amendment (v1.1 — 2026-06-21):** The postmeta/termmeta storage in v1.0 is superseded. `wp_postmeta` and `wp_termmeta` have no unique key on `(object_id, meta_key)`, `meta_value` is `LONGTEXT`, and a bare `UPDATE` on a not-yet-existing `_hsp_version` row affects zero rows — reintroducing the exact duplicate-version race this decision was written to prevent. The counter therefore moves to a dedicated MySQL table in the WordPress database:
>
> ```sql
> {$wpdb->prefix}hsp_aggregate_counters (
>   aggregate_type VARCHAR(100) NOT NULL,
>   aggregate_id   VARCHAR(255) NOT NULL,
>   version        BIGINT       NOT NULL,
>   PRIMARY KEY (aggregate_type, aggregate_id)
> )
> ```
>
> Atomic increment + read in one round-trip:
>
> ```sql
> INSERT INTO {$wpdb->prefix}hsp_aggregate_counters
>   (aggregate_type, aggregate_id, version)
> VALUES (?, ?, 1)
> ON DUPLICATE KEY UPDATE version = LAST_INSERT_ID(version + 1);
> -- then: SELECT LAST_INSERT_ID();
> ```
>
> The returned value is the `aggregate_version` written to the outbox row and relayed into `system.events`. The intent of v1.0 is unchanged (per-aggregate monotonic counter in the WP source DB, genuinely atomic at the SQL level); only the storage location changes.

**Rationale (unchanged):** Application-layer read-modify-write under concurrent saves cannot guarantee uniqueness; a single SQL atomic operation does.

---

### DECISION 3 — Idempotency via Projection Checksum (ADR-025 Implementation)

| Field | Value |
|---|---|
| **Status** | Accepted |
| **Supersedes** | — |
| **Implements** | ADR-025 |

**Ruling:** `system.processed_events` is retained. Write-suppression logic compares a **freshly-computed projection checksum** against the stored `content.*` checksum in the target store — **not** the event's own checksum (which is traceability only, not dedup). The worker's three operations — projection upsert, `system.processed_events` insert, and `system.aggregate_versions` upsert — **must** commit inside a single PostgreSQL transaction.

> **See OPEN-11:** For Phase 1A lossless projections, "freshly-computed projection checksum" equals `canonical.getChecksum()`. The three-op transaction rule is unchanged.

**Rationale:** Event-checksum dedup fails for legitimate re-deliveries carrying different event IDs; projection-checksum dedup correctly suppresses writes whose observable output would be identical.

---

### DECISION A — dead_letter_jobs.payload_snapshot NOT NULL

| Field | Value |
|---|---|
| **Status** | Accepted |
| **Supersedes** | — |
| **Amends** | OPEN-3 (v1.4) |

**Ruling:** `system.dead_letter_jobs.payload_snapshot` is `NOT NULL`. If the job payload cannot be parsed to structured JSON at failure time, the raw captured representation must be serialized into a form that can be stored as JSONB (e.g. wrapped as `{"raw": "<escaped string>"}`) rather than omitting it. An adapter that sets `payload_snapshot = NULL` violates this ruling.

**Rationale:** Every DLQ entry must be self-contained and replayable without access to any external store. A NULL payload_snapshot makes root-cause diagnosis and replay impossible, defeating the purpose of the dead letter queue.

---

### DECISION D — AdapterInterface includes bulkPersist()

| Field | Value |
|---|---|
| **Status** | Accepted |
| **Supersedes** | — |
| **Implements** | Doc 7 §19 |

**Ruling:** `AdapterInterface` exposes both `persist(CanonicalModelInterface $model, EventInterface $event): void` and `bulkPersist(array $models): void`. `bulkPersist()` is a **capability declaration**, not a strategy mandate: a conforming adapter may implement it by looping `persist()` internally. Bulk SQL, batch upserts, and single-transaction semantics for bulk operations are implementation-defined and specified at the adapter implementation task, not here.

**Rationale:** Doc 7 §19 requires adapters to support bulk operations for reconciliation, full replay, and bulk import workflows. Specifying the method at the interface level ensures all adapters are capable of serving those callers without requiring callers to know the adapter's implementation strategy.

---

### DECISION E — Shared Runtime PostgreSQL Connection Layer (resolves FLAG-P0S5-1)

| Field | Value |
|---|---|
| **Status** | Accepted |
| **Supersedes** | — |
| **Adds to** | Doc 4 §12; Doc 2 (core infrastructure layout) |
| **Resolves** | FLAG-P0S5-1 |

**Ruling:** Runtime DML subsystems (outbox relay, queue provider, worker infrastructure, and future runtime services) share a single runtime PostgreSQL connection abstraction. The migration engine is explicitly excluded and retains its own migration-specific abstraction (`ConnectionInterface`, `execute(string $sql): void`, DDL-only) — its DDL/lifecycle/error semantics differ and must stay isolated.

Consolidation is deferred to P0-S7. No consolidation occurs during P0-S5 or P0-S6. The three existing `pg_*` wrappers (`PgsqlConnection` [migrations], `PgsqlOutboxConnection`, `DatabaseQueueConnection`) are an accepted temporary duplication, not a permanent pattern.

**P0-S6 constraint (binding):** P0-S6 introduces NO additional raw `pg_*` wrapper class. The worker obtains PostgreSQL access through an existing runtime provider/connection (e.g. via `QueueProviderInterface`, Doc 4 §12), never a new low-level handle.

**P0-S7 authorized scope:** introduce a shared runtime `DatabaseConnectionInterface` (`execute`/`query`/`beginTransaction`/`commit`/`rollback`) + one shared PG implementation under `core/Database/`; collapse `OutboxConnectionInterface` and `QueueConnectionInterface` into it; replace the duplicated runtime wrappers with the shared implementation; the connection layer throws a single infrastructure `DatabaseException`, which subsystems may translate to `QueueException` / `OutboxWriteException` / `WorkerException` at their boundary. Migration engine untouched. This is consolidation only — behaviour, transaction semantics, and test coverage must remain unchanged.

> **Amendment (v1.6 — 2026-06-23 — FLAG-P0S7-1 Option 1 — Split):**
>
> `DatabaseConnectionInterface` is **PostgreSQL-only**. No MySQL connection may implement or extend it.
>
> **Queue (collapse):** `QueueConnectionInterface` is deleted. `DatabaseQueueConnection` and `DatabaseQueueProvider` depend directly on `DatabaseConnectionInterface`. `DatabaseException` is translated to `QueueException` at the `DatabaseQueueConnection` boundary.
>
> **Outbox (split by persistence technology):** `OutboxConnectionInterface` is deleted. The dual-technology outbox path is split into two distinct abstractions:
>
> - **PG delivery path:** `PgsqlOutboxConnection` implements `DatabaseConnectionInterface` directly (same shared layer as queue). `DatabaseException` is translated to `OutboxWriteException` at the `PgsqlOutboxConnection` boundary.
> - **MySQL capture path:** `MysqliOutboxConnection` implements a new `MysqlOutboxConnectionInterface` scoped to `core/Events/Outbox/Connection/`. This interface does NOT extend or reference `DatabaseConnectionInterface` — it is MySQL-only and carries its own `OutboxWriteException` error semantics.
>
> `RelayWorkerStrategy` holds one `MysqlOutboxConnectionInterface` (MySQL capture) + one `DatabaseConnectionInterface` (PG delivery) and coordinates the two explicitly — it does not treat them as one abstraction.
>
> **Rollback semantics (historical, binding):** Both original `PgsqlOutboxConnection::rollback()` (P0-S4, commit `084456a`) and `DatabaseQueueConnection::rollback()` (P0-S5, commit `084456a`) swallowed `pg_query('ROLLBACK')` failures silently — false return was ignored, no exception thrown. `PostgresDatabaseConnection::rollback()` preserves this behaviour exactly.

**Rationale:** Core owns reusable runtime infrastructure; subsystems must not each reinvent it. Capping proliferation at three and consolidating at the freeze gate avoids refactor risk during active implementation while preventing the pattern from entrenching. The split (v1.6) reflects that MySQL and PostgreSQL have fundamentally different connection, transaction, and error models — a single interface spanning both would be a leaky abstraction.

---

### DECISION F — REST Delivery API Contracts (P1A-S5)

| Field | Value |
|---|---|
| **Status** | Accepted |
| **Session** | P1A-S5 (2026-06-24) |
| **Authority** | Doc 9 §6 (ownership), §12 (filtering), §13 (pagination), ADR-038 (transport-agnostic), ADR-040 (consumer boundary) |
| **Implements** | Six Phase 1A read endpoints: GET /hsp/v1/pages, /hsp/v1/pages/{slug}, /hsp/v1/posts, /hsp/v1/posts/{slug}, /hsp/v1/categories, /hsp/v1/categories/{slug} |

**Ruling — Option A (scoped):** Four contracts added to `core/Contracts/` to satisfy Doc 9 §6 while keeping scope to what the six read endpoints exercise:

- **`QueryProviderInterface`** — `list(FilterSet): CursorPage` + `findBySlug(string): ?array`. Implementations MUST query delivery projections only (no WordPress reads — ADR-040). Implementations live in modules.
- **`ResourceInterface`** — `toArray(array): array` + `toCollection(array, ?string): array`. Serialization only; no business logic; no internal columns leaked. Implementations live in modules.
- **`FilterSet`** — Immutable value object carrying validated filter parameters (slug, status, categorySlug, publishedAfter, cursor, limit). Built by the REST registration boundary from sanitized request parameters.
- **`CursorPage`** — Immutable value object returned by `list()`; carries `$rows` and opaque `?$nextCursor`.

**Transport-agnosticism (ADR-038 — binding constraint):** No WP_REST_Request, WP_REST_Response, or any HTTP/framework type may appear in Query Providers or Resources. These types are confined to the REST route registration layer (`modules/Content/Rest/ContentRestRegistrar`). This preserves the query and resource layer for future transports (GraphQL, gRPC, etc.) without redesign.

**`TransportContract` and `SecurityContract` deferred:** These are Future/out-of-MVP scope (ADR-038 future transports; Doc 9 §22 authenticated endpoints). Building them now violates the no-Future-Vision rule.

**Cursor pagination design:** Opaque base64url token encoding `{ "s": "<primary_sort_value>", "id": "<uuid>" }`. Seek predicate uses `(primary_sort, id)` composite tiebreaker, proving no skipped or duplicated rows across page boundaries when rows share the primary sort value. Sort keys: `(published_at DESC, id DESC)` for pages/posts; `(name ASC, id ASC)` for categories.

**Default listing behavior:** `WHERE status = 'publish' AND deleted_at IS NULL` (OPEN-10 public set). The `?status=` filter accepts only values in the public set `{publish}`; any other value returns HTTP 400 (do NOT silently coerce). This is validated at the REST boundary, not inside Query Providers.

**Category filter on /posts:** The `?category=` filter resolves by category slug via a projection-side EXISTS subquery (`content.posts → content.entity_taxonomies → content.taxonomies.slug`). Never by WP term_id. Never in the Resource layer. (Architect ruling, P1A-S5.)

**`findBySlug` on missing/soft-deleted row:** returns `null`; REST boundary translates to 404. Empty 200 is prohibited.

**Internal column exclusion (ADR-040):** Resources expose ONLY contract fields. Internal columns (`id UUID`, `source_post_id`, `source_term_id`, `checksum`, `synced_at`, `created_at`, `taxonomy_type`, `*_jsonb` internals unless contractually intended) are never serialized into responses.

**Rationale:** Doc 9 §6 requires core to own API/Query/Serialization/Filtering contracts. Introducing all four contracts now satisfies the architectural principle without violating the MVP scope constraint (no Transport or Security contracts). Keeping WP types out of Query Providers and Resources satisfies ADR-038 without requiring a separate transport abstraction layer at this stage.

---

### DECISION H — Worker State Loading (ADR-044 Reaffirmation)

| Field | Value |
|---|---|
| **Status** | Accepted |
| **Date** | 2026-06-24 |
| **Session** | P1A-S6b pre-implementation |
| **Resolves** | FLAG-P1AS6-1 (worker state loading question) |
| **Reaffirms** | ADR-044 (stateless workers, state-synchronization model) |

**Ruling — Option B approved:** Workers reload current WordPress state during event processing via a defined WordPress bootstrap path in the worker runtime. The platform is a **state-synchronization** system, not an event-sourcing system (ADR-044). On each event, the worker reads the current authoritative WordPress state and projects it into the delivery store.

**Option A rejected — event payload enrichment:** Enriching the event payload with entity snapshots at capture time is rejected. A captured snapshot represents WordPress state at capture time, not at process time; replaying events with stale snapshots would project outdated state into delivery, contradicting the reconciliation principle (ADR-045, ADR-027). This would also contradict ADR-044 directly.

**Option C rejected — direct-MySQL reload:** Reading WordPress entity state directly from MySQL (bypassing the WordPress object layer) is rejected. WordPress is the authoritative access layer for its own data; a second raw-MySQL path in handlers would introduce a second persistence dependency, bypass WordPress caching, and require handlers to stay synchronized with WordPress schema internals. The WordPress bootstrap path in Option B already provides safe, authoritative entity access.

**Operational bootstrap details:** The exact WP bootstrap sequence within the worker runtime (e.g., which WP functions are available, how `wp-load.php` is invoked, which hooks fire in the worker process) is an operational concern. This detail is deferred to Doc 10 / an ops-focused session. It must not be resolved by assumption in handler code.

**Rationale:** State-synchronization means each event is an instruction to "sync this aggregate" — the handler fetches current state from WordPress and overwrites the projection. This is the correct model for a CMS sync platform where WordPress is system of record. Payload enrichment couples event schema to the handler's data requirements, bloating the event store and preventing the platform from being used for replay-to-current-state scenarios.

---

### DECISION I — Delete Processing via Tombstone Path

| Field | Value |
|---|---|
| **Status** | Accepted |
| **Date** | 2026-06-24 |
| **Session** | P1A-S6b pre-implementation |
| **Resolves** | FLAG-P1AS6-1 (delete processing question) |
| **Amends** | DECISION D (AdapterInterface — new method added) |
| **Consistent with** | OPEN-11 (canonical models and checksum surface unchanged) |

**Ruling — Option C approved:** `content.*.deleted` events follow a **dedicated tombstone path** that is structurally separate from the create/update path. The tombstone path:

1. Consumes only the **event envelope** (aggregate type + aggregate ID + event metadata). No WordPress state reload occurs.
2. Performs a **soft-delete projection**: sets `deleted_at = now()` on the target `content.*` row (consistent with DECISION F's `WHERE deleted_at IS NULL` filter invariant).
3. Does **not** invoke the Extractor, Transformer, or canonical model pipeline. There is no WordPress entity to reload — it may have been permanently deleted or transitioned to a non-public state.
4. Records the tombstone in `system.processed_events` and updates `system.aggregate_versions`, inside the same single-PostgreSQL-transaction rule (DECISION 3 three-op atomicity — projection upsert → `system.processed_events` insert → `system.aggregate_versions` upsert).

**Canonical models and OPEN-11 checksum surface: UNCHANGED.** The tombstone path never computes a new canonical checksum and never writes the `checksum` column on the target row. The stored checksum from the last create/update event is preserved as-is. OPEN-11 remains fully intact.

**AdapterInterface contract change:** `AdapterInterface` gains a tombstone/soft-delete method:

```php
public function tombstone(string $aggregateType, string $aggregateId, EventInterface $event): void;
```

This is an additive contract change. All existing adapter implementations (PageAdapter, PostAdapter, CategoryAdapter) must implement it. The method sets `deleted_at` on the target row inside a single-PG transaction covering all three DECISION 3 ops. If the target row does not exist (e.g., the create event was never processed), the tombstone is a no-op for the projection write but still records in `system.processed_events` and updates `system.aggregate_versions`.

**Rationale:** Routing delete events through the same extract→transform→persist pipeline is unsound: WordPress state may not be available (the post may have been permanently deleted), and the canonical model for a soft-deleted entity is undefined. A dedicated tombstone path with a minimal contract keeps the delete semantic explicit, avoids phantom WordPress reads, and preserves the clean separation between the create/update pipeline and the delete semantic.

---

### DECISION J — Stale-Event Guard: Resolve-Stage Primary + In-Txn Defense-in-Depth (Amends FLAG-P1AS4-2)

| Field | Value |
|---|---|
| **Status** | Accepted |
| **Date** | 2026-06-24 |
| **Session** | P1A-S6b pre-implementation |
| **Amends** | FLAG-P1AS4-2 (aggregate_versions monotonicity ruling, v1.8) |
| **Consistent with** | DECISION 3 (three-op single-PG-transaction) |

**Ruling:** The stale-event guard operates at two layers. Both layers are **mandatory**. Their roles are distinct and non-interchangeable.

**Layer 1 — Resolve-stage guard (PRIMARY, authoritative gate):** Before invoking any handler, `EventWorkerStrategy` performs a PostgreSQL read to fetch the current `latest_processed_version` from `system.aggregate_versions` for the event's aggregate. If the incoming event's `aggregate_version` ≤ the stored `latest_processed_version`, the event is **stale**: the strategy terminates early (before handler execution), marks the job complete on the queue (not as a failure), and records the skip in telemetry. This is the authoritative stale-event decision point.

**Layer 2 — Adapter in-txn FOR UPDATE + GREATEST guard (MANDATORY, defense-in-depth):** The existing adapter-side guard (in-transaction `FOR UPDATE` lock on `system.aggregate_versions` + `GREATEST(latest_processed_version, incoming)` upsert, per FLAG-P1AS4-2 ruling) is retained and remains mandatory. It closes the **Resolve→write TOCTOU window**: the Resolve read (Layer 1) occurs outside the write transaction; a concurrent worker processing a higher-version event for the same aggregate could commit between the Resolve read and the adapter's write. The `GREATEST()` guard ensures the stored version can only advance, even if two workers race on the same aggregate in the window between Resolve and write.

**Authorizations for P1A-S6b (binding):**

- `EventWorkerStrategy` may take a PostgreSQL read dependency (via `DatabaseConnectionInterface` or a dedicated aggregate-version query abstraction) for the Resolve-stage lookup.
- `WorkerServiceProvider` is authorized to wire the aggregate-version query dependency into `EventWorkerStrategy` via constructor injection (ADR-012 compliant — no service-locator calls).
- The Resolve-stage reads `system.aggregate_versions` using a non-locking SELECT (no `FOR UPDATE` at Resolve time — the lock is taken only inside the adapter's write transaction at Layer 2).
- Early termination at the Resolve stage does NOT mark the job as a DLQ failure. It is a successful no-op: the event was already superseded by a later version.

**Rationale:** FLAG-P1AS4-2 (v1.8) established the monotonic GREATEST guard as defense-in-depth. However, it left the primary stale-event gate undefined — it said "worker owns stale-event detection" without specifying where in the EventWorkerStrategy pipeline the detection occurs. This ruling fixes the gate at the Resolve stage (Step 4 of the Doc 8 §7 pipeline: Claim→Load→Validate→**Resolve**→Execute→Commit→Ack), which is the earliest safe point after the event is validated but before any handler work begins. Terminating at Resolve avoids unnecessary WordPress state reloads (DECISION H) for events that are already stale.

---

### DECISION K — Delivery Connection Isolation (Resolves FLAG-P1AS6A-1)

| Field | Value |
|---|---|
| **Status** | Accepted |
| **Date** | 2026-06-24 |
| **Session** | P1A-S6c |
| **Resolves** | FLAG-P1AS6A-1 |
| **Constrains** | DECISION E (v1.6) — connection-ownership allocation |
| **Satisfies** | DECISION J (v1.10) — Resolve-read isolation requirement |

**Ruling:**

**(a) Non-FORCE_NEW shared connection not acceptable for delivery/Resolve reads.**  
A `DatabaseConnectionInterface` binding opened with a plain `pg_connect()` (no `PGSQL_CONNECT_FORCE_NEW`) that could libpq-reuse the relay handle (`outbox.connection.pgsql`) or any other transactional handle in the same PHP process is not acceptable. PHP libpq returns a pooled handle for identical DSN strings; if that handle coincides with a handle under an open transaction (relay OR queue claim), the delivery connection would be reading inside that transaction — violating the isolation required by DECISION J's Resolve-stage stale check.

**(b) One dedicated delivery connection with guaranteed physical separation.**  
Delivery reads (REST query providers), Resolve-stage stale-event reads (`EventWorkerStrategy`), and adapter persistence (all three Content adapters) use exactly **one** dedicated `DatabaseConnectionInterface` binding that is opened with `PGSQL_CONNECT_FORCE_NEW`. This guarantees a distinct physical libpq link from the relay handle (`outbox.connection.pgsql`, `MysqliOutboxConnection`) and the queue-claim handle (`queue.connection.pgsql`, `DatabaseQueueConnection`). Sequential reuse of the same delivery connection within a worker tick — for both the Resolve-stage read and the subsequent adapter write transaction — is **acceptable and intended** (DECISION J Layer 1 reads outside the write transaction; sequential use on one link is not sharing). Cross-sharing with the relay handle or the queue-claim handle is **prohibited**.

**(c) Binding lives in DeliveryServiceProvider; no new raw pg_* wrapper.**  
The `DatabaseConnectionInterface` singleton binding is removed from `QueueServiceProvider` and relocated to a new `core/Container/Definitions/DeliveryServiceProvider`. `DeliveryServiceProvider` reuses the existing `PostgresDatabaseConnection` class — no new raw `pg_*` wrapper class is introduced (DECISION E constraint preserved). `DeliveryServiceProvider` is registered in `ContainerBuilder` before `WorkerServiceProvider` and `ContentServiceProvider`, which are its consumers.

**Rationale:** The Resolve-stage stale-event gate (DECISION J Layer 1) is the PRIMARY correctness gate — it reads `system.aggregate_versions` before handler invocation. If that read executes on a connection that shares a libpq link with an open relay transaction, the read may observe uncommitted relay state or be blocked. `PGSQL_CONNECT_FORCE_NEW` eliminates this risk unconditionally. The precedent for FORCE_NEW on the queue-claim path was established in P0-S5 (FLAG-P0S5-1); this decision applies the same discipline to the delivery/Resolve path.

---

### DECISION L — Dispatcher Stage: system.events → system.queue_jobs (Architect Ruling 2026-06-25)

| Field | Value |
|---|---|
| **Status** | Accepted |
| **Date** | 2026-06-25 |
| **Session** | P1A-S6d |
| **Authority** | Doc 1 §157/§272; Doc 4 §3 (Outbox→Dispatcher→Queue→Worker); Doc 2 §16 (`core/Events/Dispatcher/`); Doc 11 §24 (<30s SLA); DECISION E v1.6 (no new pg_* wrapper); DECISION K v1.11 (delivery connection) |

**Ruling:**

**(a) Dispatcher is a distinct pipeline stage.** The resolved pipeline is: `wp_hsp_outbox` → `RelayWorkerStrategy` → `system.events` → **Dispatcher** → `system.queue_jobs` → `EventWorkerStrategy` → projection. The Dispatcher is responsible solely for moving relayed events from `system.events` into the queue; it does not process or transform events.

**(b) Implementation as WorkerStrategyInterface.** The Dispatcher is implemented as `DispatcherWorkerStrategy` under `core/Events/Dispatcher/`, plugged into the existing Worker Engine (one `WorkerEngine` instance driven by `DispatcherWorkerStrategy`). No new worker engine infrastructure is introduced.

**(c) Claim model — anti-join, no watermark, no status column.** Each tick selects undispatched events via:
```sql
SELECT e.id, e.event_type, e.queue_name, e.aggregate_type, e.aggregate_id
FROM   system.events e
WHERE  NOT EXISTS (
    SELECT 1 FROM system.queue_jobs q WHERE q.event_id = e.id
)
FOR UPDATE SKIP LOCKED
LIMIT  N
```
No `dispatch_status` column is added to `system.events` (frozen schema — OPEN-6). No watermark / high-water-mark pointer is maintained. The `NOT EXISTS` anti-join is the authoritative undispatched check.

**(d) Dedup via UNIQUE(event_id) + ON CONFLICT DO NOTHING.** A new forward migration adds `UNIQUE(event_id)` to `system.queue_jobs`. `DatabaseQueueProvider` gains `enqueueIdempotent()` (separate from `enqueue()` to avoid breaking the existing interface): it executes `INSERT … ON CONFLICT(event_id) DO NOTHING`. `completed` rows are retained in `system.queue_jobs` (status update, not DELETE), so the UNIQUE constraint permanently blocks re-dispatch of already-completed events — this is the intended invariant.

**(e) Queue name.** Hardcoded to `'content'` for Phase 1A — all MVP events are content-domain events. Multi-queue routing (event_type-prefix → partition) is not in any frozen doc or the P1A-S6d authority. A future ADR must authorize it before a second domain partition is introduced.

**(f) Ordering.** No FIFO guarantee. The anti-join selects available events; ORDER BY `e.created_at ASC` provides approximate arrival order. Correct-final-state semantics hold regardless of dispatch order.

**(g) Connection constraints.** The Dispatcher is relay/queue-side system DML and MUST NOT use the DECISION K delivery handle (`DatabaseConnectionInterface` singleton, `DeliveryServiceProvider`). It opens its own dedicated FORCE_NEW handle bound as `'dispatcher.connection.pgsql'` (registered in `DispatcherServiceProvider`), following the same pattern as DECISION K. This guarantees the dispatcher handle is physically distinct from both the delivery handle and the relay/queue handles. No new raw `pg_*` wrapper class is introduced (DECISION E constraint is on wrapper classes, not on additional `pg_connect()` calls; `PostgresDatabaseConnection` is an existing class). The dispatcher enqueues via `DatabaseQueueProvider::enqueueIdempotent()`, which uses the queue-claim handle (`queue.connection.pgsql`).

**(g) SLA.** The <30s end-to-end SLA (Doc 11 §24) is unchanged. The Dispatcher adds one hop (system.events → queue_jobs) that must complete within the SLA budget.

**Rationale:** The gap between relay and queue was always implicit in the architecture (Doc 4 §3) but never implemented. Making it a `WorkerStrategyInterface` reuses the existing engine/heartbeat/shutdown infrastructure. Anti-join dedup is the simplest correct model: no state to track on the events table, no new columns, no watermark drift risk. UNIQUE(event_id) provides the database-level idempotency guarantee.

---

### DECISION N — Delivery REST Namespace: `hsp/v1`

| Field | Value |
|---|---|
| **Status** | Accepted |
| **Date** | 2026-06-25 |
| **Session** | P1A-S7 |
| **Authority** | Doc 9 §7 (versioned REST prefix); WP REST API convention (vendor-prefixed namespaces) |

**Ruling:** The WordPress REST namespace for all HSP delivery endpoints is `hsp/v1`.

- `hsp` is the vendor prefix — unambiguous, collision-safe under the WP REST convention where namespaces take the form `vendor/vN`.
- `v1` is the contract version. Future breaking changes in the API contract must go to `v2`; additive non-breaking changes stay in `v1`.
- The namespace is defined in exactly **one** place: `ContentRestRegistrar::NAMESPACE = 'hsp/v1'`. All `register_rest_route()` calls reference this constant. No literal namespace string may appear elsewhere in PHP code.
- Consumer clients (`hsp-blog/lib/api.ts`) and smoke-test tooling (`tools/smoke_e2e.php`) must use the `hsp/v1` path prefix in all fetch/curl calls.

**Supersedes:** The prior `api/v1` string used in `ContentRestRegistrar::NAMESPACE` (P1A-S5 through P1A-S6). `api/v1` was an un-prefixed placeholder; it is replaced by this ruling and must not appear anywhere in the codebase.

**Rationale:** WordPress REST namespaces are conventionally vendor-prefixed (`wc/v3`, `wp/v2`, etc.). A bare `api/v1` prefix is not vendor-scoped and risks collision with other plugins registering the same namespace, or with future WP core endpoints. `hsp/v1` is unique to this platform and communicates both ownership and contract generation at a glance.

---

### OPEN-10 — Unpublish Transition Capture: event action and projection model for post_status leaving the public set

| Field | Value |
|---|---|
| **Status** | **Resolved — P1A-S1 close (2026-06-23)** |
| **Raised** | 2026-06-23 — P1A-S1 review |
| **Resolved** | 2026-06-23 — architect ruling, implemented in P1A-S1 |
| **Blocks** | ~~`HookWiring::onTransitionPostStatus` guard completion~~ — resolved |

#### Ruling (Option A — public-set membership, `*.deleted` on exit)

**Public set = `{publish}` only.** `draft`, `auto-draft`, `pending`, `private`, `future`, `inherit`, `trash` are all non-public.

**Approved transition matrix (implemented in `HookWiring::onTransitionPostStatus`):**

| Old status | New status | Event emitted |
|---|---|---|
| non-public | `publish` | `content.{type}.created` (entry) |
| `publish` | `publish` | `content.{type}.updated` (in-set) |
| `publish` | non-public (any) | `content.{type}.deleted` (exit) |
| non-public | non-public | NO event |

`wp_trash_post` is suppressed when `transition_post_status` already handled the post_id in the same request (transition is authoritative for trash). `after_delete_post` always emits `*.deleted` independently (permanent hard-delete path, no overlap with transition).

**Sub-question rulings:**
1. Option A governs all exit transitions for MVP.
2. `private` is NOT in the public set — `publish → private` emits `*.deleted`.
3. `future` is NOT in the public set — `publish → future` emits `*.deleted`; when the cron fires and status moves to `publish`, that transition emits `*.created`.
4. `wp_trash_post` and `after_delete_post` remain as separate wired hooks. `wp_trash_post` is suppressed by the `$handledByTransition` guard when `transition_post_status` already fired (avoiding double-emit for a trash action). `after_delete_post` is NOT suppressed (it is the hard-delete path, fires independently of transition for permanent deletes from the trash screen).
5. Sub-question 5 (Option B adapter branching) is moot — Option A was chosen.

#### Problem statement (retained for context)

`HookWiring::onTransitionPostStatus` previously bailed on every transition whose `$newStatus !== 'publish'`. This dropped four WordPress post-status changes that are not trash operations and are not caught by `wp_trash_post` or `after_delete_post`: `publish → draft`, `publish → pending`, `publish → private`, `publish → future`. The result was a lost sync — a stale published row in the delivery projection with no delete event emitted.

---

## Implications Carried into Schema

> **This table is ADDITIVE: it lists only deltas from Doc 3. Base table DDL remains governed by Doc 3 §4/§20–24. Migrations must compose Doc 3 base + these deltas; freeze checks verify both.**

The following tables and columns are affected by the rulings above. Migration freeze checks must verify each entry against this list.

### MySQL — WordPress database

| Table | Change | Driven by |
|---|---|---|
| `wp_hsp_outbox` | Column-level DDL frozen in v1.3 — see OPEN-6 Amendment (v1.3). Columns: `id CHAR(36) PK` (event_id), `event_type VARCHAR(255)`, `event_version INT`, `aggregate_type VARCHAR(100)`, `aggregate_id VARCHAR(255)`, `aggregate_version BIGINT`, `source_updated_at DATETIME NOT NULL` (UTC), `checksum CHAR(64)`, `correlation_id CHAR(36)`, `causation_id CHAR(36) NULL`, `payload JSON`, `status ENUM('pending','relayed')`, `created_at DATETIME NOT NULL` (UTC, capture time), `relayed_at DATETIME NULL`. Index on `(status, created_at)`. All `DATETIME` columns are UTC (v1.2 canon). | OPEN-6, OPEN-3 (v1.2), OPEN-6 (v1.3) |
| `wp_hsp_aggregate_counters` | New table: PK `(aggregate_type VARCHAR(100), aggregate_id VARCHAR(255))`, `version BIGINT`; atomic increment via `INSERT … ON DUPLICATE KEY UPDATE`. No timestamp columns. | DECISION 2 (v1.1) |

> **Note (v1.1):** The v1.0 rows for `wp_postmeta` (`_hsp_version`) and `wp_termmeta` (`_hsp_version`) are removed. That storage is superseded by `wp_hsp_aggregate_counters` per DECISION 2 amendment.

> **Note (v1.2):** MySQL timestamp columns use `DATETIME`-UTC, not `TIMESTAMPTZ` (which is a PostgreSQL type). A freeze-check finding of `TIMESTAMPTZ` in a MySQL migration is a violation; `DATETIME` is correct.

### PostgreSQL — system schema

| Table | Change | Driven by |
|---|---|---|
| `system.events` | New columns: `aggregate_version BIGINT`, `source_updated_at TIMESTAMPTZ`, `checksum VARCHAR(64)`, `correlation_id UUID`, `causation_id UUID` | OPEN-5 (v1.1) |
| `system.events` | Event `type` column must accept fully-qualified `<domain>.<aggregate>.<action>` values | OPEN-1 |
| `system.queue_jobs` | New columns: `worker_id UUID`, `visibility_timeout_at TIMESTAMPTZ` | OPEN-4 (v1.1) |
| `system.dead_letter_jobs` | New columns: `stack_trace TEXT`, `attempt_count INTEGER`, `worker_id UUID`, `payload_snapshot JSONB NOT NULL` (NOT NULL per DECISION A v1.4; Doc 3 `payload` superseded) | OPEN-3 (v1.1), DECISION A (v1.4) |
| `system.aggregate_versions` | New table: PK `(aggregate_type, aggregate_id)`, `latest_processed_version BIGINT`, `latest_processed_at TIMESTAMPTZ` | OPEN-2 |
| `system.processed_events` | New table: PK `event_id`, `checksum VARCHAR(64)`, `processed_at TIMESTAMPTZ` | OPEN-7 (v1.1), DECISION 3 |
| `system.schema_versions` | Frozen DDL: `id UUID PK`, `migration_name VARCHAR(255) NOT NULL`, `schema_context VARCHAR(100) NOT NULL` (engine-qualified values: `'core/mysql'`, `'core/pgsql'`, `'content/pgsql'`, etc.), `applied_at TIMESTAMPTZ NOT NULL`, `rolled_back_at TIMESTAMPTZ NULL`, `checksum VARCHAR(64) NOT NULL`, `UNIQUE(migration_name, schema_context)` | OPEN-8 (v1.4) |
| `system.module_versions` | Frozen DDL: `id UUID PK`, `module_name VARCHAR(100) NOT NULL`, `schema_version VARCHAR(50) NOT NULL`, `applied_at TIMESTAMPTZ NOT NULL`, `notes TEXT NULL`, `UNIQUE(module_name, schema_version)`, `INDEX(module_name, applied_at DESC)` | OPEN-8 (v1.4) |
| `system.security_events` | Frozen DDL: `id UUID PK`, `event_type VARCHAR(100) NOT NULL` (`security.<aggregate>.<action>`), `severity VARCHAR(20) NOT NULL`, `actor_type VARCHAR(50) NULL`, `actor_id VARCHAR(255) NULL`, `ip_address VARCHAR(45) NULL`, `metadata JSONB NOT NULL`, `created_at TIMESTAMPTZ NOT NULL`, `INDEX(event_type, created_at)` | OPEN-8 (v1.4) |

> **Note (v1.2):** Module-owned `content.*` tables (`content.pages`, `content.posts`, `content.taxonomies`, `content.media`, and any future module projection tables) are not listed here because they are generated in Phase 1A, not Phase 0. However, they **must** follow the v1.2 type canon: `TIMESTAMPTZ` for all timestamp columns, `VARCHAR(64)` for all checksum columns. Their freeze check occurs at the Phase 1A DoD gate. Doc 3 §9–11, which show bare `TIMESTAMP` for these tables, is superseded by OPEN-3 (v1.2).

> **Note (v1.8 — P1A-S4 delivery):** `content.pages`, `content.posts`, `content.taxonomies`, and `content.entity_taxonomies` migrations were delivered in P1A-S4. All timestamp columns use `TIMESTAMPTZ`; all checksum columns use `VARCHAR(64)`. `content.entity_taxonomies` is a pure join table — (entity_id UUID, taxonomy_id UUID) composite PK only (FLAG-P1AS4-1). The freeze check for all `content.*` tables occurs at the Phase 1A DoD gate (end-to-end validation in P1A-S6) per the v1.2 rule. `content.media` remains OUT of MVP scope (Phase 1B).

> **Note (v1.10 — content.* soft-delete column):** The tombstone path (DECISION I) writes the `deleted_at TIMESTAMPTZ NULL` column that already exists on `content.pages`, `content.posts`, and `content.taxonomies` from the P1A-S4 migrations. DECISION F's default listing filter (`WHERE status = 'publish' AND deleted_at IS NULL`) depends on this same column. No new migration is owed by P1A-S6b — the column is already present.

> **Migration freeze rule:** no schema migration that touches any table or column in the tables above may be merged unless it is consistent with the ruling in the referenced OPEN / DECISION item, or this document is formally amended with a new versioned entry.

### PHP Contracts and Infrastructure

> **This table records non-schema implications: interface changes, class-level dependencies, and wiring obligations introduced by rulings. These are as binding as schema implications.**

| Component | Change | Driven by |
|---|---|---|
| `core/Contracts/AdapterInterface` | Gains method `tombstone(string $aggregateType, string $aggregateId, EventInterface $event): void`. All existing adapter implementations (PageAdapter, PostAdapter, CategoryAdapter) must implement it. The tombstone performs a soft-delete (`deleted_at = now()`) inside a single-PG transaction covering all three DECISION 3 ops. If the target row does not exist, the projection write is a no-op but `system.processed_events` and `system.aggregate_versions` are still updated. | DECISION I (v1.10) |
| `core/Workers/Strategies/EventWorkerStrategy` | Gains a PostgreSQL read dependency for the Resolve-stage aggregate-version lookup (`system.aggregate_versions`). Must be injected via constructor (ADR-012); no service-locator call permitted. Resolves the `system.aggregate_versions` row using a non-locking SELECT before handler invocation. | DECISION J (v1.10) |
| `core/Container/Definitions/WorkerServiceProvider` | Must wire the aggregate-version read dependency into `EventWorkerStrategy` via constructor injection. | DECISION J (v1.10) |
| `core/Container/Definitions/DeliveryServiceProvider` | New service provider. Binds `DatabaseConnectionInterface::class` as a singleton opened with `PGSQL_CONNECT_FORCE_NEW`, wrapping `PostgresDatabaseConnection`. This is the exclusive binding for delivery reads (REST query providers), Resolve-stage reads (`EventWorkerStrategy`), and adapter persistence. Registered in `ContainerBuilder` before `WorkerServiceProvider` and `ContentServiceProvider`. | DECISION K (v1.11) |
| `core/Container/Definitions/QueueServiceProvider` | `DatabaseConnectionInterface::class` singleton binding removed. Queue provider binds only `'queue.connection.pgsql'` (its own FORCE_NEW handle) and `QueueProviderInterface`. | DECISION K (v1.11) |
| `core/Events/Dispatcher/` | New directory. `DispatcherWorkerStrategy` (implements `WorkerStrategyInterface`), `EventDispatcher` (reads `system.events` anti-join, calls `DatabaseQueueProvider::enqueueIdempotent()`), `DispatchBatch` (value object: event rows selected in one tick). | DECISION L (v1.12) |
| `core/Queue/Providers/Database/DatabaseQueueProvider` | Gains `enqueueIdempotent(EventInterface $event, string $queueName): void` — executes `INSERT … ON CONFLICT(event_id) DO NOTHING`. Does NOT replace or alter `enqueue()`. | DECISION L (v1.12) |
| `database/Core/pgsql/0011_add_unique_event_id_to_queue_jobs.sql` | New forward migration: `ALTER TABLE system.queue_jobs ADD CONSTRAINT uq_queue_jobs_event_id UNIQUE (event_id)`. Must not edit frozen migration 0003. | DECISION L (v1.12) |
| `core/Container/Definitions/DispatcherServiceProvider` | New service provider. Binds `'dispatcher.connection.pgsql'` (FORCE_NEW `PostgresDatabaseConnection`), `'dispatcher.strategy'` → `DispatcherWorkerStrategy`, `'dispatcher.engine'` → `WorkerEngine`. The dispatcher connection is physically distinct from the delivery handle (DECISION K) and relay/queue handles. Registered in `ContainerBuilder` after `QueueServiceProvider`. | DECISION L (v1.12) |
