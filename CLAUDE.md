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
PHPUnit). Exact pinned versions live in `composer.json`, `.php-version`, and CI config — not
here. PHP and PHPUnit versions must be a compatible pair. Do not invent or assume version
numbers; read them from those files when present.

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
│                         # security, observability
├── modules/              # Business domains (Content, WooCommerce, …); each
│                         # module is self-contained with its own events,
│                         # transformers, canonical models, migrations, tests
├── database/             # Core infrastructure migrations (outbox, queue, audit…)
├── resources/            # Static assets / templates
├── storage/              # Runtime storage (logs, cache)
├── tests/                # Unit / Integration / Contract / Module / E2E
├── tools/                # Developer tooling
├── docs/                 # Architecture & design documents
└── vendor/               # Composer dependencies
```

Namespace root: `HSP\` — mirrors folder structure (`HSP\Core\`, `HSP\Modules\Content\`, …).

---

## Build / Test / Run / Lint Commands

> No `composer.json` exists yet. Commands below are TBD — confirm before use.

| Task | Command |
|---|---|
| Install dependencies | `composer install` |
| Run all tests | TBD — confirm |
| Run unit tests | TBD — confirm |
| Run a single test | TBD — confirm |
| Lint / static analysis | TBD — confirm |
| Run worker (production) | WP-CLI command — TBD — confirm |

Workers run under **systemd / Supervisor / container runtime** in production.
WP-Cron is a fallback only (recovery jobs, safety checks) — never the primary execution path.

---

## Coding Standard

TBD — PSR-12 vs WPCS not yet decided. Do not assume either. Confirm before writing or
enforcing style rules.

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
  `system.events` in PostgreSQL is the *relayed copy*, not the capture point (OPEN-6).
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
  `UUID` (UUIDv7 self-assigned at worker startup).
- **Worker state:** workers reload current WordPress state on each event (state sync, not event
  sourcing). Workers are stateless (ADR-044).
- **Write-suppress logic:** compare a freshly-computed *projection* checksum against the stored
  checksum in the target store. Never compare against the event's own checksum — that is for
  traceability only (DECISION 3).
- **Atomicity:** projection upsert + `system.processed_events` insert +
  `system.aggregate_versions` upsert **must** commit in one PostgreSQL transaction (DECISION 3).
- **WordPress wins reconciliation** (ADR-045). Never repair WordPress from PostgreSQL state.

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

---

## Notes on What Belongs Elsewhere

Deployment runbooks, per-environment configuration, WP-CLI worker launch commands, one-off
migration procedures, and fast-changing operational details belong in skills, path-scoped
rules, or hooks — not here.

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
7. Leave the working tree clean and reviewable. Do NOT begin the next session's work.
