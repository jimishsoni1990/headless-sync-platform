# CLAUDE.md — Headless Sync Platform (HSP)

## Project Overview

HSP is a WordPress plugin that turns WordPress (MySQL) into a headless CMS via an event-driven
pipeline: WordPress hooks write to the `wp_hsp_outbox` table → a RelayWorker copies rows to
PostgreSQL `system.events` → workers transform and project content into PostgreSQL delivery
tables → a versioned REST Delivery API serves consumers. Consumers depend on the API contract
only; they never touch WordPress or PostgreSQL schemas directly.

---

## Tech Stack & Versions

HSP targets the **latest stable release** of each dependency (PHP, WordPress, PostgreSQL,
PHPUnit). Exact pinned versions live in `composer.json`, the plugin header, and CI config — not
here. PHP and PHPUnit versions must be a compatible pair. Do not invent or assume version
numbers; read them from those files. Platform minimum PHP is the plugin header "Requires PHP".

---

## Folder Structure

```
headless-sync/
├── headless-sync.php     # Plugin entry point
├── composer.json / .lock
├── bootstrap/            # Startup sequence, env loading, container init
├── config/               # Global platform config (no business logic)
├── core/                 # Infrastructure only — contracts, DI container, events,
│                         # queue, workers, delivery adapters, reconciliation,
│                         # security, observability, operations console
│   ├── Contracts/         #   incl. Contracts/Operations/ (operations-console
│   │                      #   contracts — modules depend here only, Rule 5; DECISION V)
│   ├── Operations/        #   Operations Console infrastructure (registries,
│   │                      #   providers, services, diagnostics). Shipped MVP UI is
│   │                      #   server-rendered PHP (DECISION V); new admin UI is
│   │                      #   React+shadcn (DECISION W amends V (a))
│   ├── Workers/           #   WP-Cron Processing Engine — bounded stateless cycle,
│   │                      #   strategies, heartbeat publisher, cron registrar (ADR-054)
│   └── Onboarding/         #   First-run/onboarding: preflight, nav gating,
│                          #   backfill trigger (thin delegator to
│                          #   ReconciliationService), self-remediation endpoints,
│                          #   derived progress; React UI (DECISION W) — NOT under
│                          #   Operations/ (V (j))
├── modules/              # Business domains (Content, WooCommerce, …); each
│                         # module is self-contained with its own events,
│                         # transformers, canonical models, migrations, tests
├── database/             # Core infrastructure migrations (outbox, queue, audit…)
├── resources/            # Static assets / templates; React admin UI toolchain +
│                         # committed dist/ build output (DECISION W (a) — build in
│                         # dev/CI, deploy = file copy, no host build step)
├── storage/              # Runtime storage (logs, cache)
├── tests/                # Unit / Integration
├── tools/                # Developer tooling
├── docs/                 # Architecture & design documents
└── vendor/               # Composer dependencies
```

