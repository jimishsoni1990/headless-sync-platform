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
- **Phase 2 — multi-module platform + WooCommerce Catalog (DECISION AG):** Phase 2's success test is
  that **WooCommerce becomes the second independent domain module without special-casing Commerce in
  Core**. **Core must not import or hardcode a concrete module** — `ModuleInterface::getServiceProvider()`
  is the real composition seam and adding a module adds **no** line to `core/` (AG-1). Replay
  emitters, reconciliation sources and **projection descriptors** are **Core-owned registries keyed by
  aggregate type**, registered by modules, with duplicate registration throwing and **no silent skip**
  of an uncovered aggregate; **Core — not a module — constructs `ReplayService`/`ReconciliationService`**
  (AG-2, AG-3). Queue routing resolves `content.*→content`, `commerce.*→commerce`, `system.*→system`
  through **one** explicit seam, and **`processing.projection_batch_size` stays the TOTAL cycle budget
  shared fairly across active partitions — never multiplied per domain** (AG-4). Delivery filters use a
  domain-neutral `QueryFilterInterface` with module-owned typed DTOs; **no untyped array bag, no filter
  registry** (AG-5). **Module lifecycle is DISCOVERED → AVAILABLE → READY(ACTIVE)**, with data bootstrap
  tracked separately: availability is **not** readiness, migrations must have applied first, and a newly
  ready module bootstraps by **module-scoped `ReconciliationService` re-emission** — so **WooCommerce
  installed after HSP converges its existing catalog with no reactivation, no manual migrate and no
  manual reconcile** (AG-12; ADR-054 Principle 8). Bootstrap state is a module-keyed **WordPress
  option** — never a PostgreSQL table, and a sibling module's state must never be erased.
  **Commerce data model:** **no cross-aggregate foreign keys** between independently projected
  aggregates — a child may legitimately arrive before its parent and an FK would turn valid
  out-of-order sync into a DLQ failure, so Doc 3 §18 is superseded and soft references are used
  (AG-7). **`commerce.inventory` owns stock** — no `stock_status` on the product projection — and
  inventory is **aggregate-aware** so a product *or* a variation owns a row; **a missing inventory row
  means state unknown, NEVER out of stock**, and must never drop a valid row from a listing (AG-8,
  AG-14). Product categories and `pa_*` attribute terms share **one** `commerce.taxonomies` +
  `commerce.entity_taxonomies` discriminated by `taxonomy_type`, while **`commerce.attributes` stays
  separate because global attribute definitions are not terms** (AG-9, extending DECISION AA);
  `(taxonomy_type, slug)` is **not** a term's durable identity and gets **no UNIQUE constraint**
  without source verification, and ambiguous hierarchies follow **DECISION AD/AE/AF** — full ancestor
  path at read time, no stored path column, no second hierarchy architecture. **`content.media` stays
  the single attachment projection**: no duplicate Commerce copy, no `Commerce → Content` PHP import,
  no hidden `Commerce SQL → content.media` dependency, and **Commerce product sync must still succeed
  when the media capability is absent** (AG-10). Money is `NUMERIC` with **no imposed precision/scale**,
  normalized to a deterministic decimal **string** before checksum construction; **currency is
  store-level, not a per-product column** (Requirements C / C′). Phase 2 supports **`simple` +
  `variable` products only**; other types are **normal out-of-scope source, not processing failures** —
  never coerced, never DLQ'd for being unsupported, excluded from expected counts, and **tombstoned via
  DECISION I/T/U when a supported product leaves scope** (AG-13). See
  `docs/ARCHITECTURE_DECISIONS.md` DECISION AG.

---

## MVP Scope (Blog only) — **MVP is COMPLETE; Phase 2 is authorised**

> **Superseded in part by DECISION AG (2026-09-07).** Phase 1A, the Early Operational Baseline, the
> Architecture Validation Gate (GATE-S1…S4) and Phase 1B are all shipped, so the MVP boundary below is
> **history, not a live prohibition**. **WooCommerce is now IN SCOPE** as Phase 2 — WooCommerce
> Catalog (Doc 11 §11, ratified by DECISION AG, expanded into IMPLEMENTATION_PLAN.md §5b rows
> P2-S1…P2-S7). Everything else in the out-of-scope list below still holds.

In scope: Posts, Pages, Categories + the full platform pipeline (outbox, queue, worker,
transformer, PostgreSQL projection, REST Delivery API).

Out of scope for MVP (do not introduce):

- ~~WooCommerce~~ (**now Phase 2 — see DECISION AG**), Membership, LMS, Directory, Booking
- GraphQL, OpenSearch
- Redis as a hard requirement (optional only)
- Multi-site / multi-tenancy

**Phase 2 scope boundary (DECISION AG Part 6).** In: Products, Product Variations, Product
Categories, Attributes, Attribute Terms, Inventory (`simple` + `variable` product types only —
AG-13), the required Commerce filtering and Category queries, and the infrastructure changes
strictly necessary to prove the second module. Out: Orders, Customers, Cart, Checkout, Payments,
Shipping workflows, Coupons, `product_tag`, and local/custom (non-global) product attributes. **The
WooCommerce cart stays WordPress-owned runtime functionality and is never projected into
PostgreSQL.**

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
9. Deploy to the local site — source tree only, no dev dependencies (`vendor/` is 100% `require-dev`
   and `node_modules/` is build-time only; neither belongs in a WordPress plugins directory):
   ```
   set DEST=C:\Users\jimis\Local Sites\headless-sync-platform\app\public\wp-content\plugins\headless-sync
   robocopy "J:\HSP\headless-sync" "%DEST%" /MIR /XD vendor node_modules tests tools storage
   robocopy "J:\HSP\headless-sync\vendor" "%DEST%\vendor" autoload.php
   robocopy "J:\HSP\headless-sync\vendor\composer" "%DEST%\vendor\composer" /MIR
   <php.exe> <composer.phar> dump-autoload --no-dev --optimize -d "%DEST%"
   ```
   Only the Composer autoloader ships from `vendor/`. The final `dump-autoload --no-dev` is
   REQUIRED, not optional: the dev autoloader has a files-autoload entry for `myclabs/deep-copy`
   and fatals the plugin once the dev packages are gone. It runs against `%DEST%`, so the source
   tree keeps its dev autoloader for PHPUnit. `/MIR` prunes files deleted since the last deploy;
   `/XD storage` preserves runtime logs on the site. See [[reference-tools]] for the PHP path —
   `php` is not on PATH, so bare `composer` fails.
