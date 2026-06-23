# HSP Architecture Decisions — Authoritative Conflict-Resolution Record

**Precedence: when this document conflicts with the PRD or Docs 1–11, THIS document wins. These resolutions are Accepted and frozen. Do not re-open or re-derive them.**

Version: 1.8  
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

---

## Table of Contents

1. [Open Items (OPEN-1 through OPEN-11)](#open-items)
2. [Decisions (DECISION 1 through DECISION 3, DECISION A, DECISION D, DECISION E)](#decisions)
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

> **Migration freeze rule:** no schema migration that touches any table or column in the tables above may be merged unless it is consistent with the ruling in the referenced OPEN / DECISION item, or this document is formally amended with a new versioned entry.