Namespace root: `HSP\` — mirrors folder structure (`HSP\Core\`, `HSP\Modules\Content\`, …).

---

## Build / Test / Run / Lint Commands

Backend commands run from `headless-sync/`. The **frontend (React admin UI) toolchain** lives in
`resources/admin-ui/` (npm), builds in dev/CI, and commits `dist/`; the production deploy is a
file copy (no host build step — DECISION W (a)).

| Task                        | Command                                                             |
| --------------------------- | ------------------------------------------------------------------ |
| Install PHP dependencies    | `composer install`                                                 |
| Run all tests               | `vendor/bin/phpunit`                                               |
| Run unit tests              | `vendor/bin/phpunit --testsuite Unit`                             |
| Run integration tests       | `vendor/bin/phpunit --testsuite Integration`                      |
| Run a single test           | `vendor/bin/phpunit --filter <TestName>` (or a path)              |
| Static analysis (PHPStan)   | `composer analyse` (PHPStan level 8, baseline-green; see `phpstan-baseline.neon`) |
| Lint (PHPCS)                | `composer lint` (PSR-12 all code + WPCS security sniffs at WP entry points; baseline-green, see `phpcs-baseline.xml`) |
| Auto-fix lint (PHPCBF)      | `composer lint:fix` (PSR-12 formatting **only** — never auto-fixes WPCS security sniffs) |
| Install admin-UI deps       | `cd resources/admin-ui && npm install` (DECISION W (a))           |
| Build admin UI → `dist/`    | `cd resources/admin-ui && npm run build` (DECISION W (a))         |
| Watch-build admin UI (dev)  | `cd resources/admin-ui && npm run dev` (DECISION W (a))           |

**Integration tests require live databases** and the `pgsql` PHP extension; they self-skip when
absent. Provide PostgreSQL via `HSP_TEST_PGSQL_{HOST,PORT,USER,PASSWORD,DATABASE}` and MySQL via
`HSP_TEST_MYSQL_{HOST,PORT,USER,PASSWORD,DATABASE}`. Unit tests need neither (WordPress + `$wpdb`
are stubbed in `tests/bootstrap.php`).

The admin-UI build outputs stable, non-hashed filenames
(`resources/admin-ui/dist/hsp-onboarding.{js,css}`) so the PHP registrar enqueues deterministic
paths. Commit `dist/`; never rely on a build step running on the WordPress host (DECISION W (a)).

**Execution model (ADR-054):** background processing runs **only** as bounded, stateless cycles
on the `hsp_processing_cycle` WP-Cron event — one cycle per invocation (relay → dispatch →
project → maintenance), exiting before `max_execution_time`. WP-Cron is the **only** v1.x
execution mechanism; there are **no** systemd / Supervisor / container / CLI-daemon workers. The
platform operates immediately after activation with **zero configuration** (Principle 8). System
cron may invoke `wp cron event run --due-now` as a reliable *trigger*; each invocation still runs
one bounded cycle and exits. `system.worker_heartbeats` records **processing-cycle freshness**
(not daemon liveness).

---

## Coding Standard

Settled by **DECISION V**:

- **PSR-12 for all platform code.**
- **WPCS security requirements — output escaping, input sanitization, capability checks,
  nonces — apply at WordPress entry points only** (admin pages, form/action handlers, REST
  registration, `$wpdb` calls), and — per **DECISION W (a)** — at the **REST/ajax endpoints the
  React admin UI calls** (the untrusted-client JSON boundary): sanitize input, check capability,
  verify nonce at every such endpoint.

**Admin UI stack (DECISION W (a) — amends DECISION V (a)):** **React + shadcn** is the admin UI
stack. The build toolchain runs in **dev/CI only**; the compiled `dist/` bundle is **committed**
and the production deploy is a **file copy** (no node/npm build step on the WordPress host). The
already-shipped OPSC server-rendered PHP Operations Console remains as built; only **new** admin
UI (including onboarding) is React. See `docs/ARCHITECTURE_DECISIONS.md` DECISIONS V and W.

---

## Architectural Rules (enforce in every session)

1. **WordPress is source of truth.** All content originates there; reconciliation always repairs
   the delivery side to match WordPress, never the reverse.
2. **Transform before persist.** PostgreSQL projections are optimised delivery stores, not WP
   table replicas.
3. **Event-driven via outbox.** Every sync goes through `wp_hsp_outbox` → relay → `system.events`.
   Bypassing the outbox is prohibited.
4. **At-least-once + idempotent.** Workers must handle redelivery safely.
5. **Module isolation.** Modules own domain logic, `core/` owns contracts and infrastructure.
   Module-to-module imports are prohibited. Modules depend on `core/Contracts/` only.
6. **Consumers depend on API contracts only.** No synchronous WordPress reads on the consumer
   path; no coupling to WP or PG internal schemas.
7. **Constructor injection only.** Service-locator calls (`Container::get(…)`, `global $container`)
   inside business logic are prohibited (ADR-012).
8. **Never attempt a cross-DB transaction** (MySQL ↔ PostgreSQL).

---

## SETTLED — DO NOT RE-OPEN

> See `docs/ARCHITECTURE_DECISIONS.md` for full rationale. These are Accepted and frozen.

- **Outbox table:** `wp_hsp_outbox` lives in WordPress MySQL and is the capture point.
  `system.events` in PostgreSQL is the _relayed copy_, not the capture point (OPEN-6).
- **Capture model:** near-atomic post-commit write to `wp_hsp_outbox` + reconciliation backstop
  (DECISION 1 / ADR-029 revised). A true cross-DB atomic write is impossible; do not attempt it.
- **Event naming:** fully-qualified `<domain>.<aggregate>.<action>`
  (e.g. `content.post.updated`). Bare names are superseded (OPEN-1).
- **`aggregate_version`:** per-aggregate monotonic counter stored in a **dedicated MySQL table**
  `wp_hsp_aggregate_counters` (PK: `aggregate_type, aggregate_id`). Postmeta/termmeta storage
  is superseded — those tables have no unique key on `(object_id, meta_key)` and a bare UPDATE
  on a missing row affects zero rows, reintroducing the duplicate-version race. Atomic increment
  in one round-trip (DECISION 2 v1.1):
    ```sql
    INSERT INTO wp_hsp_aggregate_counters (aggregate_type, aggregate_id, version)
    VALUES (?, ?, 1)
    ON DUPLICATE KEY UPDATE version = LAST_INSERT_ID(version + 1);
    -- then: SELECT LAST_INSERT_ID();
    ```
- **Column-type canon** (supersedes Doc 3 types — OPEN-3/4/5/7 v1.1): all timestamps are
  `TIMESTAMPTZ`; all checksums are `VARCHAR(64)` (sha256); all worker-identity columns are
  `UUID`. `worker_id` is a **fresh UUIDv7 minted per processing cycle** — a per-cycle
  processing-component identity, not a long-lived daemon id (ADR-054 §8 / DECISION X (1)).
- **Worker state:** each cycle reloads current WordPress state per event (state sync, not event
  sourcing) and holds no state between cron executions (ADR-044 / ADR-054 §2).
- **Write-suppress logic:** compare a freshly-computed _projection_ checksum against the stored
  checksum in the target store. Never compare against the event's own checksum — that is for
  traceability only (DECISION 3).
- **Atomicity:** projection upsert + `system.processed_events` insert +
  `system.aggregate_versions` upsert **must** commit in one PostgreSQL transaction (DECISION 3).
- **WordPress wins reconciliation** (ADR-045). Never repair WordPress from PostgreSQL state.
- **Runtime PG connection layer (DECISION E):** runtime DML subsystems (relay, queue, worker)
  share one PostgreSQL `DatabaseConnectionInterface`; the migration engine keeps its own
  DDL-only abstraction. The connection topology is frozen at four handles — **no fifth handle
  and no new raw `pg_*` wrapper** may be introduced (DECISION L Ruling 0).
- **Plugin lifecycle migrations (OPEN-9 / DECISION W (f)):** `Application::activate()` /
  `upgrade()` (and module `activate()`/`upgrade()` hooks) attempt pending migrations through the
  shared migration engine **iff `HSP_PG_*` are defined AND PostgreSQL is reachable**, and are a
  **silent no-op otherwise** — activation must never fatal on an unconfigured site.
- **Operations Console (DECISION V):** the console is **observability/diagnostics only, not a
  control plane** — restarting services/containers and infrastructure orchestration are
  permanently out of scope. Shipped MVP UI is **server-rendered PHP + minimal vanilla JS** (the
  new-admin-UI stack is superseded by DECISION W (a) — React+shadcn; the shipped console stays as
  built). Provider PG reads **reuse the delivery `DatabaseConnectionInterface`** (no fifth handle).
  Operations contracts live in `core/Contracts/Operations/` (Rule 5 holds). Console metrics are
  **derived on-demand** (DECISION Q — zero new persistence). Actions are **Replay + Reconcile
  only**, thin delegators to the ratified services (no second repair path). **No Flush Queue**
  (destructive) and **no Restart Workers** (nothing to restart under ADR-054).
- **Onboarding & First-Run (DECISION W):** admin UI stack is **React + shadcn** (amends V (a));
  build in dev/CI, **commit `dist/`**, deploy = file copy. Initial content backfill =
  **full-reconciliation re-emission via `ReconciliationService`** (DECISION U) through the normal
  pipeline — **no direct WP→PG copy, no second repair path** (write-spy proof in the DoD).
  Progress is **derived on-demand** (DECISION Q); completion = a single WP option
  `hsp_onboarding_state` in MySQL (**no schema change**). Onboarding lives in `core/Onboarding/`
  (NOT the console — V (j) holds). Until complete, **Operations + Playground pages are hidden**.
  **Four environment preflight checks hard-block progression** (ONB-S1b): `pgsql` extension
  loaded, `HSP_PG_*` constants defined, PostgreSQL reachable, PHP ≥ platform minimum. The
  **migrations-applied check is an ONB-S2 backfill gate** (not preflight). Both ONB-S2 backfill
  gates — **migrations applied** and **processing pipeline advancing** (scheduled
  `hsp_processing_cycle` cron **and** a recent heartbeat, DECISION X (4)) — are **hard blocks
  with in-product self-remediation** (W (f) v1.23): `POST hsp/v1/onboarding/migrate` (thin
  delegator to the migration engine) and `POST hsp/v1/onboarding/spawn-worker` (non-blocking
  `spawn_cron()`; no in-request drain — action, not bypass; each gate still blocks until it
  genuinely passes). See `docs/ARCHITECTURE_DECISIONS.md` DECISIONS W and X.

---

## MVP Scope (Blog only)

In scope: Posts, Pages, Categories + the full platform pipeline (outbox, queue, worker,
transformer, PostgreSQL projection, REST Delivery API).

Out of scope for MVP (do not introduce):

- WooCommerce, Membership, LMS, Directory, Booking
- GraphQL, OpenSearch
- Redis as a hard requirement (optional only)
- Multi-site / multi-tenancy

---

## Anti-Patterns — Never Do These

- Replicate raw WordPress tables into PostgreSQL.
- Couple consumers to WordPress or PostgreSQL internal schemas, or to canonical models.
- Read WordPress synchronously on the consumer request path.
- Silently drop a failed sync (failed events go to DLQ; replays are always possible).
- Bypass the outbox.
- Import one module from another module.
- Introduce a supervised daemon / CLI-worker / systemd / Supervisor / container-restart execution
  path — WP-Cron is the only v1.x mechanism (ADR-054).

---

## Notes on What Belongs Elsewhere

Deployment runbooks, per-environment configuration, WP-Cron cadence tuning, one-off migration
procedures, and fast-changing operational details belong in skills, path-scoped rules, or hooks —
not here.

---

## Session Close — run at the end of every session

Before ending a session:

1. Verify the session's Definition of Done is actually met (tests/checks green). If not
   met, the session is NOT done — do not mark it complete or advance the pointer.
2. Confirm only in-scope files changed. Anything out of scope: revert it or flag it.
3. If migrations or contracts were touched, verify consistency with
   `docs/ARCHITECTURE_DECISIONS.md` (Implications table + the cited OPEN/DECISION). A
   migration that diverges from a frozen ruling may not be left in the tree.
4. Update `STATUS.md`: flip completed items to done, set "Last updated", set "Next session"
   to the next session ID from the IMPLEMENTATION_PLAN.md Session Map.
5. Surface every new flag, unresolved question, or place a ruling is needed. NEVER silently
   resolve a conflict with a frozen doc — stop and flag it.
6. Append one dated line to the Session Log at the bottom of `STATUS.md`: session ID, what
   shipped, any flags raised.
7. Present the session summary for the approval to commit.
8. Once approved, commit, merge to main (fast-forward preferred), and push origin/main. A session is not closed until its commits are on origin/main. Leave the working tree clean and reviewable. Do NOT begin the next session's work.
9. robocopy "j:\HSP\headless-sync" TO "C:\Users\jimis\Local Sites\headless-sync-platform\app\public\wp-content\plugins\headless-sync"
