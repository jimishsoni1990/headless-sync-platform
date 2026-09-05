# HSP Architecture Decisions — Authoritative Conflict-Resolution Record

**Precedence: when this document conflicts with the PRD or Docs 1–11, THIS document wins. These resolutions are Accepted and frozen. Do not re-open or re-derive them.**

Version: 1.32  
Status: Accepted  
Owner: Architecture  

---

## Amendment Log

| Version | Date | Items changed |
|---|---|---|
| 1.32 | 2026-09-05 | **DECISION Y — PostgreSQL full-text search deferred from Phase 1B to Phase 5 (P1B-S0, docs-only; product decision 2026-09-05).** Phase 1B — Content Enhancement is **featured images, media synchronization, tags, basic ACF, pagination**; **search is NOT a Phase 1B deliverable** and lands in **Phase 5 — Search Expansion** (Doc 11 §14), which already states PostgreSQL Search remains supported. **Doc 11 → v1.2**: the "PostgreSQL Search" deliverable and the "Search Queries" validation item in §7 are retained under an explicit DECISION Y banner (superseded text is bannered, never deleted — DOC-RECON-S1 precedent). **§17 Search Roadmap ordering is unchanged** (PostgreSQL Search still precedes the provider contract and OpenSearch/Typesense — only the phase placement moved), and **Doc 9 §14/§15, Doc 3 §27 and the Doc 5/6/7 search-projection references are untouched** — all Phase 5+ material. No Phase 1B session may introduce a `tsvector` column, full-text index, or search endpoint. No schema change, no contract change, no code change. |
| 1.31 | 2026-09-05 | **DECISION Z — lazy PostgreSQL connections at the container boundary (LAZYPG-S1, interstitial before P1B-S0).** Resolves the ONB-S1b "lazy-connection ruling pre-Phase-1B" carry-forward. All four runtime PG handles (delivery, relay, queue-claim, dispatcher) previously called `pg_connect()` **inside their singleton factory**, so merely RESOLVING a binding threw a raw `\RuntimeException` when PostgreSQL was unreachable or unconfigured; because `rest_api_init` fires on **every REST request to the site** (`wp/v2` and the block editor included) and building the content registrar resolves the query providers, an unreachable PostgreSQL fatalled **every REST request**, not just `hsp/v1`. Each factory now hands a **connector `\Closure`** to its wrapper; `PostgresDatabaseConnection` accepts a handle **or** a connector, invokes it at most once on first real use, memoizes it, and translates connect failure to `DatabaseException` at that boundary (onward translation to `OutboxWriteException`/`QueueException` unchanged — DECISION E v1.6); `rollback()` on a never-opened connection is a no-op. Mirrors the existing `MysqliOutboxConnection` connector hotfix on the capture path. **Still four handles with their existing flags** — FORCE_NEW on delivery/queue/dispatcher, none on relay: DECISION K isolation and DECISION L Ruling 0 topology untouched. No fifth handle, no new `pg_*` wrapper, no persistence, no schema change, no contract change. DECISION **Y** is reserved for the P1B-S0 Phase-1B search deferral. |
| 1.30 | 2026-09-05 | **ADR-054 sibling-document reconciliation applied (DOC-RECON-S1, docs-only) — closes FLAG-DOC8V2-1. No new ruling, no ADR re-opened.** The already-ratified ADR-054 wording was propagated into the sibling frozen docs that still asserted the superseded daemon/CLI-worker execution model: **Doc 4 → v1.1** (§19 heartbeat = cycle freshness; §20 ADR-024 status → **SUPERSEDED by ADR-054**, original text retained verbatim as history; §29 scaling = overlapping cycles; §30 checklist), **Doc 10 → v1.1** (§4/§5 topologies, §7 rewritten WP-Cron-only, §20 `uptime` removed, §23 "Worker Offline" → "Processing Stalled", §24 availability target → processing freshness, §26 runbook rename, §27 systemd/Supervisor/worker-launch assets removed, §28 shared hosting without CLI/process supervision promoted to first-class supported), **Doc 11 → v1.1** (Doc-8 title, Scalability "Multiple Worker Processes" → concurrent claimants, "Restart Workers" note). CLAUDE.md was already clean (2026-07-20 rewrite). Superseded text is retained under explicit banners, never deleted; ADR-054 remains the single authority. See the APPLIED note under the ADR-054 Conflict Report. |
| 1.29 | 2026-07-20 | **ADR-055 (f)(2) meta-schema gate moved to the Node ajv toolchain — architect ruling "D" 2026-07-20 (OAPI-S1), verified against live reproductions.** `opis/json-schema` **2.6.0 is REMOVED from `require-dev`**: it has two reproduced JSON Schema 2020-12 conformance defects that make it unable to validate real OpenAPI 3.1 documents against the official meta-schema — **(i)** it does not index the `$dynamicAnchor: "meta"`, so `$dynamicRef "#meta"` mis-resolves to the document root (every Schema Object slot is validated as if it were a whole OpenAPI document); **(ii)** `unevaluatedProperties` reports schema-declared property names that are **absent** from the instance. The OAI `schema-base` variant does **not** avoid `$dynamicRef` (it overrides the anchor — defect (i) persists), and **no conformant 2020-12 PHP validator exists**. The **(f)(2) gate therefore runs via ajv in the Node toolchain** (already a sanctioned dev/CI dependency — DECISION W (a)), differential-verified against a conformant reference implementation on valid and invalid documents. **Pinned fixture:** `tests/fixtures/openapi-3.1-meta-schema-pinned.json` — the official OAI 3.1 meta-schema (`$id …/2022-10-07`, source tag 3.1.1) with exactly **four semantics-preserving edits** (`$dynamicRef "#meta"` → `$ref "#/$defs/schema"`; equivalent because the fixture validates as its own root resource, so no outer dynamic scope can retarget the anchor). Pinned, **never fetched at test time**. **Gate mechanics:** `tools/openapi-validator/` (committed `package.json` + `package-lock.json`; `node_modules/` gitignored) runs `validate-openapi.mjs` (Ajv2020 + ajv-formats, `strict:false`) over the pinned fixture; the drift guard calls it via `proc_open`, layered over the PHP structural pre-check which stays the fast-fail. **Environment contract:** node available → gate runs; node missing AND `HSP_REQUIRE_NODE_GATE` unset → the meta-schema assertion is **SKIPPED** with a warning naming the env var; `HSP_REQUIRE_NODE_GATE=1` (CI) AND node missing → **FAIL, never skip**. Completeness / exemption / exclusion / non-circularity stay pure PHP. No schema change; no new PG handle / `pg_*` wrapper. |
| 1.28 | 2026-07-20 | **ADR-055 (f)(1) drift-guard enumeration scoped — resolves the OAPI-S1 drift-guard route-enumeration flag (architect ruling 2026-07-20, "A-modified", OAPI-S1).** The CI drift guard enumerates the **FULL live `hsp/v1` route index** (external ground truth — not the registry, preserving non-circularity), then applies exactly **ONE structural exemption**: routes under the **`hsp/v1/onboarding/` prefix** (authority: DECISION W (e) — the onboarding first-run **admin** surface is outside the published delivery contract; its registrar is gated pre-completion). **Every non-exempted `hsp/v1` route must carry a complete `EndpointDescriptor`** or CI fails. The exemption is a **single prefix frozen in this ADR** — adding any further exempt prefix requires an architect ruling; the guard **hardcodes this one prefix with an ADR-055 (f) citation comment**. Any **future authenticated `hsp/v1` route OUTSIDE the exempted prefix must carry a descriptor** (`auth = authenticated` → excluded from the served document per v1.27; asserted by the exclusion test). **Net today: 13 live `hsp/v1` routes − 6 onboarding = 7 guarded routes** (six content + `openapi.json`), matching the OAPI-S1 seven-route DoD. Adds a named non-circularity test: a fixture route registered on `hsp/v1` **outside** the exempted prefix **without** a descriptor **fails the guard**. No schema change; no new PG handle / `pg_*` wrapper. |
| 1.27 | 2026-07-20 | **ADR-055 (d) scoping ruled — resolves FLAG-OAPI-1 (architect 2026-07-20, OAPI-S0 closeout).** The served `GET /hsp/v1/openapi.json` document describes **PUBLIC endpoints only**; endpoints requiring authentication/capabilities (Doc 9 §22) are **EXCLUDED from the generated document**. Exclusion is driven by the endpoint metadata **auth field** (ADR-055 (c)), **not by route inspection** (registry-driven, consistent with ADR-055 (a)). The **generator endpoint stays public and stateless** — **no capability check inside request-time generation** (consistent with ADR-055 (e)). The OAPI-S1 drift guard (ADR-055 (f)) must additionally assert, positively, that **no non-public-metadata route appears in the generated document** (exclusion test) — reflected in the OAPI-S1 DoD. No schema change; no new PG handle / `pg_*` wrapper. |
| 1.26 | 2026-07-20 | **ADR-055 (OpenAPI Specification, Registry-Generated) — architect ruling 2026-07-20, interstitial OAPI-S1 (inserted BEFORE P1B-S0).** The OpenAPI **3.1** document for the delivery API is **GENERATED at request time from the endpoint metadata registry** (`EndpointProviderInterface` / Doc 12 §15) — **never hand-authored, never derived by reflection/scanning of WP REST routes** (explicit-registration idiom, OPSC-S1 registries; ADR-048/ADR-052). **Single source of truth = the endpoint registrations**: the spec auto-updates because it is derived from the current registrations each time it is served — add/edit/remove a registration and the spec follows with no separate edit. **Additive enrichment** of the endpoint metadata contract (`core/Contracts/Operations/EndpointDescriptor` + `EndpointProviderInterface`): params, request/response schema, auth requirement, cursor-pagination envelope (Doc 9 §13), deprecation status (Doc 9 §26), version, module owner — **core owns the contract** (`core/Contracts/`, Rule 5); **modules own their metadata** (Doc 9 §6). **Exposure:** `GET /hsp/v1/openapi.json` (versioned per Doc 9 §7 — the v1 spec describes v1). **Proposed public** per Doc 9 §22 (Pages/Posts are public) — **flagged for architect decision** on scoping the document to public endpoints only (**FLAG-OAPI-1**). **Generation is request-time + stateless:** NO persistence, NO PG read, NO new handle (DECISION L Ruling 0 untouched), NO `pg_*` wrapper (DECISION E), and **NO involvement of the ADR-054 cron cycle** — the generator never runs inside a processing cycle. **Drift guard (CI):** a test asserts every registered `hsp/v1` REST route has a complete metadata entry **AND** the generated document validates against the OpenAPI 3.1 meta-schema; a route without metadata **fails CI**. Amends Doc 12 §15 (OpenAPI Generation moved Future → in-scope). Inserts interstitial session **OAPI-S1** into IMPLEMENTATION_PLAN §5b **before P1B-S0**; no Phase 1B text altered. No schema change; no new PG handle / `pg_*` wrapper. |
| 1.25 | 2026-07-18 | **DECISION W (f) amended — self-remediating ONB-S2 backfill gates (architect ruling 2026-07-18, ONB-S2).** The two ONB-S2 backfill prerequisite gates (migrations applied; processing pipeline advancing) become **self-remediating in-product** so a **zero-configuration fresh install** completes onboarding with **no manual CLI/engine step** (ADR-054 **Principle 8**). Adds two WPCS-guarded endpoints: **`POST hsp/v1/onboarding/migrate`** — applies the outstanding core + content migrations through the **EXISTING** migration engine over the **DECISION W (e) delegate list** (core migrations + module `getMigrations()` — OPEN-9/Rule 5), a **thin delegator** (`core/Onboarding/MigrationApplier`) with no new engine/DDL/schema/`pg_*` wrapper/handle, gated on the four ONB-S1b environment preflight checks (409 until they pass), re-evaluating `MigrationsAppliedCheck` after; and **`POST hsp/v1/onboarding/spawn-worker`** — a **non-blocking** WP-Cron spawn (`core/Onboarding/WorkerCronSpawner`) so a processing cycle runs and a heartbeat appears, with **NO in-request drain (DECISION W (c) intact)** and a WP-Cron-only warning when `DISABLE_WP_CRON` is set (no supervisor/systemd/daemon/restart wording — ADR-054 §5). Plugin **`activate()`/`upgrade()` (OPEN-9)** attempt pending migrations through the same shared engine **IFF `HSP_PG_*` defined AND PG reachable**, silent no-op otherwise — **activation never fatals on an unconfigured site**. Each gate keeps its **hard block** (action, not bypass); no second repair path. No schema change; no new PG handle / `pg_*` wrapper. |
| 1.24 | 2026-07-17 | **DECISION X (ADR-054 Alignment Rulings — per-cycle identity, heartbeat status set, `WorkerInterface` contract shape, backfill prerequisite) — architect ruling 2026-07-17, resolves FLAG-ALIGN-1 (a)/(b)/(c) + FLAG-ALIGN-2.** Records four rulings enabling the ALIGN-S1/S2 implementation of ADR-054: **(1)** worker identity = **Option A** — each processing cycle mints a **fresh UUIDv7** `worker_id` at cycle bootstrap; `system.worker_heartbeats` rows now represent **processing-cycle executions**, not daemon identities; maintenance prunes stale rows under existing retention; this cardinality is what makes DECISION Q on-demand cycle metrics (cycles_completed / avg_cycle_duration) derivable — resolves FLAG-ALIGN-1 (a). **(2)** heartbeat `status` set = exactly `'running'`/`'idle'` in v1.x (`'processing'` → `'running'`; `'shutdown'` removed — cycles terminate normally) — resolves FLAG-ALIGN-1 (b). **(3)** `WorkerInterface` = **Option A (architectural correction)** — an internal core contract, not module-implemented; `run()`/`shutdown()` are **removed** and the contract expresses exactly **one bounded processing cycle**: execute one cycle honouring configured batch limits + execution-time budget, return a processing result describing the completed cycle — resolves FLAG-ALIGN-1 (c). **(4)** backfill prerequisite = **Option C** — a processing cron event scheduled **AND** a recent processing heartbeat; remediation references **only WP-Cron** (`wp cron event run --due-now` etc.), never supervisor/systemd/daemon/restart — resolves FLAG-ALIGN-2 (implemented in ALIGN-S2). Inserts ALIGN-S1 (Processing Engine cycle + trigger + contract + heartbeat) and a stub ALIGN-S2 (console/metrics reinterpretation + backfill gate + remaining docblocks) into IMPLEMENTATION_PLAN §5b. No schema change; no new PG handle / `pg_*` wrapper (ADR-054 §9 constraints hold). |
| 1.23 | 2026-07-17 | **ADR-054 (Background Processing via WP-Cron Processing Engine) — architect ruling 2026-07-17. Product ruling: HSP v1.x supports ONLY WP-Cron for background execution — no Supervisor, systemd, Docker workers, CLI daemons, or continuously running processes. The execution mechanism changes; nothing else does.** ADR-054 **supersedes the execution-model decision of ADR-024** ("CLI Workers" primary / WP-Cron fallback → **WP-Cron only for v1.x**) and **amends the wording of ADR-035 and ADR-036** where they assumed daemon workers (shared-engine + specialized-strategy model is retained; the *invocation* model changes from supervisor-launched daemon to a bounded cron-triggered cycle; "workers may be restarted/recycled" reframed as "cycles start fresh each cron tick"). ADR-024/035/036 history is **not deleted** — each is annotated below with its superseded/amended status. **Doc 8 rewritten to Version 2.0** ("Background Processing & Execution Architecture"): introduces the Processing Engine model (WP-Cron trigger → relay batch → dispatch batch → projection batch → persist metrics → clean exit; stateless between executions; per-stage max batch sizes + execution-time budget + continuation across cron runs). **Preserved unchanged:** the Outbox→Relay→Dispatcher→Queue→Processing pipeline, per-event execution pipeline (Claim→Load→Context→Validate→Resolve→Execute→Commit→Ack), execution context, subscribers/handlers, registry-based resolution, aggregate-version ordering (DECISION J), visibility timeout (OPEN-4/DECISION R), replay (DECISION T), reconciliation (DECISION U), correlation/causation IDs (ADR-037), failure isolation, stateless processing, at-least-once delivery, four-connection topology (DECISION L Ruling 0), heartbeat current-state (DECISION P), metrics/progress without persistence (DECISION Q). **Removed:** long-running workers, supervisors, systemd/Supervisor/container restart policies, worker recycling/startup/shutdown lifecycle, multi-process worker pools, daemon health monitoring, heartbeat-as-liveness (heartbeat is reinterpreted as cycle-freshness/progress). **Concurrency:** overlapping cron cycles are safe via **existing guarantees only** (FOR UPDATE SKIP LOCKED + aggregate versioning + visibility timeout) — **no new locking mechanism introduced.** Health = processing freshness/progress; metrics replace `worker_uptime`/`restart_count` with cycles-completed / avg-cycle-duration / per-stage-throughput / queue-backlog / processing-lag. Recovery = next cron execution + queue durability + visibility timeout + replay + reconciliation. **Implementation class names retained** (`WorkerEngine`, `RelayWorkerStrategy`, `EventWorkerStrategy`, `ReconciliationWorkerStrategy`, `MaintenanceWorkerStrategy`) — defined as processing components invoked by WP-Cron; no rename proposed. Inserts an interstitial architecture session (ARCH-DOC8-V2) into the Session Map. Conflict report (Docs 1/2/3/4/5/7/9/10/11) recorded in the ADR body — no other doc edited by this ruling. |
| 1.21 | 2026-07-16 | **DECISION W (Onboarding & First-Run Backfill) — architect ruling 2026-07-16.** Adopts the Onboarding / First-Run feature into the frozen record. Records six rulings: **(a) UI stack — DECISION V (a) is AMENDED (globally): React + shadcn is adopted as THE admin UI stack** for HSP; build-artifact policy = **commit `dist/` to the repo** (npm build runs in dev/CI only; production deploy is a file copy — matches the CLAUDE.md robocopy step; no node/npm build step on the WordPress host); WPCS security rules (escape/sanitize/capability/nonce) apply at the **REST/ajax endpoints the React app calls**. The already-shipped OPSC-S1..S4 server-rendered PHP Operations Console remains as built (not rewritten by this ruling); all **new** admin UI — including onboarding — is React+shadcn. DECISION V (a)'s "server-rendered PHP + no node toolchain, React deferred to a future ADR" is **superseded** by this decision (this IS that ADR-equivalent ruling); DECISION V (a)'s provider/registry architecture (registries, provider contracts, `OperationsService` seam, ADR-047/048/052/053) is **unchanged** — only the frontend rendering technology changes. **(b) Backfill mechanism** — initial content migration is **full-reconciliation re-emission via `ReconciliationService` (DECISION U)** through the normal outbox→relay→dispatch→worker pipeline; **NO direct WP→PG copy path, no second repair path** (mirrors DECISION V (d): thin delegator + write-spy proof in the implementation DoD). **(c) Queue drain** — a **live worker heartbeat is a HARD PREREQUISITE**: onboarding will not trigger backfill unless a fresh `system.worker_heartbeats` row exists (DECISION P age check); workers drain the pipeline as normal (no in-request tick drain). **(d) Progress** — derived on-demand per DECISION Q (expected-count scan vs processed/projection counts); **zero new PG persistence**. Completion state = a single WP option `hsp_onboarding_state` in MySQL; **no schema change**. **(e) Placement** — onboarding is a lifecycle/setup surface under **`core/Onboarding/`** (NOT `core/Operations/` — keeps DECISION V (j) console-is-observability-only intact); contracts (if any) under `core/Contracts/`; delegates to ratified services only. **(f) Nav gating** — until `hsp_onboarding_state = complete`, the Operations + API Playground admin pages are **not registered/visible**; the onboarding page is the only HSP admin surface. Prerequisite checks (pgsql extension, PG constants in `wp-config`, PG reachable, migration-engine state, PHP version) **hard-block** progression. Inserts **ONB-S1** (preflight + onboarding page + nav gating + completion flag) and **ONB-S2** (backfill trigger + derived live progress + redirect to Operations) into IMPLEMENTATION_PLAN.md §5b, sequenced **before Phase 1B**. Implications table updated; CLAUDE.md SETTLED gains a DECISION W line; CLAUDE.md Coding Standard/folder notes reconciled to the React amendment. |
| 1.20 | 2026-07-15 | **DECISION V (Operations Console Adoption) — ratifies FLAG-PLANOPS1-1..11 (architect ruling 2026-07-15).** Adopts Doc 12 (Admin Operations Console) into the frozen record, conservatively scoped. Records: (a) FLAG-3 B — MVP console is **server-rendered PHP + minimal vanilla JS + WP native admin UI**; **no node/npm/bundler toolchain**; React deferred to a future ADR that must not alter the provider/registry architecture. (b) FLAG-4 A — coding standard settled: **PSR-12 for platform code; WPCS security requirements (escaping, sanitization, capabilities, nonces) at WordPress entry points only.** (c) FLAG-5 A — all console metrics **derived on-demand per DECISION Q** (processing rate, replay status, reconciliation status computed from existing operational data; **zero new persistence**). (d) FLAG-6 A — Replay/Reconcile console actions are **thin delegators** to `ReplayService` (DECISION T/S) and `ReconciliationService` (DECISION U); **no second repair path ever**; OPSC-S4 DoD must include a **write-spy proof** (zero direct `content.*`/`system.*` writes on the action path). (e) FLAG-7 A — **Flush Queue REMOVED** from the action set; any future queue maintenance must be replay-safe, never destructive deletion. (f) FLAG-8 C-modified — **no Restart Workers action**; console provides worker status, heartbeat, restart guidance, and runbook links only; worker lifecycle belongs to the process supervisor. (g) FLAG-10 A — console providers **reuse the delivery `DatabaseConnectionInterface`** (DECISION K); four-handle topology (DECISION L Ruling 0) unchanged; no new `pg_*` wrapper (DECISION E). (h) FLAG-11 A — operations contracts live under **`core/Contracts/Operations/`** (NOT `core/Operations/Contracts/`); Rule 5 holds verbatim; Doc 12 §3 tree superseded on this point. (i) FLAG-2 A — **`core/Operations/`** (lowercase) added to the canonical folder structure as infrastructure; Doc 2's tree amended by this ruling (Doc 2 not edited). (j) Architect philosophy clause (binding scope) — the console is an **observability/diagnostics interface, not an operational control plane**; restarting services/containers, managing OS processes, and infrastructure orchestration are permanently outside the plugin's scope. **FLAG-9 B:** ratifies **ADR-047, ADR-048, ADR-049, ADR-050, ADR-052, ADR-053** as entries below; **ADR-051 recorded as HELD** — not citable as authority — pending incorporation of the FLAG-7/8 rulings. **FLAG-1 A:** Doc 11 roadmap updated to add "Phase 1A – Expanded — Operations Console & Developer Experience" between Phase 1A and Phase 1B. Doc 12 promoted Draft → "Accepted (as amended by DECISION V)"; §21 self-freeze removed. Implications table updated. Inserts **OPSC-S1..S4** into the §5b Session Map. |
| 1.19 | 2026-07-13 | **DECISION U amended (design ratified) — reconciliation detection design fixed for OPS-S3 build.** Adds section "**DECISION U — Ratified Detection Design (v1.19)**": (1) **Comparison signal by mode** — hourly *drift* = source-timestamp/existence comparison (WP `post_modified_gmt` vs projection `updated_at`; existence vs projection presence/`deleted_at`); nightly *incremental* = recent window + **checksum recompute**; weekly *full* = whole corpus + checksum + orphan sweep. (2) **Taxonomy limitation** — WordPress terms carry **no modified timestamp**, so hourly drift for categories is **existence-only**; category field-staleness (e.g. rename/description edit) is caught by the nightly/weekly **checksum recompute**, not hourly. (3) **Direction by mode** — hourly + nightly are **WP→PG only** (missed create/update); the **orphan sweep (PG→WP) runs in full mode only**; missed-**delete** repair latency is therefore **≤ weekly** at MVP (accepted). (4) **Suppression rule (final)** — an aggregate is **IN-FLIGHT (skip repair)** iff a **pending unrelayed `wp_hsp_outbox` row** exists for it **OR** a `system.events` row exists with `aggregate_version > system.aggregate_versions.latest_processed_version`; otherwise WP-newer-than-projection is a **genuine missed capture** (DECISION 1 gap) and is repaired. (5) **Executor = B1** — `ReconciliationWorkerStrategy::execute()` stays a producer-side no-op; cron/CLI invoke `reconcileDrift/Incremental/Full` in the (worker-bootstrapped) process, matching the `ReplayWorkerStrategy` precedent. (6) **New core contract `WpReconciliationSourceInterface`** (Content-module implementation) for detection-side WP reads — symmetric with `ReplayEmitterInterface`, preserves Rule 5; contract-only, no schema change. (7) **Full sweep is unbounded, paged; page size config-driven.** Repair remains DECISION T `ReplayService::replayEntity` ONLY (no direct PG writes; WordPress wins by construction). No schema change, no fifth PG handle, no new `pg_*` wrapper. Implications rows updated. |
| 1.18 | 2026-07-13 | **DECISION U (Reconciliation MVP via DECISION T Re-emission) — ratifies FLAG-GATES3-1 Option A.** Inserts a new session **OPS-S3 "Reconciliation MVP"** into the IMPLEMENTATION_PLAN.md §5b Session Map immediately **before GATE-S3**; **GATE-S3 Depends-on changes from `GATE-S2` to `OPS-S3`**; **GATE-S3 DoD is UNCHANGED**. Reconciliation un-stubs `ReconciliationWorkerStrategy` and repairs delivery drift **only** by re-emission through the normal pipeline via the DECISION T primitive (`ReplayService`/`ReplayEmitterInterface` — synthetic re-emission through `wp_hsp_outbox` with a fresh `wp_hsp_aggregate_counters` version [DECISION 2], flowing relay → dispatch → worker, passing the DECISION J guard naturally). **Direct PostgreSQL projection writes as a repair path are PROHIBITED.** WordPress-wins (ADR-026/ADR-027/ADR-045; CLAUDE.md Rule 1) holds **by construction**: repair reads current WP state via the emitter and reprojects, never writing WP from PG. **Scope:** drift detection, incremental validation, full reconciliation. Reconciliation must detect (a) **missed captures** — a WP entity newer than, or absent from, the delivery state (the DECISION 1 post-commit-gap backstop), and (b) **orphans** — present in delivery but deleted/non-public in WP (repair = re-emit the `.deleted` event → DECISION I tombstone path). **Scheduling:** WP-Cron is authorized for MVP under the CLAUDE.md recovery-jobs carve-out; **workers remain the execution path — cron only triggers.** No new PG handle (DECISION L Ruling 0 topology frozen at four), no new raw `pg_*` wrapper (DECISION E), no direct PG repair writes. **Resolves FLAG-GATES3-1.** Implications table + Session Map amended. |
| 1.17 | 2026-07-12 | **DECISION T (Replay via Projection Repair by Synthetic Re-emission) — ratifies FLAG-OPSS2-1 Option A.** Entity and date-range replay emit a NEW event through `wp_hsp_outbox`, taking a NEW `aggregate_version` from `wp_hsp_aggregate_counters` (DECISION 2 atomic increment), flowing relay → dispatch → worker and passing the DECISION J stale guard **naturally** (new version > stored version). The guard is NOT weakened, bypassed, or made conditional. Historical `system.events` rows are never mutated or re-enqueued — replay **appends**, never rewrites (Doc 5 §26 immutability holds; Rule 3 outbox path holds). The replay emitter reads CURRENT WordPress state per aggregate (ADR-044/ADR-045 — WordPress wins): aggregate exists and is public → emit the existing OPEN-1 `.updated` type; missing or non-public → emit `.deleted` (correctly tombstones entities deleted during an outage window). No new event-type contracts. Date-range mode: `SELECT DISTINCT (aggregate_type, aggregate_id) FROM system.events` within the `[from, to]` window (read via the existing delivery `DatabaseConnectionInterface` handle — no fifth handle, DECISION L Ruling 0), then one synthetic emit per aggregate. Traceability: synthetic events carry `causation_id` referencing the replay operation; one `correlation_id` groups a replay run. This emit-through-outbox repair primitive is the same mechanism future reconciliation (ADR-026/027/045) will build on. **Supersedes** the IMPLEMENTATION_PLAN.md §5b "re-enqueue original event" wording for entity/date-range modes; single-event DLQ replay under DECISION S is unchanged. **Resolves FLAG-OPSS2-1.** No schema change; no new pg_* wrapper; no new PG handle. Implications table updated. |
| 1.16 | 2026-07-11 | OPS-S1 architect rulings (5). **Ruling 0 — Connection Topology Ratified:** the four-connection topology (relay PG, queue/worker runtime, delivery [DECISION K], dispatcher [DECISION L]) is FROZEN as final; no fifth handle ever without a new ADR; heartbeat publication is worker-runtime infrastructure on the existing worker-runtime connection; no new connection class or raw `pg_*` wrapper. Recorded as amendment to DECISION L; **resolves FLAG-P1AS6D-1**. **Ruling 1 — DECISION P (Worker Heartbeat Storage):** single current-state table `system.worker_heartbeats` (upsert per tick, no history); `DatabaseHeartbeatPublisher` implements existing `HeartbeatPublisherInterface`, connection via constructor injection (ADR-012); migration authorized for OPS-S1. **Ruling 2 — DECISION Q (Metrics Without Persistence):** no metrics table/rollups/external telemetry in MVP; derived metrics computed on demand; runtime counters emitted as structured worker log events; "metrics emit" DoD = queryable operational status + structured log output. **Ruling 3 — DECISION R (Visibility-Timeout Recovery Driver):** `MaintenanceWorkerStrategy` drives `requeueTimedOut()`; cadence config-driven, no hardcoded timing. **Ruling 4 — DECISION S (DLQ Replay Lifecycle):** DLQ rows are permanent audit records (never deleted); replay is one PG transaction (verify exists → verify not replayed → DELETE any `system.queue_jobs` row sharing `event_id` → INSERT fresh job attempts=0 → stamp `replayed_at`); passes DECISION J stale guard; WP-CLI surface only. `replayed_at` is **absent** from the OPEN-3 v1.1 DLQ schema (migration 0004) — adding it is authorized within OPS-S1 migration scope. Implications table updated. |
| 1.15 | 2026-06-25 | DECISION O: credential resolution — `define()` constant → `getenv()` fallback → documented default; required-PG-missing fails loud; MySQL derives from WP `DB_*` constants by default; one `CredentialResolver` in `bootstrap/`; provider factories read resolver, not `getenv()` directly; `wp-config.php` uses `define()` for HSP PG credentials (no `putenv()`). |
| 1.14 | 2026-06-25 | DECISION N: delivery REST namespace is `hsp/v1` (vendor-prefixed WP convention). Renames `api/v1` to `hsp/v1` in `ContentRestRegistrar::NAMESPACE` constant, `hsp-blog/lib/api.ts` fetch paths, and `tools/smoke_e2e.php` curl paths. Doc sites reconciled (DECISION F Implements table, IMPLEMENTATION_PLAN.md §4 endpoint bullets and pipeline diagram, Phase 1A DoD, FLAG-P1AS5-1 flag text). |
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

> **Amendment (v1.16 — 2026-07-11 — Ruling 0: Connection Topology Ratified; resolves FLAG-P1AS6D-1):**
>
> The four-connection PostgreSQL topology is **FROZEN as final**:
>
> 1. **Relay handle** — `outbox.connection.pgsql` (relay-side capture/copy path).
> 2. **Queue/worker runtime handle** — `queue.connection.pgsql` (queue-claim + worker runtime path).
> 3. **Delivery handle** — `DatabaseConnectionInterface` singleton, FORCE_NEW, `DeliveryServiceProvider` (DECISION K).
> 4. **Dispatcher handle** — `dispatcher.connection.pgsql`, FORCE_NEW, `DispatcherServiceProvider` (DECISION L clause (g)).
>
> This is the complete and final set. **No fifth handle may ever be introduced without a new ADR.** The fourth (dispatcher) handle is accepted as a pragmatic, ratified extension of the DECISION E (v1.6) temporary-duplication allowance — it is no longer "pending ratification." Consolidation of the four handles remains a future-ADR concern, not an OPS-S1 concern.
>
> **Heartbeat publication is worker-runtime infrastructure**, not a delivery or dispatcher concern. It uses the **existing worker-runtime connection** (handle 2 above), injected into `DatabaseHeartbeatPublisher` via constructor (ADR-012). This introduces **no new connection**: it does not add a fifth handle, does not create a new connection class, and does not add a new raw `pg_*` wrapper. See DECISION P.
>
> **FLAG-P1AS6D-1 is resolved by this ratification** — answer (a) "yes, the fourth FORCE_NEW handle is accepted"; the topology is frozen at four, and DECISION L now records it explicitly.

---

### DECISION P — Worker Heartbeat Storage (Ruling 1)

| Field | Value |
|---|---|
| **Status** | Accepted |
| **Date** | 2026-07-11 |
| **Session** | OPS-S1 (pre-implementation ruling) |
| **Authority** | Doc 8 §15 (heartbeat field intent); ADR-012 (constructor injection); ADR-015 (UUIDv7 worker identity); OPEN-3 v1.1/v1.2 type canon |
| **Resolves** | FLAG-OPSS1-1 (heartbeat persistence) |

**Ruling:** Worker heartbeats persist to a **single current-state table** — one row per worker, **upserted per tick**. There is **no history table**.

**`system.worker_heartbeats` — frozen DDL:**

```sql
worker_id         UUID        NOT NULL,   -- UUIDv7, self-assigned at worker startup (ADR-015)
worker_type       TEXT        NOT NULL,   -- e.g. 'event', 'dispatcher', 'maintenance', 'relay'
status            TEXT        NOT NULL,   -- e.g. 'running', 'idle', 'stopping'
last_heartbeat_at TIMESTAMPTZ NOT NULL,   -- updated every tick; heartbeat-age crash detection reads this
started_at        TIMESTAMPTZ NOT NULL,   -- worker process start time
PRIMARY KEY (worker_id)
```

The upsert (`INSERT … ON CONFLICT (worker_id) DO UPDATE SET status = …, last_heartbeat_at = …`) advances the current-state row each tick. A monitor detects a crashed worker by `last_heartbeat_at` age (Doc 8 §15). All timestamps are `TIMESTAMPTZ` (v1.2 canon); `worker_id` is `UUID` (v1.1 canon).

**Publisher:** `DatabaseHeartbeatPublisher` implements the **existing** `HeartbeatPublisherInterface` (introduced in P0-S6; currently satisfied by `NullHeartbeatPublisher`). It receives its PostgreSQL connection via **constructor injection** (ADR-012 — no service-locator call). The connection is the **worker-runtime handle** (DECISION L Ruling 0), not the delivery or dispatcher handle.

**Migration authorization:** A new migration creating `system.worker_heartbeats` is **explicitly authorized for OPS-S1**. This is the formal amendment that lifts the freeze objection recorded in FLAG-OPSS1-1: the table is now a frozen contract in this document and in the Implications table below.

**Rationale:** The DoD requires heartbeat to be "visible … and updated per tick." Current-state-only storage satisfies visibility and crash detection with the minimal schema; a history table adds unbounded growth and retention concerns for no MVP benefit. Reusing `HeartbeatPublisherInterface` avoids new contract surface — only the null implementation is swapped for a database-backed one.

---

### DECISION Q — Metrics Without Persistence (Ruling 2)

| Field | Value |
|---|---|
| **Status** | Accepted |
| **Date** | 2026-07-11 |
| **Session** | OPS-S1 (pre-implementation ruling) |
| **Authority** | Doc 8 §27 (metric minimum set — intent); MVP scope (no OpenSearch/telemetry backend); observability-in-core |
| **Resolves** | FLAG-OPSS1-2 (metric source of truth) |

**Ruling:** MVP introduces **no metrics table, no rollup tables, and no external telemetry backend** (statsd, Prometheus, OpenSearch, etc.). Operational metrics are served two ways:

1. **Derived metrics — computed on demand from PostgreSQL.** Queue depth, DLQ depth, oldest-pending age, and worker count are computed by aggregate query at read time over the existing tables (`system.queue_jobs`, `system.dead_letter_jobs`, `system.worker_heartbeats`). No column is added to any frozen table; no counter is persisted for these.
2. **Runtime counters — emitted as structured worker log events.** Processed / retry / failure / replay counts are emitted as **structured log output** from the worker runtime. They are not stored in a metrics table and do not require a schema change.

**DoD definition:** The OPS-S1 DoD term **"metrics emit"** is defined as: **queryable operational status (on-demand PostgreSQL aggregates) + structured log output (runtime counters).** This is the acceptance bar — no persisted metric store is owed.

**Rationale:** A metrics table would require either a frozen-schema change (`system.queue_jobs` gaining per-job timing columns — a freeze conflict) or a new table with its own retention story, neither justified at MVP. Derived-on-demand + structured logs give operators the required visibility with zero new persistent schema and no conflict with the frozen queue/DLQ contracts. FLAG-OPSS1-2's "no producer" concern is resolved by defining the producer as on-demand queries plus log emission, not a counter sink.

---

### DECISION R — Visibility-Timeout Recovery Driver (Ruling 3)

| Field | Value |
|---|---|
| **Status** | Accepted |
| **Date** | 2026-07-11 |
| **Session** | OPS-S1 (pre-implementation ruling) |
| **Authority** | OPEN-4 (visibility timeout; config-driven duration; requeue on expiry); Doc 8 (worker strategies); ADR-012 |
| **Resolves** | FLAG-OPSS1-3 (crash→requeue runtime driver) |

**Ruling:** `MaintenanceWorkerStrategy` is the **runtime driver** for `DatabaseQueueProvider::requeueTimedOut()`. It is un-stubbed in OPS-S1 (scope: `core/Workers/`) to invoke `requeueTimedOut()` on the maintenance tick, reviving jobs whose `visibility_timeout_at` has expired without completion.

**Cadence is configuration-driven** with a sensible default; **no hardcoded timing values** in the strategy. The cadence config key is defined alongside the existing visibility-timeout config (OPEN-4 established that the timeout duration is config-driven; the recovery cadence follows the same discipline).

The recovery/requeue loop uses the **worker-runtime connection** (DECISION L Ruling 0) — the same handle the queue provider already uses — not a new handle.

**Rationale:** `requeueTimedOut()` already exists and is tested; the only gap was a runtime owner. `MaintenanceWorkerStrategy` is the natural home (its own stub comment already anticipated this). Config-driven cadence keeps operational tuning out of code and consistent with the OPEN-4 config-driven-timeout precedent.

---

### DECISION S — DLQ Replay Lifecycle (Ruling 4)

| Field | Value |
|---|---|
| **Status** | Accepted |
| **Date** | 2026-07-11 |
| **Session** | OPS-S1 (pre-implementation ruling) |
| **Authority** | Doc 4 §24 (single-event replay); DECISION A (DLQ self-contained/replayable); DECISION J (Resolve-stage stale guard); DECISION L clause (d) (UNIQUE(event_id) on `system.queue_jobs`); OPEN-3 v1.1 (DLQ schema) |
| **Resolves** | FLAG-OPSS1-4 (replay entry point + DLQ tooling surface) |
| **Amends** | OPEN-3 (adds `replayed_at` to `system.dead_letter_jobs`) |

**Ruling:**

**(a) DLQ rows are permanent audit records — never deleted.** Replay does not delete the DLQ row. The row is preserved as an audit trail; replay marks it, it does not remove it.

**(b) Replay executes in ONE PostgreSQL transaction** with these steps, in order:
1. **Verify** the DLQ row exists.
2. **Verify** it has not already been replayed (`replayed_at IS NULL`).
3. **DELETE** any `system.queue_jobs` row sharing the same `event_id`. This is mandatory: DECISION L clause (d) retains `completed`/`dead_lettered` rows in `system.queue_jobs`, and `UNIQUE(event_id)` means a naive re-enqueue would `ON CONFLICT DO NOTHING` — a silent no-op. Clearing the prior job row first is what makes the fresh insert take effect.
4. **INSERT** a fresh `system.queue_jobs` job for the event with **`attempts` reset to 0**.
5. **Stamp** `replayed_at` on the DLQ row.

**(c) Replay passes the Resolve-stage stale guard (DECISION J).** The re-enqueued event re-enters the pipeline through the normal queue/claim path. If the aggregate is already at or beyond the event's version, the Resolve-stage guard acks the job with **zero projection writes** — this is **correct behavior**, not an error. Replay never writes projections directly.

**(d) Surface: WP-CLI only.** The operational surface is `hsp dlq list | inspect | replay`. **No admin UI** is built in OPS-S1 (this sidesteps the still-TBD WPCS/coding-standard decision at the WP admin boundary).

**(e) `replayed_at` schema addition — authorized.** `replayed_at TIMESTAMPTZ NULL` is **absent** from the OPEN-3 v1.1 DLQ schema (verified against migration `0004_create_system_dead_letter_jobs.sql`, which carries only the four OPEN-3 delta columns). Adding it is **explicitly authorized within the OPS-S1 migration scope** as a forward migration (must not edit frozen migration 0004). This ruling is the formal amendment to OPEN-3; the Implications table below is updated accordingly.

**Rationale:** Permanent DLQ rows preserve the audit trail DECISION A requires. The single-transaction delete-then-insert closes the `UNIQUE(event_id)` no-op trap identified in FLAG-OPSS1-4: without clearing the prior job row, replay would silently do nothing. Resetting `attempts` to 0 gives the replayed job a full retry budget. Relying on the DECISION J guard to no-op an already-current aggregate keeps replay idempotent and projection-safe. WP-CLI-only avoids coupling replay to the unresolved WP-admin coding-standard question.

---

### DECISION T — Replay via Projection Repair by Synthetic Re-emission (Ratifies FLAG-OPSS2-1 Option A)

| Field | Value |
|---|---|
| **Status** | Accepted |
| **Date** | 2026-07-12 |
| **Session** | OPS-S2 (pre-implementation ruling) |
| **Authority** | Doc 4 §24 (replay modes); DECISION 1 (near-atomic capture via outbox); DECISION 2 (per-aggregate atomic counter); DECISION J (Resolve-stage stale guard); DECISION S (single-event DLQ replay); ADR-044/ADR-045 (stateless workers, WordPress wins); Doc 5 §26 (event immutability); CLAUDE.md Rule 3 (outbox path), Rule 5 (module isolation) |
| **Resolves** | FLAG-OPSS2-1 |
| **Supersedes** | IMPLEMENTATION_PLAN.md §5b "re-enqueue original event" wording — for entity/date-range replay modes only |

**Ruling — Option A approved:** Entity and date-range replay is **projection repair via synthetic re-emission**. It does not re-enqueue or mutate any historical event.

**1. MECHANISM.** Entity replay (given `aggregate_type, aggregate_id`) and date-range replay each emit a **NEW** event through `wp_hsp_outbox` (Rule 3 holds), taking a **NEW** `aggregate_version` from `wp_hsp_aggregate_counters` via the DECISION 2 atomic increment. The synthetic event flows through the normal pipeline: relay → `system.events` → dispatch → `system.queue_jobs` → worker. Because the new version is strictly greater than `system.aggregate_versions.latest_processed_version`, it passes the DECISION J Resolve-stage stale guard **naturally**. The guard is **not** weakened, bypassed, or made conditional. Historical `system.events` rows are **never** mutated or re-enqueued — replay **appends**, never rewrites, so Doc 5 §26 immutability holds. (This is the exact inverse of the FLAG-OPSS2-1 conflict: a re-enqueue of the *original* version would be `<=` stored and get acked with zero writes; a fresh higher-versioned synthetic event reprojects correctly.)

**2. SEMANTICS (WordPress wins — ADR-044/ADR-045).** The replay emitter reads **current** WordPress state per aggregate at emit time:
- aggregate **exists and is public** (public set = `{publish}`, OPEN-10) → emit the existing OPEN-1 `.updated` type (`content.{page|post|category}.updated`);
- aggregate **missing or non-public** → emit the existing OPEN-1 `.deleted` type.

No new event-type contracts are introduced. This correctly **tombstones** entities that were deleted or unpublished during an outage window: replaying them projects the current (absent) reality, not a stale snapshot. Categories have no `publish` status; a category is "public" iff its term currently exists.

**3. DATE-RANGE MODE.** Enumerate affected aggregates with `SELECT DISTINCT aggregate_type, aggregate_id FROM system.events WHERE created_at >= :from AND created_at < :to`, then perform one synthetic emit per distinct aggregate (semantics per point 2). The window is half-open `[from, to)`. The `system.events` read reuses the **existing delivery `DatabaseConnectionInterface` handle** (DECISION K) — the same handle the Dispatcher and Resolve-stage already use to read `system.events`/`system.aggregate_versions`. **No fifth PG handle is opened** (DECISION L Ruling 0 — topology frozen at four). No new raw `pg_*` wrapper.

**4. TRACEABILITY.** Each synthetic event carries a `causation_id` referencing the replay operation, and all synthetic events from one replay run share a single `correlation_id`. This makes a replay run auditable and distinguishable from organic edits in `system.events`.

**5. CONVERGENCE.** This emit-through-outbox repair primitive is the **same mechanism** future reconciliation (ADR-026/ADR-027/ADR-045) will use to repair the delivery side from WordPress. Reconciliation sessions build on DECISION T rather than inventing a second repair path.

**Scope boundaries.** Single-event DLQ replay under DECISION S is **unchanged** (it re-enqueues a never-processed dead-lettered event whose version is still ahead of the guard). Full Replay (Doc 4 §24) is out of MVP gate scope (Phase 3). Module isolation (Rule 5): the replay orchestration/discovery lives in `core/` and depends on a **core-owned `ReplayEmitterInterface`**; the Content module implements it (WP-state read + `.updated`/`.deleted` decision + outbox emit via the existing `EventProviderInterface`/`OutboxWriter`). Core never imports the module.

**Rationale:** The FLAG-OPSS2-1 conflict was that re-enqueuing the *original* event version cannot reproject an already-current aggregate (DECISION J acks it with zero writes). A fresh synthetic event with a new counter version dissolves the conflict: it honors the outbox path (Rule 3), reloads current state (ADR-044), advances the version monotonically so the guard passes without modification, and leaves the historical event log immutable (Doc 5 §26). It requires no schema change and no new connection. Reading current WP state (not a stored snapshot) means replay after an outage converges the projection to reality — including deletions — which is precisely the reconciliation guarantee.

---

### DECISION U — Reconciliation MVP via DECISION T Re-emission (Ratifies FLAG-GATES3-1 Option A)

| Field | Value |
|---|---|
| **Status** | Accepted |
| **Date** | 2026-07-13 |
| **Session** | GATE-S3 flag resolution (architect ruling) |
| **Authority** | Doc 11 §9–10 (Operability Validation); ADR-026 (drift detection / incremental validation / full reconciliation schedule); ADR-027, ADR-045 (WordPress wins divergence); DECISION 1 (near-atomic capture + reconciliation backstop); DECISION T (synthetic re-emission repair primitive); DECISION I (tombstone path); DECISION J (Resolve-stage stale guard); DECISION L Ruling 0 (four-connection topology frozen); DECISION E (no new `pg_*` wrapper); CLAUDE.md Rule 1 (WordPress source of truth), recovery-jobs WP-Cron carve-out |
| **Resolves** | FLAG-GATES3-1 |
| **Amends** | IMPLEMENTATION_PLAN.md §5b Session Map (inserts OPS-S3; re-points GATE-S3 Depends-on) |

**Ruling — Option A approved.** A reconciliation build session is inserted before GATE-S3. Reconciliation is un-stubbed and validated, then GATE-S3 runs against real behavior.

**1. SESSION-MAP CHANGE.** A new session **OPS-S3 "Reconciliation MVP"** is inserted in the §5b Session Map **immediately before GATE-S3**. **GATE-S3 `Depends-on` changes from `GATE-S2` to `OPS-S3`.** **GATE-S3 DoD is UNCHANGED** — it still validates all three Operability criteria (worker health / failure detection within one heartbeat cycle; DLQ payload snapshot + stack trace; reconciliation executes with WordPress-wins repair). GATE-S1/GATE-S2 are unaffected.

**2. REPAIR MECHANISM — DECISION T re-emission ONLY.** Reconciliation repairs the delivery side **exclusively** by re-emission through the normal pipeline via the DECISION T primitive (`core/Replay/ReplayService` → core-owned `ReplayEmitterInterface` → module `ContentReplayEmitter`): a synthetic event is emitted through `wp_hsp_outbox` taking a fresh `wp_hsp_aggregate_counters` version (DECISION 2), flowing relay → `system.events` → dispatch → `system.queue_jobs` → worker, and passing the DECISION J Resolve-stage stale guard **naturally** (new version > stored). **Direct PostgreSQL projection writes as a repair path are PROHIBITED.** Reconciliation never writes `content.*`, `system.processed_events`, or `system.aggregate_versions` directly — only the worker pipeline does, exactly as for organic edits. This is the "reconciliation sessions build on DECISION T rather than inventing a second repair path" commitment recorded in DECISION T point 5.

**3. WORDPRESS WINS BY CONSTRUCTION (ADR-026/ADR-027/ADR-045).** Because repair is re-emission and the emitter reads **current** WordPress state (exists+public → `.updated`; missing/non-public → `.deleted`, DECISION T point 2), the delivery side is always repaired **to** WordPress and WordPress is **never** written from PG. WordPress-wins is therefore structural, not a runtime branch that could be inverted.

**4. SCOPE — three detection modes.** Reconciliation covers **drift detection**, **incremental validation**, and **full reconciliation** (ADR-026). Across these modes it must detect and repair both divergence classes:
- **(a) Missed captures** — a WordPress entity that is newer than, or absent from, the delivery state. This is the **DECISION 1 backstop**: the narrow window between WP commit and the post-commit outbox write can drop a capture; reconciliation closes it. Repair = re-emit → the aggregate reprojects to current WP state.
- **(b) Orphans** — an aggregate present in the delivery projection but **deleted or non-public** in WordPress. Repair = re-emit; the emitter observes the aggregate is missing/non-public and emits the `.deleted` event, driving the **DECISION I tombstone path** (soft-delete `deleted_at`), so the orphan is correctly tombstoned rather than left as a stale published row.

**5. SCHEDULING.** WP-Cron is **authorized for MVP** to *trigger* reconciliation, under the CLAUDE.md recovery-jobs carve-out (WP-Cron is a fallback for recovery/safety jobs, never the primary execution path). **Workers remain the execution path** — WP-Cron only enqueues/triggers a reconciliation pass; the actual detection scan and all repair re-emission run on the worker runtime, not inside the cron request. The ADR-026 cadences (hourly drift / nightly incremental / weekly full) map onto WP-Cron schedules for MVP; migrating the trigger to systemd/external scheduling later requires no repair-path change (it is a trigger swap only).

**6. CONSTRAINTS (binding).** No new PostgreSQL handle (DECISION L Ruling 0 — topology frozen at four; reconciliation reuses existing runtime handles). No new raw `pg_*` wrapper class (DECISION E). No new event-type contracts (reuses OPEN-1 `.updated`/`.deleted`). Module isolation (Rule 5): reconciliation orchestration/discovery lives in `core/`; the WordPress-state read and emit decision live in the Content module behind `ReplayEmitterInterface`; core never imports a module. Any drift-detection query, entry-point (CLI/cron) shape, and false-positive-suppression mechanism is a **design detail to be ratified in the OPS-S3 design step** before implementation — this DECISION authorizes the session and fixes the repair mechanism, not the query shapes.

**Rationale:** FLAG-GATES3-1 was a Session-Map sequencing conflict: GATE-S3 required reconciliation to *execute* before any session built it. Option A resolves it in the way most consistent with the frozen record — DECISION T already established the emit-through-outbox repair primitive and explicitly reserved it as the mechanism future reconciliation would build on (DECISION T point 5). Constraining repair to that single primitive keeps WordPress-wins structural, avoids a second repair path, and requires no schema change or new connection. Deferring the query/entry-point/false-positive details to the OPS-S3 design step keeps this ruling to the architectural commitments (session insertion, repair mechanism, WP-wins, scope, scheduling) without prematurely fixing implementation shape.

#### DECISION U — Ratified Detection Design (v1.19)

The OPS-S3 design was reviewed and ratified in-chat with the following binding design decisions. These fix the detector; the repair mechanism (DECISION T re-emission only) and all constraints in points 1–6 above are unchanged.

**(D1) Comparison signal by mode.** Detection runs in three modes mapping onto the ADR-026 cadences:

| Mode | Cadence | Detection signal | Direction |
|---|---|---|---|
| **drift** | hourly | Source-timestamp / existence comparison. Posts/pages: WP `post_modified_gmt` vs projection `updated_at`; existence vs projection presence & `deleted_at`. Categories: **existence-only** (see D2). No checksum recompute. | WP→PG |
| **incremental** | nightly | Recent window (config horizon) + **checksum recompute** (deep field equality — catches silent drift a timestamp missed). | WP→PG |
| **full** | weekly | Whole corpus + checksum + **orphan sweep** (PG→WP). Unbounded, paged; page size config-driven. | WP→PG **and** PG→WP |

**(D2) Taxonomy limitation.** WordPress terms carry **no modified timestamp** (there is no `term_modified` analogue to `post_modified_gmt`). Therefore hourly drift for categories is **existence-only**: it detects a missing projection row for a live term (missed create) and a live projection row for a deleted term is an orphan (full mode only). Category **field** staleness (a rename, description edit) is invisible to the hourly timestamp pass and is caught instead by the **nightly/weekly checksum recompute**. This is an accepted MVP latency, recorded so it is not mistaken for a detector bug.

**(D3) Direction by mode (missed-delete latency).** Hourly and nightly are **WP→PG only** — they detect missed **creates/updates** (a WP entity newer than, or absent from, delivery). The **orphan sweep (PG→WP)** — a projection row with no live/public WP entity — runs in **full mode only**. Consequently a missed **delete** (a WP entity deleted or unpublished without a `.deleted` event ever emitted) is repaired at a latency of **≤ one week** at MVP. Accepted: the `.deleted` capture path (OPEN-10 transitions + `after_delete_post`) is the primary delete mechanism; the weekly orphan sweep is the backstop, and the DECISION 1 gap for deletes is narrow.

**(D4) Suppression rule (final — false-positive guard).** Before repairing any aggregate that WP→PG comparison flags as "WP newer than / absent from delivery", the detector must **skip** it (treat as IN-FLIGHT, not drift) iff **either**:
1. a **pending unrelayed `wp_hsp_outbox` row** exists for the aggregate (`status = 'pending'`), **OR**
2. a **`system.events` row** exists for the aggregate with `aggregate_version > system.aggregate_versions.latest_processed_version` (captured/relayed but not yet projected).

If neither holds, the newer-WP state was **never captured** — a genuine DECISION 1 missed capture — and is repaired via re-emission. This spans the whole pipeline: (1) covers the capture-not-yet-relayed window (MySQL side); (2) covers the relayed-not-yet-processed window (PG side). The narrow residual race (WP edited between the detector's WP read and PG read) is self-healing — at worst one redundant synthetic emit, collapsed by the DECISION J guard and idempotent upsert; no correctness exposure.

**(D5) Executor = B1.** `ReconciliationWorkerStrategy::execute()` stays a **producer-side no-op** (`return false`), exactly like `ReplayWorkerStrategy` under DECISION T. Reconciliation is triggered by WP-Cron / WP-CLI, which invoke `reconcileDrift()` / `reconcileIncremental()` / `reconcileFull()` on the strategy; the detection scan and all repair re-emission run **on the worker-bootstrapped process**, not by claiming a `system`-queue job. No `system` reconcile job type is introduced (avoids inventing queue-routing surface at MVP).

**(D6) New core contract `WpReconciliationSourceInterface`.** Detection-side WordPress reads (list aggregate IDs by type, fetch `post_modified_gmt`/existence/public-status, and — for checksum modes — the data needed to recompute the projection checksum) live behind a new **core-owned `WpReconciliationSourceInterface`**, implemented in the Content module (`modules/Content/Reconciliation/`). Symmetric with `ReplayEmitterInterface`; core never imports the module (Rule 5). Contract-only — **no schema change**.

**(D7) Full sweep bound + paging.** Full reconciliation is **unbounded** (whole corpus) but **paged**; the page size is **config-driven** (following the DECISION R cadence-config precedent — no hardcoded batch size). All modes page WP IDs in chunks and fetch paired projection rows per chunk in one PG read; no cross-DB join (Rule 8).

**Connections (unchanged from points 1–6):** PG reads (`content.*`, `system.aggregate_versions`, `system.events`) use the existing **delivery `DatabaseConnectionInterface`** handle; the pending-outbox suppression read (D4 clause 1) uses the WP `$wpdb`/outbox read path in the worker bootstrap. No fifth handle (DECISION L Ruling 0), no new `pg_*` wrapper (DECISION E).

---

### DECISION V — Operations Console Adoption (Ratifies FLAG-PLANOPS1-1..11)

| Field | Value |
|---|---|
| **Status** | Accepted |
| **Date** | 2026-07-15 |
| **Session** | OPSC-S0 (docs-only ratification) |
| **Authority** | Architect ruling 2026-07-15 (FLAG-PLANOPS1-1..11); PLAN-OPS-1 audit (`docs/notes/DOC12-ADOPTION-AUDIT.md`); Doc 12 (Admin Operations Console); DECISION K (delivery connection), DECISION L Ruling 0 (four-handle topology), DECISION E (no new `pg_*` wrapper), DECISION P (heartbeat current-state), DECISION Q (metrics without persistence), DECISION S/T (replay), DECISION U (reconciliation); DECISION 1 + Rule 4 (never lose a sync); CLAUDE.md Rules 1–8 |
| **Resolves** | FLAG-PLANOPS1-1, -2, -3, -4, -5, -6, -7, -8, -9, -10, -11 |
| **Adopts** | Doc 12 (as amended by this decision) — promoted Draft → "Accepted (as amended by DECISION V)" |
| **Amends** | CLAUDE.md folder structure + Coding Standard; Doc 2 folder tree (Doc 2 not edited — amended by this ruling); Doc 11 roadmap; IMPLEMENTATION_PLAN.md §5b Session Map (inserts OPSC-S1..S4) |

**Ruling.** The HSP Operations Console (Doc 12) is adopted into the frozen architecture, conservatively scoped per the architect's 2026-07-15 rulings. Doc 12 is authoritative for the console's registry/provider architecture; where Doc 12 conflicts with this decision, **this decision wins** (precedence, line 3). The eleven flags are resolved as follows.

**(a) Frontend stack (FLAG-3 B).** The MVP console is **server-rendered PHP + minimal vanilla JavaScript + the WordPress native admin UI**. **No node/npm/bundler toolchain is introduced**; no `package.json`, no CI build step, no shipped JS/TS bundle. Doc 12 §10's "React UI" and its Console State Store / Refresh Coordinator become simple server-rendered pages with lightweight JS polling. React remains available only via a **future ADR** that **must not alter the provider/registry architecture** ratified here. Doc 12 §3's `Assets/`/`UI/` subtree is reduced accordingly (no build config).

> **⚠ AMENDED by DECISION W (v1.21, 2026-07-16).** Clause (a) is **superseded going forward**: React + shadcn is now adopted as **THE admin UI stack** (DECISION W is the "future ADR" this clause anticipated). Build-artifact policy = **commit `dist/` to the repo** (npm build in dev/CI only; production deploy is a file copy — no host build step); WPCS security applies at the REST/ajax endpoints the React app calls. **The already-shipped OPSC-S1..S4 server-rendered PHP Operations Console is NOT rewritten by this** — it remains as built; only **new** admin UI (including onboarding) is React+shadcn. The **provider/registry architecture** ratified in this clause and in clauses (g)–(j) — registries, provider contracts, the `OperationsService` seam, ADR-047/048/052/053, the read-only/observability-only philosophy — is **unchanged**; DECISION W changes rendering technology only. See DECISION W.

**(b) Coding standard (FLAG-4 A).** The platform coding standard is settled: **PSR-12 for all platform code**; **WPCS security requirements — escaping, sanitization, capability checks, nonces — apply only at WordPress entry points** (admin pages, form handlers, REST registration, `$wpdb` calls). This confirms the IMPLEMENTATION_PLAN.md §3 wording and lifts the "TBD / do not enforce" hold. DECISION S clause (d) deliberately shipped CLI-only to avoid this boundary; it is now resolved and the admin boundary is open.

**(c) Metrics derived on-demand (FLAG-5 A).** All console metrics are **derived on-demand per DECISION Q** — **zero new persistence**. Processing rate, replay status, and reconciliation status are computed from existing operational data (e.g. rolling-window queries over `system.queue_jobs.processed_at`; last-run summaries derived from existing rows/logs). **No metrics table, no rollups, no time-series store** may be introduced. Doc 12 §12's "Processing Rate / Replay Progress / Reconciliation Status" tiles are point-in-time derivations, not persisted progress surfaces.

**(d) Actions are thin delegators; no second repair path (FLAG-6 A).** The Replay and Reconcile console actions are **thin delegators** to `core/Replay/ReplayService` (DECISION T/S) and `core/Reconciliation/ReconciliationService` (DECISION U). They **never** open a second repair path and **never** write `content.*` / `system.*` projections directly — repair is re-emission only, exactly as for organic edits and reconciliation (DECISION T point 5 / DECISION U point 2). **OPSC-S4's DoD must include a write-spy proof**: zero direct `content.*`/`system.*` writes on the action path, mirroring the GATE-S3 reconciliation evidence.

**(e) Flush Queue removed (FLAG-7 A).** **Flush Queue is removed from the action set.** A destructive queue flush discards pending/failed jobs and violates at-least-once (Rule 4), "never lose a sync" (DECISION 1), and the never-drop-a-sync anti-pattern. Any future queue-maintenance action must be **replay-safe** (e.g. requeue timed-out jobs via DECISION R, or move stuck jobs to the DLQ), **never destructive deletion**.

**(f) No Restart Workers action (FLAG-8 C-modified).** The console provides **worker status, heartbeat, restart guidance, and runbook links only** — **no Restart Workers action**. A wp-admin PHP request cannot control a systemd/Supervisor-managed process; worker lifecycle belongs to the **process supervisor**. The console surfaces DECISION P heartbeat state and links to the operational runbook; it does not attempt lifecycle control.

**(g) Provider PG reads reuse the delivery handle (FLAG-10 A).** Console providers running in the wp-admin request read `system.*` / `content.*` through the existing **delivery `DatabaseConnectionInterface`** (DECISION K). This opens **no fifth handle** — the four-handle topology (DECISION L Ruling 0) is unchanged — and introduces **no new raw `pg_*` wrapper** (DECISION E).

**(h) Operations contracts location (FLAG-11 A).** Operations contracts live under **`core/Contracts/Operations/`** (a namespace under the existing contracts root), **not** `core/Operations/Contracts/`. This keeps **CLAUDE.md Rule 5 ("modules depend on `core/Contracts/` only") true verbatim** when a module (e.g. `modules/Content/Operations/`) provides a widget / diagnostics / metrics implementation. Doc 12 §3's `Core/Operations/Contracts/` tree is **superseded** on this point.

**(i) `core/Operations/` folder (FLAG-2 A).** **`core/Operations/`** (lowercase, matching the repo/namespace convention `HSP\Core\Operations\`) is added to the canonical folder structure as **core infrastructure** (housing Admin, Registries, Providers, Services, Diagnostics, and the server-rendered UI). Doc 12's `Core/` uppercase casing is corrected to `core/`. Doc 2's folder tree is amended by this ruling; **Doc 2 is not edited** (the amendment lives here).

**(j) Philosophy — observability, not a control plane (binding scope).** The Operations Console is an **observability and diagnostics interface, not an operational control plane.** Restarting services or containers, managing OS processes, and infrastructure orchestration are **permanently outside the plugin's scope.** Every current and future console session is bound by this: the console reports and diagnoses; it does not operate the infrastructure. State-changing actions are limited to the platform's own re-emission primitives (replay, reconcile) that route through the ratified services.

**ADR ratification (FLAG-9 B).** ADR-047, ADR-048, ADR-049, ADR-050, ADR-052, ADR-053 are ratified as entries below (each consistent with Doc 12 as amended by this decision). **ADR-051 (Operational Actions) is HELD** — recorded but **not citable as authority** by any session — until the FLAG-7 (no Flush Queue) and FLAG-8 (no Restart Workers) rulings are incorporated into its text. No ADR number collides (prior ceiling was ADR-046).

**Doc 11 (FLAG-1 A).** Doc 11's roadmap is updated to add **"Phase 1A – Expanded — Operations Console & Developer Experience"** between Phase 1A (Blog MVP) and Phase 1B (Content Enhancement).

**Doc 12 status.** Doc 12 is promoted from Draft to **"Accepted (as amended by DECISION V — see ARCHITECTURE_DECISIONS.md)"**; its §21 self-freeze sentence is removed (freezing is this record's act, not the doc's). No other content edits are made to Doc 12 — all supersessions live in this decision, not in doc rewrites.

**Rationale.** The console's architectural value is the registry/provider discipline and the delegation-only action model, not a specific frontend framework. Server-rendered PHP delivers both MVP nav items (Operations dashboard + API Playground) with zero new toolchain and the smallest possible admin-boundary surface, letting the operational architecture be validated before any richer-client investment. Constraining actions to the ratified re-emission primitives keeps WordPress-wins structural and prevents a second repair path. Removing Flush Queue and Restart Workers keeps the platform's never-lose-a-sync guarantee and the supervisor-owned worker lifecycle intact. Reusing the delivery handle preserves the frozen four-handle topology. Placing contracts under `core/Contracts/Operations/` preserves Rule 5 verbatim with no rule edit.

---

### DECISION W — Onboarding & First-Run Backfill

| Field | Value |
|---|---|
| **Status** | Accepted |
| **Date** | 2026-07-16 |
| **Session** | ONB-S0 (docs-only ratification; OPSC-S0 pattern) |
| **Authority** | Architect ruling 2026-07-16; DECISION U (reconciliation = re-emission repair via `ReconciliationService`); DECISION T (re-emission primitive); DECISION V (Operations Console adoption — clause (a) amended here, clauses (b)–(j) unchanged); DECISION P (worker heartbeat current-state); DECISION Q (metrics/progress without persistence); DECISION K (delivery handle), DECISION L Ruling 0 (four-handle topology), DECISION E (no new `pg_*` wrapper); DECISION 1 + Rule 4 (never lose a sync); CLAUDE.md Rules 1–8 |
| **Amends** | **DECISION V (a)** (frontend stack — React+shadcn adopted globally); CLAUDE.md folder structure (`core/Onboarding/`), Coding Standard note (React admin UI), SETTLED (adds a DECISION W line); IMPLEMENTATION_PLAN.md §5b Session Map (inserts ONB-S1, ONB-S2 before Phase 1B) |

**Ruling.** The HSP Onboarding / First-Run experience is adopted into the frozen architecture with the six rulings below. Where this decision conflicts with DECISION V, **this decision wins** (precedence, line 3) — specifically for clause (a) (frontend stack).

**(a) UI stack — DECISION V (a) AMENDED: React + shadcn adopted for the admin UI.** DECISION V (a) deferred React to "a future ADR that must not alter the provider/registry architecture." **This decision is that ruling.** React + shadcn is adopted as **THE admin UI stack** for HSP going forward.
- **Build-artifact policy: commit `dist/` to the repo.** The npm/bundler toolchain runs in **dev/CI only**; the compiled JS/CSS bundle is committed under a `dist/` (build output) directory and the production deploy is a **plain file copy** (consistent with the CLAUDE.md session-close robocopy step). **No node/npm build step runs on the WordPress host.** `package.json` and the toolchain live in the repo for dev/CI; production carries only the built assets.
- **WPCS boundary:** WPCS security rules (output escaping, input sanitization, capability checks, nonces) apply at the **REST/ajax endpoints the React app calls** (the JSON boundary), not inside the React render tree. This extends DECISION V (b) to the new client transport: the client is untrusted; every server endpoint it calls sanitizes input, checks capability, and verifies the nonce.
- **Scope:** The **already-shipped OPSC-S1..S4 server-rendered PHP Operations Console is NOT rewritten by this decision** — it remains as built. Only **new** admin UI — including the onboarding surface (ONB-S1/S2) — is built in React+shadcn. The **provider/registry architecture** ratified by DECISION V (a) and clauses (g)–(j) (registries, provider contracts, the `OperationsService` seam, ADR-047/048/052/053, and the read-only/observability-only console philosophy) is **unchanged**; DECISION W changes rendering technology only. A future migration of the Operations Console itself from PHP to React is permitted but out of this decision's scope and requires its own session.

> **Styling addendum (architect-ruled 2026-07-16, ONB-S1a STEP 0).** For the React/shadcn admin stack, the **dark theme is the DEFAULT** (shadcn `.dark` token strategy). It is applied **on the plugin mount root only** — never on the `wp-admin` `<body>`. Tailwind preflight/base styles MUST be scoped to the mount container (prefix or scoped-selector strategy) so wp-admin's own styles are untouched; a wp-admin page outside the mount must be visually unaffected. The already-shipped server-rendered OPSC console keeps its current styling until a future console-migration session.

**(b) Backfill mechanism — full-reconciliation re-emission via `ReconciliationService` (DECISION U); no direct copy path.** The initial content migration (first-run population of the delivery projections from existing WordPress content) is performed as a **full reconciliation** (`ReconciliationService::reconcileFull()`, DECISION U) that re-emits every in-scope aggregate through the **normal outbox → relay → dispatch → worker pipeline** via the DECISION T primitive. **There is NO direct WP→PG copy path and NO second repair path.** Onboarding is a **thin delegator** to the ratified `ReconciliationService` exactly as the OPSC-S4 console actions delegate to it (DECISION V (d)); the implementation DoD (ONB-S2) **must include a write-spy proof**: zero direct `content.*` / `system.*` writes on the backfill path — projections are written only by the worker pipeline, exactly as for organic edits. WordPress-wins holds by construction (DECISION U point 3).

**(c) Queue drain during onboarding — a live worker heartbeat is a HARD PREREQUISITE.** Onboarding will **not** trigger the backfill unless a **fresh `system.worker_heartbeats` row exists** (DECISION P current-state row; freshness judged by `last_heartbeat_at` age against the same config threshold the Worker Status provider uses). Workers drain the outbox→relay→dispatch→queue pipeline **as normal** (they are the execution path — CLAUDE.md, DECISION U point 5). **There is no in-request tick drain** — the wp-admin/onboarding request never runs the worker engine inline; it only enqueues (via re-emission) and then reports derived progress. If no live worker is detected, the backfill trigger is blocked and the operator is shown worker-status + runbook guidance (never a Restart Workers action — DECISION V (f); the supervisor owns worker lifecycle).

**(d) Progress — derived on-demand (DECISION Q); completion state is one WP option; zero schema change.** Onboarding progress is **derived on demand** per DECISION Q: an expected-count scan (in-scope WordPress aggregate counts by type) compared against processed/projection counts (`content.*` projection row counts and/or `system.processed_events`), computed at read time. **No new PG persistence** — no progress table, no rollups, no time-series store. The single durable completion signal is a WordPress option **`hsp_onboarding_state`** stored in **MySQL** (WP options table); it is **not** a schema migration and adds no table/column. Its values track the onboarding lifecycle (e.g. `pending` → `preflight_ok` → `backfilling` → `complete`); the exact value enum is an ONB-S1 design detail.

**(e) Placement — `core/Onboarding/`, delegating to ratified services only.** Onboarding is a **lifecycle/setup surface**, distinct from the observability console. It lives under **`core/Onboarding/`** (core infrastructure; `HSP\Core\Onboarding\`), **not** under `core/Operations/`. This keeps DECISION V (j) intact — the Operations Console remains observability/diagnostics only, and onboarding (which *triggers* a state change via backfill) is not folded into the console's read-only surface. Any onboarding contracts live under **`core/Contracts/`** (Rule 5). Onboarding **delegates to ratified services only** (`ReconciliationService`, the migration engine, the Worker Status / heartbeat read path); it opens no PG handle of its own — provider-style reads reuse the delivery `DatabaseConnectionInterface` (DECISION K), no fifth handle (DECISION L Ruling 0), no new `pg_*` wrapper (DECISION E).

**(f) Nav gating & hard-blocking prerequisite checks.** Until `hsp_onboarding_state = complete`:
- The **Operations** and **API Playground** admin pages are **not registered / not visible** (the `AdminPageController` menu registration is gated on the completion flag); the **onboarding page is the only HSP admin surface**.
- **Prerequisite checks hard-block progression** (the operator cannot advance/trigger backfill until they pass): (1) the **`pgsql` PHP extension** is loaded; (2) the **PG connection constants** are defined in `wp-config.php` (DECISION O `HSP_PG_*`); (3) **PostgreSQL is reachable** (a live connection succeeds); (4) the **PHP version** meets the platform minimum. A failed prerequisite is a **hard block** with remediation guidance, not a warning.

> **⚠ AMENDED (v1.22, 2026-07-17 — architect ruling).** The **"required core + content migrations applied" check is moved OUT of the ONB-S1b environment-preflight and INTO ONB-S2 as a backfill prerequisite.** Rationale: the environment preflight (ONB-S1b) validates the *host environment* (extension, constants, PG reachable, PHP version) — the four checks above. Whether the delivery schema is migrated is a **content/data-readiness** concern that gates the **backfill** step (ONB-S2), not host readiness; ONB-S2 already opens the delivery handle and drives `ReconciliationService`, so the migration-state check (`system.schema_versions` / `system.module_versions`, OPEN-8 read path) is evaluated there as a hard block immediately before backfill. This changes **when** the check runs (ONB-S2, not ONB-S1b), not **whether** — it remains a hard block via the same delivery-handle read path (DECISION K reuse; no new handle/wrapper). The `MigrationsAppliedCheck` implementation shipped in ONB-S1b is retained for ONB-S2 to reuse. ONB-S1b therefore ships **four** preflight checks; ONB-S2 adds the migration check to its backfill-gate set. See the Session Map ONB-S1b / ONB-S2 rows.

> **⚠ AMENDED (v1.23, 2026-07-18 — architect ruling; self-remediating gates).** The two ONB-S2 backfill prerequisite gates — **(1) migrations applied** and **(2) processing pipeline advancing** (the DECISION X (4) Option-C worker gate) — become **self-remediating in-product**, so a **zero-configuration fresh install** completes onboarding with **NO manual CLI/engine step** (ADR-054 **Principle 8** — Zero-Configuration Operation; "an operator installs the plugin and synchronization begins"). This changes how a blocked gate is *satisfied*, **not whether it blocks** — each gate remains a **hard block** and merely gains an in-product action; nothing is projected until the gate genuinely passes (no bypass, no weakening).
>
> - **Migrations-applied gate → `POST hsp/v1/onboarding/migrate`.** A new WPCS-guarded endpoint (nonce + capability + sanitize at the JSON boundary — DECISION W (a) / V (b)) that **applies the outstanding core + content migrations through the EXISTING migration engine** (`MigrationRunner`) over the **DECISION W (e) delegate list** (core migrations + module migrations collected via the module registry's declarative `getMigrations()` — OPEN-9; Rule 5 — core imports no module migration class). It is a **thin delegator** (`core/Onboarding/MigrationApplier`): **no new engine, no new DDL, no new schema, no new `pg_*` wrapper** (DECISION E — the migration engine keeps its own DDL abstraction), **no new PG handle** (DECISION L Ruling 0). **Guarded on the four ONB-S1b environment preflight checks** (pgsql ext, PG constants, PG reachable, PHP version) — a failing env preflight returns 409; the migrations gate (`MigrationsAppliedCheck`) is re-evaluated after and returned. Idempotent (the engine skips already-applied migrations).
> - **Processing-pipeline gate → `POST hsp/v1/onboarding/spawn-worker`.** A new WPCS-guarded endpoint (`core/Onboarding/WorkerCronSpawner`) that ensures the processing-cycle cron is scheduled and issues a **NON-BLOCKING WP-Cron spawn** (`spawn_cron()` loopback) so a bounded Processing Engine cycle runs and a heartbeat appears. **There is NO in-request tick drain — DECISION W (c) is intact**: the cycle runs **only inside WP-Cron execution**, never inline in the admin request. When `DISABLE_WP_CRON` is set, no spawn is issued and an explicit **WP-Cron-only** warning is surfaced (`wp cron event run --due-now`) — **never** supervisor / systemd / daemon / "restart the worker" wording (ADR-054 §5; DECISION V (f)).
> - **Plugin lifecycle (OPEN-9).** `Application::activate()` / `upgrade()` (and the module `activate()`/`upgrade()` hooks) **attempt the pending migrations through the same shared engine IFF the `HSP_PG_*` constants are defined AND PostgreSQL is reachable**, and are a **silent no-op otherwise** — **activation must never fatal on an unconfigured site** (the connection-free `PgConstantsCheck` gates first; the applier catches all failures). This is one migration path (the shared engine), not a second one; the onboarding migrate endpoint is the in-product path for sites configured *after* activation.
>
> The self-remediation actions are **thin delegators to ratified infrastructure** (the migration engine; the processing-cycle cron) — they introduce **no second repair path**, honour the DECISION W constraints verbatim (no new handle / `pg_*` wrapper / schema / event-type; no in-request drain), and keep the gates' hard-block semantics. Recorded as an architect ruling for ONB-S2 (2026-07-18). See the Session Map ONB-S2 row and `core/Onboarding/`.

**Constraints (binding).** No new PostgreSQL handle (DECISION L Ruling 0 — topology frozen at four; onboarding reuses existing runtime/delivery handles). No new raw `pg_*` wrapper (DECISION E). No new event-type contracts (backfill reuses the OPEN-1 `.updated`/`.deleted` re-emission via DECISION U). No schema migration (completion state is a WP option; progress is derived). No second repair path (DECISION U point 2 / DECISION V (d)). Module isolation (Rule 5): onboarding orchestration lives in `core/`; any WordPress-state read reuses the existing `WpReconciliationSourceInterface` (DECISION U D6) / `ReplayEmitterInterface` (DECISION T) contracts implemented in the Content module — core never imports a module.

**Rationale.** Reusing `ReconciliationService` full-reconciliation for the initial backfill means first-run population and steady-state drift repair share **one** code path and one correctness proof (the DECISION U write-spy / WordPress-wins guarantees), rather than inventing a bespoke bulk-copy that would be a second repair path and a fresh source of divergence. Requiring a live worker before backfill keeps "workers are the execution path" true and avoids long-running admin requests and the coupling of wp-admin to worker execution. Deriving progress and storing only a single WP option honors DECISION Q's zero-new-persistence discipline and needs no migration. Gating the console behind onboarding completion and hard-blocking on prerequisites prevents an operator from reaching a half-configured console that would surface confusing errors (e.g. PG unreachable). Adopting React+shadcn for the admin UI is the architect's chosen resolution of DECISION V (a)'s deferred frontend question; committing `dist/` keeps the production deploy a file copy (no host toolchain) while giving the richer client, and confining WPCS to the JSON endpoints the client calls keeps the security boundary exactly where untrusted input crosses into the platform.

---

### DECISION X — ADR-054 Alignment Rulings (Per-Cycle Identity, Heartbeat Status, `WorkerInterface` Contract, Backfill Prerequisite)

| Field | Value |
|---|---|
| **Status** | Accepted |
| **Date** | 2026-07-17 |
| **Session** | ALIGN-S1 (recorded at session STEP 0; ruling given as architect ruling 2026-07-17) |
| **Authority** | Architect ruling 2026-07-17; ADR-054 (WP-Cron Processing Engine — authoritative execution model); Doc 8 v2.0 §8/§9/§15/§16/§24/§25; DECISION P (heartbeat current-state schema — reused verbatim); DECISION Q (metrics/progress without persistence); DECISION W (c) (backfill live-heartbeat prerequisite — reinterpreted here); DECISION R (config-driven cadence precedent); ADR-012 (constructor injection) |
| **Resolves** | FLAG-ALIGN-1 (a)/(b)/(c); FLAG-ALIGN-2 |
| **Amends** | `core/Contracts/WorkerInterface.php` surface (Phase-0-frozen contract — corrected here, not by mid-implementation choice); Doc 8 v2.0 heartbeat-identity + status-set interpretation (confirms §8/§16) |

The ALIGN-S0 audit (`docs/ADR054-IMPLEMENTATION-AUDIT.md`) surfaced four needed-ruling items that gate the ADR-054 alignment-implementation work. The architect ruled as follows.

**(1) Worker identity = Option A — fresh UUIDv7 per cycle (resolves FLAG-ALIGN-1 (a)).** Each WP-Cron **processing cycle mints a FRESH UUIDv7** `worker_id` at cycle bootstrap (Doc 8 v2.0 §24). A `system.worker_heartbeats` row therefore represents a **processing-cycle execution**, NOT a long-lived daemon identity. The table **accumulates one row per recent cycle** (per `worker_type` stage); maintenance may **prune stale rows under existing retention** (an age sweep — no new schema, no history table beyond what DECISION P already allows as current-state rows keyed by the per-cycle UUID). This cardinality is precisely what makes the ADR-054 §6/§17 reinterpretation coherent: `worker_count` = distinct processing-component rows that heartbeated within the freshness window (cycles/stages that ran recently), and the ADR-054 §17/§27 **cycles_completed / avg_cycle_duration** metrics become **derivable on demand with zero persistence** (DECISION Q) by counting/averaging recent-cycle rows. (The prior implementation's "self-assigned once at construction … never changes during the worker lifetime" is superseded.)

**(2) Heartbeat status set = `'running'`/`'idle'` only (resolves FLAG-ALIGN-1 (b)).** In v1.x the heartbeat `status` value set is **exactly** `'running'` and `'idle'` (Doc 8 v2.0 §16). `'processing'` → **`'running'`**; **`'shutdown'` is removed** (a cycle terminates normally at its batch/budget boundary — there is no shutdown signal to record). The `system.worker_heartbeats` **schema is unchanged** (DECISION P not re-migrated); only the emitted `status` string values change.

**(3) `WorkerInterface` contract shape = Option A — architectural correction (resolves FLAG-ALIGN-1 (c)).** `WorkerInterface` is an **internal core contract, not a module-implemented one** (no module implements it; it is core-owned processing infrastructure). ADR-054 §8 retains the class *names* but the contract *surface* is a Phase-0-frozen contract whose disposition is an architect ruling. **Ruling: `run()` and `shutdown()` are REMOVED.** The corrected contract expresses **exactly one bounded processing cycle**: execute one cycle, honour the configured per-stage batch limits + execution-time budget, and **return a processing result describing the completed cycle** (stages run, batch counts, whether the budget was hit, whether any work was done). The exact method signature + result type are the implementation session's to name; the **intent is fixed** by this ruling. This is an architectural *correction* of a contract that encoded the superseded daemon lifecycle — not a new feature.

**(4) Backfill prerequisite = Option C — scheduled cron event AND recent heartbeat (resolves FLAG-ALIGN-2).** Under the cycle model, the DECISION W (c) "live worker heartbeat is a hard prerequisite" for backfill is satisfied by **BOTH**: (i) the **processing-cycle WP-Cron event is scheduled** (`wp_next_scheduled` truthy — cron is set up to advance the pipeline) **AND** (ii) a **recent processing heartbeat** exists (a cycle ran within the offline threshold — the existing DECISION P age read). Remediation text references **only WP-Cron** — "ensure WP-Cron is firing", "run `wp cron event run --due-now`", "check the processing cron is scheduled" — and **never** supervisor / systemd / daemon / "restart the worker" (there is no supervised process under ADR-054 §5). The `BackfillService` no-in-request-drain design and the re-emission repair path are **unchanged**; only the gate's meaning + remediation change. **This ruling is implemented in ALIGN-S2** (the `BackfillGate` realignment), recorded here now.

**Maintenance recovery cadence under the cycle model (records the DECISION R reinterpretation — ALIGN-S1).** DECISION R (v1.16, OPS-S1) shipped the visibility-timeout recovery sweep on a **config-driven** cadence, throttled by an in-memory `$lastSweepAt` gate keyed on `config/worker.php` → `maintenance.recovery_interval_seconds` (default 30 s). That in-memory gate assumed a continuously-ticking daemon and does **not** survive a per-cron-cycle process exit (every fresh cycle would see `$lastSweepAt = null` and always sweep). Under ADR-054 the maintenance sweep therefore runs **once per processing cycle**, and the **cadence is the processing cron interval** (`config/worker.php` → `processing.interval_seconds`, itself config-driven — the DECISION R config-driven property is **preserved**, only relocated from a per-strategy key to the cron cadence). Consequently **`maintenance.recovery_interval_seconds` is now inert** — retained in the config file for back-compat/operator reference but **read by no code** (superseded). No new persistence, no schema change (DECISION Q / ADR-054 §9). DECISION R's *driver* (`MaintenanceWorkerStrategy` invoking `requeueTimedOut()`) and *config-driven* discipline are both intact; only the throttle mechanism changed from an in-process interval gate to the cron-cycle cadence.

**Constraints (binding — inherited from ADR-054 §9).** No new locking mechanism; no fifth PG handle; no new `pg_*` wrapper; **no schema change / no migration** (heartbeat schema reused verbatim per DECISION P; completion/progress unchanged per DECISION W (d)); the per-event pipeline, DECISION 3 commit, SKIP LOCKED claiming, visibility timeout, replay, and reconciliation are preserved verbatim.

**Rationale.** Fresh-UUID-per-cycle is the only identity model consistent with heartbeats-as-cycle-freshness (ADR-054 §5): a stable per-stage id would give one current-state row and no per-cycle history, forcing new persistence or log-derived metrics for cycles_completed/avg_cycle_duration — both prohibited by DECISION Q. Reducing the status set to `running`/`idle` matches "a cycle either ran or its heartbeat is stale" (§16) and removes the daemon-only `shutdown` state. Removing `run()`/`shutdown()` from `WorkerInterface` corrects a contract that mandated the daemon lifecycle ADR-054 abolished; returning a cycle result gives the trigger + tests a bounded, inspectable outcome. Requiring both a scheduled cron event and a recent heartbeat for backfill is the faithful cycle-model translation of "a worker is draining" — a heartbeat alone could be a one-off manual cycle with no recurring schedule, and a scheduled event alone could be firing into a stalled runtime; together they mean "the pipeline is actually being advanced on a cadence."

---

### ADR-047 — Operations Console as Core Infrastructure

| Field | Value |
|---|---|
| **Status** | Accepted (ratified by DECISION V, 2026-07-15) |
| **Source** | Doc 12 §1, §3, §19 |

The Operations Console is a **core infrastructure** subsystem, not a collection of ad-hoc WordPress admin pages. Core owns the console's infrastructure — registries, providers, services, aggregation, and rendering — under `core/Operations/`; modules own their implementations (pages, widgets, diagnostics, metrics, actions, endpoints) behind core-owned contracts. Module→module dependencies are prohibited (Rule 5); future extraction of the console remains possible. As amended by DECISION V: the subtree is `core/Operations/` (lowercase); its contracts live under `core/Contracts/Operations/`; the MVP is server-rendered PHP with no node toolchain.

### ADR-048 — Registry-Driven Administration

| Field | Value |
|---|---|
| **Status** | Accepted (ratified by DECISION V, 2026-07-15) |
| **Source** | Doc 12 §4, §5 |

Console capabilities are discovered through **explicit-registration registries** (Page, Navigation, Widget, Action, Asset), mirroring the platform's existing event/adapter registry model (no reflection, no hardcoding). Registries discover *capabilities*; providers supply *runtime data*. Nothing about the console's pages, widgets, endpoints, or actions is hardcoded in core.

### ADR-049 — Unified Diagnostics

| Field | Value |
|---|---|
| **Status** | Accepted (ratified by DECISION V, 2026-07-15) |
| **Source** | Doc 12 §11, §12 |

Diagnostics are contributed by **Diagnostics Providers** (Health, Metrics, Configuration/Environment Validation, Version, Warnings, Recommendations) with a common severity scale (OK/Info/Warning/Error/Critical). **Historical health storage is out of scope**; the console reports **current operational state only.** Consistent with DECISION P (single current-state heartbeat row) and DECISION Q (no metrics persistence). As amended by DECISION V: all metric-bearing diagnostics are derived on-demand — zero new persistence.

### ADR-050 — Delivery API Validation

| Field | Value |
|---|---|
| **Status** | Accepted (ratified by DECISION V, 2026-07-15) |
| **Source** | Doc 12 §15 |

The API Playground validates the delivery API by exercising the published `hsp/v1` endpoints (DECISION N/F) from the admin UI: Endpoint Explorer, Request Builder, Request Execution, Response Viewer, driven by registered endpoint metadata. Execution hits the **live delivery API contract** (Rule 6 — consumers depend on the API contract, not internal schemas). Out-of-MVP display categories (Commerce/Search) are placeholders and must not pull WooCommerce/OpenSearch into scope.

### ADR-051 — Operational Actions

| Field | Value |
|---|---|
| **Status** | **HELD — recorded, NOT citable as authority** (per DECISION V / FLAG-9 B) |
| **Source** | Doc 12 §17 |
| **Blocked on** | Incorporation of FLAG-7 (Flush Queue removed) and FLAG-8 (no Restart Workers) into this ADR's text |

ADR-051 governs registry-driven Operational Actions. It is **HELD**: Doc 12 §17 as written still lists **Flush Queue** and **Restart Workers**, both of which DECISION V removes (FLAG-7 A / FLAG-8 C-modified). Until this ADR's text is revised to (1) drop Flush Queue and any destructive-flush semantic, (2) drop Restart Workers in favour of status + heartbeat + runbook links, and (3) constrain the surviving Replay/Reconcile actions to thin delegators over the DECISION T/U services (FLAG-6 A), **no session may cite ADR-051 as authority.** OPSC-S4 cites DECISION V (d)+(e)+(f) directly, not ADR-051.

### ADR-052 — Registry-Driven Operations Console

| Field | Value |
|---|---|
| **Status** | Accepted (ratified by DECISION V, 2026-07-15) |
| **Source** | Doc 12 §20 (ADR-052) |

All Operations Console capabilities are discovered through registries and provider contracts. Core never hardcodes pages, widgets, endpoints, diagnostics, metrics, or operational actions. (Reaffirms ADR-048 at the whole-console level.)

### ADR-053 — Operations Console is Read-Only by Default

| Field | Value |
|---|---|
| **Status** | Accepted (ratified by DECISION V, 2026-07-15) |
| **Source** | Doc 12 §20 (ADR-053) |

The console is **observational by default.** State-changing functionality is implemented **only** as registered Operational Actions protected by capability checks, confirmation, and audit — and, per DECISION V (d)+(j), only as thin delegators to the platform's re-emission primitives. This keeps the console a diagnostics interface (DECISION V (j)); administrative operations remain explicit, discoverable, and auditable.

---

### ADR-054 — Background Processing via WP-Cron Processing Engine (Supersedes ADR-024; Amends ADR-035/ADR-036)

| Field | Value |
|---|---|
| **Status** | Accepted |
| **Date** | 2026-07-17 |
| **Session** | ARCH-DOC8-V2 (architecture-only) |
| **Authority** | Product ruling 2026-07-17 (HSP v1.x supports ONLY WP-Cron for background execution); Doc 8 v2.0; ADR-024 (execution model — superseded here); ADR-035 (shared engine + strategies — amended here); ADR-036 (stateless workers — amended here); ADR-037 (traceability — preserved); OPEN-4 (visibility timeout / SKIP LOCKED claiming); DECISION 3 (three-op single-PG-transaction commit); DECISION J (Resolve-stage stale guard); DECISION L Ruling 0 (four-connection topology); DECISION P (heartbeat current-state); DECISION Q (metrics without persistence); DECISION R (config-driven recovery cadence); DECISION T/U (replay/reconciliation via re-emission); CLAUDE.md Rules 3/4/8 |
| **Supersedes** | **ADR-024** (execution-model decision) |
| **Amends** | **ADR-035**, **ADR-036** (daemon-worker wording only); Doc 8 (rewritten to v2.0) |
| **Preserves** | ADR-037; the full event pipeline; DECISION 3/J/L/P/Q/R/T/U guarantees |

**Product ruling (authoritative).** HSP **v1.x supports ONLY WP-Cron** for background execution. There are **no Supervisor, systemd, Docker workers, CLI daemons, or continuously running processes** in the v1.x execution model. **The execution *mechanism* changes; nothing else does** — the event pipeline, per-event processing pipeline, idempotency, ordering, replay, reconciliation, and tracing are all unchanged.

**1. EXECUTION MODEL — WP-Cron Processing Engine.** Background processing is advanced by a **WP-Cron-triggered Processing Engine** that runs **one bounded, stateless cycle per invocation** and exits cleanly: `WP-Cron tick → bootstrap → relay batch → dispatch batch → projection batch → maintenance (as scheduled) → persist operational metrics → clean exit`. Each stage is a **bounded batch** (config-driven max size per stage; `config/worker.php` keys `processing.relay_batch_size` / `dispatch_batch_size` / `projection_batch_size`), not a loop-to-empty. A cycle carries a config-driven **execution-time budget** (`processing.cycle_time_budget_seconds`) set well inside the environment's PHP `max_execution_time`; on reaching the budget the engine stops claiming new work, finishes the in-flight event's single transaction (DECISION 3), records metrics, and exits. WP-Cron may itself be driven by a system cron invoking `wp cron event run --due-now` for reliable cadence — that is a *trigger* for WP-Cron, not a daemon; each invocation still runs exactly one bounded cycle and exits.

**2. STATELESS BETWEEN EXECUTIONS + CONTINUATION.** A cycle holds no state between invocations. All continuation state is the **residual durable state** already in the pipeline: unrelayed `wp_hsp_outbox` rows (`status='pending'`), undispatched `system.events` (absent from `system.queue_jobs`), and unclaimed/expired `system.queue_jobs`. A backlog larger than one cycle drains across **successive** cron executions — the next tick re-derives exactly what remains from those tables. This extends ADR-036 (stateless workers) to "stateless between cron executions."

**3. CONCURRENCY — overlapping cron cycles are safe via EXISTING guarantees only; NO new locking mechanism.** Two cron cycles that overlap are safe because of three mechanisms already frozen in the architecture, and **no new lock (no cron mutex, no advisory lock, no WP transient single-flight) is introduced**:
- **`FOR UPDATE SKIP LOCKED`** on every claim (relay `wp_hsp_outbox` — OPEN-6; dispatch anti-join — DECISION L (c); projection `system.queue_jobs` — OPEN-4). Two overlapping cycles claim **disjoint** job sets and neither blocks the other (proven for concurrent claimants in GATE-S2 criterion 1). Duplicate dispatch is a no-op via `UNIQUE(event_id)` + `ON CONFLICT DO NOTHING` (DECISION L (d)).
- **Aggregate-version ordering** (DECISION J) — Resolve-stage non-locking SELECT + in-transaction `FOR UPDATE` + `GREATEST()` monotonic upsert closes the Resolve→write TOCTOU window if two cycles touch the same aggregate; a superseded event is acked with zero writes.
- **Visibility timeout** (OPEN-4 / DECISION R) — a cycle **killed mid-batch** by `max_execution_time` (or deploy/VPS restart) before the DECISION 3 commit leaves **no partial projection** (single-transaction rollback); the claimed job's `visibility_timeout_at` expires and `MaintenanceWorkerStrategy` requeues it, and the **next** cycle re-claims and reprojects. A committed-but-unacked job re-delivered next cycle is absorbed idempotently (DECISION J guard / `processed_events` dedup).

**Explicit gap check:** the two required scenarios (two overlapping cycles claiming from the same queue; a cycle killed mid-batch recovering via visibility timeout on the next cycle) are **fully covered** by SKIP LOCKED + aggregate versioning + visibility timeout + DECISION 3 atomicity. **No gap requiring a new locking mechanism was found.** If a future workload proves a gap, a new DECISION is required before any lock is added — it must not be improvised.

**4. SCALING.** Throughput scales by **cron frequency + per-stage batch size**, not by a worker pool. Overlap being safe (point 3) means a tighter cron schedule increases throughput without corruption. The <30s sync SLA and <60s queue-lag target are met by tuning cadence and batch size.

**5. HEALTH = PROCESSING FRESHNESS/PROGRESS (not daemon liveness).** There is no daemon to be up/down. Health is the freshness and progress of cycles, derived on demand (DECISION P/Q): last cron execution, last successful cycle, per-stage last-run, queue depth, oldest pending job, processing lag. A **stalled pipeline** = heartbeat age exceeds a config threshold **while** queue depth is non-zero (work exists but no recent cycle advanced it). The DECISION P `system.worker_heartbeats` current-state row and its age-check mechanism are reused **verbatim** (no schema change); only the interpretation changes from "a daemon crashed" to "cycles are not advancing." A missing heartbeat is NOT a dead daemon to restart — there is nothing to restart; it means "cron may not be firing" → runbook.

**6. METRICS.** `worker_uptime` and `restart_count` are **removed** (no process uptime; nothing restarts). Replaced by: cycles completed, avg cycle duration, per-stage throughput, queue backlog, processing lag, oldest-pending-job age — all derived on demand or emitted as structured logs (DECISION Q; no metrics table). `worker_count` is reinterpreted as the count of distinct processing-component rows that heartbeated within the freshness window (cycles/stages that ran recently), not a live-daemon population.

**7. RECOVERY.** Next cron execution (re-derives residual durable work) + queue durability + visibility timeout (DECISION R) + retries (ADR-022) + DLQ (OPEN-3/DECISION A/S) + replay (DECISION T) + reconciliation (DECISION U). A gap in cron firing delays processing but **loses no event** — all continuation state is durable. This is the exact recovery set of Doc 8 v1.0 §26 with "next cron execution" replacing "supervised worker restart" as the primary re-drive.

**8. NAMING (no rename in v1.x).** The implementation class names `WorkerEngine`, `RelayWorkerStrategy`, `EventWorkerStrategy`, `ReconciliationWorkerStrategy`, `MaintenanceWorkerStrategy`, `WorkerStrategyInterface`, `WorkerExecutionContext`, and `HeartbeatPublisherInterface` are **retained** and defined in Doc 8 v2.0 as **processing components invoked by WP-Cron**, not daemons. ADR-054 authorizes this naming continuity; **no rename is proposed** (churn-only). The `system.worker_heartbeats` table and its `worker_id`/`worker_type`/`status`/`last_heartbeat_at`/`started_at` columns are unchanged (DECISION P); `worker_id` is now a per-cycle UUIDv7 processing-component identity. **Table-name continuity (architect ruling, Doc 8 v2.0 §15):** `system.worker_heartbeats` is retained in v1.x for implementation continuity and migration stability even though it now represents processing-cycle health; a **future major version** may add a schema migration renaming it to `system.processing_heartbeats` (or equivalent). No rename/migration is done in v1.x.

**8b. PRODUCT OBJECTIVE — Zero-Configuration Operation (Doc 8 v2.0 Principle 8 + §2b "Why WP-Cron?").** The product goal behind this ruling is elevated to an explicit architectural principle: the platform must operate immediately after plugin activation **without OS services, external supervisors, container orchestration, or manual infrastructure configuration** — install from the WordPress Plugins screen, activate, and synchronization begins. WP-Cron is the *only* supported execution mechanism in v1.x precisely because it is what makes this achievable on any host that runs WordPress. Future versions may add execution drivers via their own ADRs **without changing the processing architecture** (the engine and every guarantee here are trigger-agnostic).

**9. WHAT ADR-054 DOES NOT CHANGE.** The Outbox→Relay→Dispatcher→Queue→Processing pipeline; the per-event pipeline (Claim→Load→Context→Validate→Resolve→Execute→Commit→Ack); DECISION 3 three-op single-PG-transaction commit; DECISION J stale guard; DECISION L Ruling 0 four-connection topology (**no fifth handle; no new `pg_*` wrapper** — a cron cycle uses the same runtime/delivery handles); DECISION P heartbeat schema; DECISION Q derived-metrics discipline; DECISION T/U re-emission repair; ADR-037 correlation/causation IDs; at-least-once + idempotent redelivery (ADR-036 correctness); module isolation (Rule 5). **No schema migration** is owed by this ruling (config keys only). No new event contracts.

**Relationship to CLAUDE.md.** CLAUDE.md's "Workers run under systemd / Supervisor / container runtime in production; WP-Cron is a fallback only" statement is a Build/Test/Run note that **conflicts with this product ruling** and is flagged for update in the conflict report (§ below); ADR-054 (this record) wins by precedence (line 3). Reconciliation's existing WP-Cron *trigger* authorization (DECISION U point 5) is consistent with — and generalized by — this ruling: under v1.x, WP-Cron is the trigger for **all** background processing, not only recovery jobs.

**Rationale.** A CMS-sync plugin deployed on ordinary WordPress hosting cannot assume a process supervisor; requiring systemd/Supervisor/daemon workers (ADR-024) narrows the deployable environments and adds an operational surface the plugin cannot own. A bounded WP-Cron cycle runs everywhere WordPress runs, exits before `max_execution_time`, and — because every correctness guarantee the pipeline relies on (SKIP LOCKED, aggregate versioning, visibility timeout, single-transaction commit) is independent of *how* processing is triggered — delivers the same at-least-once, ordered, idempotent, replayable, reconcilable behaviour as a daemon would, with overlap as a throughput lever rather than a hazard. Retaining the class names avoids a churn-only rename while Doc 8 v2.0 redefines them semantically.

---

#### ADR-054 Conflict Report — daemon / long-running-worker assumptions in other docs (report only; those docs are NOT edited by this ruling)

The following sections assume long-running/CLI-daemon workers, external process supervision, or worker-liveness health/metrics, and **conflict with ADR-054 (WP-Cron only, v1.x)**. Per precedence (line 3), ADR-054 wins; these are recorded for a future doc-reconciliation session. No fix is applied here beyond Doc 8 (v2.0) and this record.

**Doc 1 — Technical Architecture Specification** (`docs/01-…`)
- §"Core Platform" (line ~208) and the pipeline diagram (line ~280) name **"Worker Infrastructure"/"Worker"** as the execution component. *Severity: LOW — nomenclature only; "Worker" is the retained processing-component name (ADR-054 §8). No daemon/supervisor assumption stated. Reconcile terminology only.*

**Doc 2 — Plugin Folder Structure & Code Organization** (`docs/02-…`)
- §19 "Worker Infrastructure" (line ~482): `core/Workers/` subtree `Consumers/ Scheduling/ Recovery/ Monitoring/ Contracts/`, and `WorkerInterface.php` (line ~187). *Severity: LOW — folder/interface names retained (ADR-054 §8). `Scheduling/`+`Recovery/` are actually consistent with a cron-triggered model. No worker "entry point"/daemon binary is defined in Doc 2 (the brief's "worker entry points in Doc 2" concern resolves to these structural names — no `bin/worker` daemon script exists). Reconcile terminology only.*

**Doc 3 — Database Design & Persistence Architecture** (`docs/03-…`)
- §21 `system.queue_jobs` (line ~838) includes `started_at`, and Doc 3 has **no `worker_heartbeats` table** of its own (the heartbeat schema the brief refers to lives in Doc 4 §19 / Doc 8 v1.0 §15 and was frozen by DECISION P). *Severity: LOW — `system.queue_jobs.started_at` is per-job claim time, fully compatible with cron cycles; the DECISION P heartbeat schema is unchanged (reinterpreted, not re-schema'd). No conflict requiring a Doc 3 edit; noted for completeness because the brief flagged "worker heartbeat schema in Doc 3."*

**Doc 4 — Queue & Event Processing Architecture** (`docs/04-…`)
- **§20 ADR-024 "Worker Execution Model"** (line ~684): *Primary = CLI Workers (WP-CLI / Supervisor / Systemd); Fallback = WP-Cron.* **DIRECT CONFLICT** — ADR-054 inverts this (WP-Cron only for v1.x). *Severity: HIGH. This is the decision ADR-054 supersedes.*
- **§19 "Worker Heartbeats"** (line ~654): workers *publish* `worker_id/status/current_job/memory_usage/started_at/last_heartbeat_at`. *Severity: MEDIUM — heartbeat is reinterpreted as per-cycle current-state (DECISION P / ADR-054 §5); "workers publish continuously" wording conflicts. `current_job`/`memory_usage` are daemon-liveness framing.*
- §30 Approval Checklist "CLI Worker Strategy" (line ~1049) and §29 "Horizontal Scaling" (line ~1016, "Worker 1..N consume concurrently"). *Severity: MEDIUM — horizontal *process* scaling superseded by cron-frequency+batch scaling (ADR-054 §4); overlapping cycles preserve the concurrency guarantee.*

**Doc 5 — Event Architecture & Contract Design** (`docs/05-…`)
- No daemon/supervisor/worker-liveness assumptions found. Event immutability (§26) is preserved. *No conflict.*

**Doc 7 — Adapter Architecture & Delivery Projection Design** (`docs/07-…`)
- §19 "Bulk Operations" / `bulkPersist()` (lines ~640–679) references reconciliation/replay bulk paths. *Severity: NONE — orthogonal to execution model; `bulkPersist()` is a fail-fast stub in Phase 1A (FLAG-P1AS4-3) and unrelated to trigger. No conflict.*

**Doc 9 — Delivery API & Consumption Architecture** (`docs/09-…`)
- No worker/daemon/supervisor assumptions found (consumer-path doc). *No conflict.*

**Doc 10 — Operations, Deployment & Runtime Architecture** (`docs/10-…`) — **most conflict-heavy**
- **§7 "Worker Execution Strategy"** (line ~232): *"CLI workers are required … running under systemd / Supervisor / Container Runtime"; "WP-Cron is not the primary execution mechanism."* **DIRECT CONFLICT** with ADR-054. *Severity: HIGH.*
- **§24 "Worker Availability" target 99.9%** (line ~768). **CONFLICT** — there is no persistent worker to have an availability figure; replace with a **processing-freshness / cycle-cadence** target (ADR-054 §5). *Severity: HIGH.*
- **§27 Deployment Tooling Boundary** (line ~836): supported assets include **"systemd Templates", "Supervisor Templates", "Worker Launch Scripts"** (lines ~849/851/859). **CONFLICT** — these presuppose supervised daemons; not authorized under v1.x. *Severity: HIGH.*
- **§28 Infrastructure Compatibility Matrix** (line ~882): "Supported With Limitations: Shared Hosting **only when … Long-running workers possible**" (line ~907) and "Unsupported: **Environments Without Process Supervision**" (line ~916). **CONFLICT** — ADR-054 makes shared hosting *without* supervision a first-class target; "process supervision" must not be an unsupported-gating requirement. *Severity: HIGH.*
- §4 Topology C "Horizontally Scaled — Multiple Workers" (line ~160) and §5 Topology Migration (line ~180). *Severity: MEDIUM — reframe "multiple workers" as cron-frequency/batch scaling.*
- §20 "Worker Monitoring" (line ~660): `uptime`, `heartbeat_age` as worker-liveness. *Severity: MEDIUM — `uptime` removed; `heartbeat_age` reinterpreted as cycle-freshness (ADR-054 §5–6).*
- §23 Alerting "Worker Offline" (line ~723). *Severity: MEDIUM — becomes "processing stalled: heartbeat stale while queue non-empty."*
- §26 Operational Runbooks "Worker Failure" (line ~810). *Severity: LOW — becomes "cron not firing / processing stalled" runbook.*

**Doc 11 — Development Roadmap & Platform Evolution Strategy** (`docs/11-…`)
- §"Scalability Validation — Multiple Worker Processes" (line ~489). *Severity: LOW — this gate criterion is satisfied by concurrent claimants (GATE-S2, already PASS); reframe as "overlapping cron cycles / concurrent claimants." No re-gate needed — the SKIP LOCKED proof already covers it.*
- Header dependency "Document 8 — Worker Architecture & Execution Model" (line ~17). *Severity: LOW — update title to "Background Processing & Execution Architecture" when Doc 11 is next revised.*
- Phase 3 "Operational Hardening / Improved Monitoring / Alerting" (line ~588). *Severity: NONE — compatible; monitoring/alerting reframed per ADR-054 §5–6.*

**CLAUDE.md (project instructions)** — not one of Docs 1–11 but authoritative and conflicting:
- "Workers run under **systemd / Supervisor / container runtime** in production. WP-Cron is a fallback only (recovery jobs, safety checks) — never the primary execution path." **DIRECT CONFLICT** with the product ruling. *Severity: HIGH — flagged for update; ADR-054 wins by precedence (line 3). Also: the Build/Test/Run table's "Run worker (production) — WP-CLI command — TBD" row should be reframed to a WP-Cron trigger.*
- **IMPLEMENTATION_PLAN.md §5b / §4** (Operability & Scalability validation) and any worker-launch operational notes similarly assume daemons; flagged for the same future reconciliation session.

*Recommendation:* a follow-up docs-only reconciliation session should apply ADR-054's wording to Doc 4 §19/§20, Doc 10 §7/§24/§27/§28/§20/§23, Doc 11's Doc-8 title + Scalability wording, and CLAUDE.md's worker-execution note. This session edits **only** Doc 8 (→ v2.0), this ADR record, and STATUS.md, per the ARCH-DOC8-V2 scope.

> **APPLIED 2026-09-05 (DOC-RECON-S1, docs-only) — FLAG-DOC8V2-1 CLOSED.** The reconciliation above
> was carried out: **Doc 4 → v1.1** (header amendment note; §19 heartbeat semantics banner; §20
> ADR-024 status flipped to *SUPERSEDED by ADR-054* with the original decision/reasoning retained
> verbatim as history; §29 horizontal scaling reframed as overlapping cycles; §30 checklist item
> corrected), **Doc 10 → v1.1** (header amendment note + Doc-8 title; §4/§5 topologies; §7 rewritten
> to WP-Cron-only with the v1.0 text retained as quoted history; §20 `uptime` removed and
> `heartbeat_age` reframed as cycle freshness; §23 "Worker Offline" → "Processing Stalled"; §24
> "Worker Availability 99.9%" → processing-freshness target; §26 "Worker Failure" → "Processing
> Stalled / Cron Not Firing"; §27 systemd/Supervisor templates + worker launch scripts removed in
> favour of an optional system-cron trigger example; §28 shared hosting without CLI/supervision
> promoted to first-class supported and the CLI/supervision unsupported-gates replaced by
> `pgsql`/PostgreSQL/WP-Cron-trigger gates), **Doc 11 → v1.1** (header amendment note + Doc-8 title;
> Scalability Validation "Multiple Worker Processes" → concurrent claimants / overlapping cycles;
> Operations Console "Restart Workers" note de-supervisored). **CLAUDE.md** was already reconciled
> by the 2026-07-20 rewrite (no daemon wording remains). No ADR was re-opened and no new ruling was
> made — ADR-054 was already authoritative; this only propagated its wording. No production or test
> code was touched.

---

#### Superseded / Amended ADR status (history preserved — recorded here, not deleted from Docs 4/8)

The original ADR bodies remain in **Doc 4** (ADR-024) and **Doc 8 v1.0** (ADR-035, ADR-036); Doc 8 is rewritten to v2.0 and carries the amended ADR-035 wording inline. Their status is recorded authoritatively here:

- **ADR-024 — Worker Execution Model (CLI Workers primary; WP-Cron fallback).** Status: **SUPERSEDED by ADR-054** (2026-07-17). The v1.x execution model is **WP-Cron only**; CLI-daemon-primary is no longer the ruling. ADR-024's text is retained in Doc 4 §20 as history and must not be cited as current authority for the execution mechanism.
- **ADR-035 — Shared Worker Engine + Specialized Worker Strategies.** Status: **AMENDED by ADR-054** (2026-07-17). The shared-engine + specialized-strategy structure is **retained**; only the *invocation model* is amended (supervisor-launched daemon → bounded WP-Cron-triggered cycle). See Doc 8 v2.0 §5 for the amended wording.
- **ADR-036 — Stateless Worker Design.** Status: **AMENDED (extended) by ADR-054** (2026-07-17). Stateless-worker correctness is **retained and strengthened** to "stateless **between cron executions**." The consequence list "workers may be restarted / recycled / replaced / horizontally scaled" is reframed: there is no daemon to restart/recycle — each cron cycle starts fresh and exits; recycling is a removed no-op concept (Doc 8 v2.0 §13). Horizontal *process* scaling is replaced by cron-frequency + batch-size scaling (Doc 8 v2.0 §10).
- **ADR-037 — Event Traceability (correlation/causation IDs).** Status: **PRESERVED unchanged** by ADR-054.

---

### ADR-055 — OpenAPI Specification, Registry-Generated

| Field | Value |
|---|---|
| **Status** | Accepted |
| **Date** | 2026-07-20 |
| **Session** | OAPI-S1 (interstitial — inserted BEFORE P1B-S0) |
| **Authority** | Doc 12 §15 (endpoint metadata registry — API Playground); Doc 9 §6 (module API ownership), §7 (versioning from day one), §13 (cursor pagination), §22 (public + authenticated endpoints), §26 (contract lifecycle / deprecation); ADR-048 / ADR-052 (registry-driven, explicit registration); ADR-050 (delivery API validated by exercising the published contract); ADR-038 (transport-agnostic contracts — no HTTP/framework types); Rule 5 (module isolation; core owns contracts); Rule 6 (consumers depend on the API contract only); DECISION N/F (`hsp/v1` namespace + REST contracts); DECISION E (no new `pg_*` wrapper); DECISION L Ruling 0 (four-connection topology frozen); ADR-054 (WP-Cron Processing Engine — the generator is NOT part of a processing cycle) |
| **Preserves** | The endpoint metadata registry (`EndpointProviderInterface`/`EndpointDescriptor`), the four-connection topology, the ADR-054 execution model, and every delivery-API contract (DECISION F/N) — all unchanged in substance; the enrichment is additive |

**Ruling.** HSP publishes an **OpenAPI 3.1** description of the delivery API, and it is **generated from the endpoint metadata registry**, not written by hand and not discovered by scanning WordPress.

**(a) Generated from the registry — never hand-authored, never reflection/scan-derived.** The OpenAPI 3.1 document is produced by a generator that consumes the endpoint metadata supplied through `EndpointProviderInterface` (Doc 12 §15). It is **not** a checked-in hand-authored `openapi.json`, and it is **not** derived by reflecting over or enumerating registered WP REST routes (`rest_get_server()->get_routes()` or equivalent). This preserves the OPSC-S1 **explicit-registration idiom** (ADR-048/ADR-052): the platform describes exactly what modules have deliberately registered as endpoint metadata, nothing implicit. A WP route that exists but carries no registered metadata is a **gap to be surfaced** (see (f)), never silently reverse-engineered into the spec.

**(b) Single source of truth = the endpoint registrations.** Because the document is derived from the current registrations **at serve time**, it **auto-updates**: adding, editing, or removing an endpoint registration changes the served spec with **no separate edit** and no regeneration step to forget. There is exactly one place endpoint truth lives — the registrations — and both the API Playground (OPSC-S3) and this document read from it.

**(c) Additive enrichment of the endpoint metadata contract.** The endpoint metadata contract (`core/Contracts/Operations/EndpointDescriptor` + `EndpointProviderInterface`, today five fields: method, route, namespace, displayGroup, description) is **additively enriched** — no field removed, no existing consumer broken — to carry what an OpenAPI 3.1 Operation Object needs:
- **parameters** (path + query params, with types) — including the DECISION F filters (`slug`/`status`/`published_after`/`category`) and the cursor parameter;
- **request/response schema** (JSON Schema fragments for the resource shapes — Rule 6 published-contract shapes, not internal `content.*`/canonical schemas);
- **auth requirement** (public vs authenticated — Doc 9 §22);
- **cursor-pagination envelope** (`data` + `next_cursor` — Doc 9 §13 / DECISION F `CursorPage`), so paginated list operations describe the envelope, not a bare array;
- **deprecation status** (Doc 9 §26 Supported → Deprecated → Removed lifecycle → OpenAPI `deprecated: true`);
- **version** (the contract version the operation belongs to — Doc 9 §7);
- **module owner** (which module registered the endpoint — Doc 9 §6).

**Ownership (Rule 5 holds verbatim).** **Core owns the contract** — the enriched `EndpointDescriptor`/`EndpointProviderInterface` live under `core/Contracts/Operations/`, and the generator lives in core. **Modules own their metadata** — each module populates the enriched descriptors for its own endpoints (Doc 9 §6; e.g. `modules/Content/Operations/ContentEndpointProvider`), depending on `core/Contracts/` only. Core never imports a module; core never hardcodes an endpoint.

**(d) Exposure.** The document is served at **`GET /hsp/v1/openapi.json`**, versioned per Doc 9 §7 — **the `v1` document describes the `v1` contract** (a future `v2` contract gets `/hsp/v2/openapi.json`). The route is registered on the `hsp/v1` namespace (DECISION N) through the normal REST registration boundary; WPCS security rules (capability/nonce/sanitization/escaping) apply at that registration per DECISION V (b) / DECISION W (a) exactly as for any HSP REST endpoint.

**Scoping — ruled (architect 2026-07-20; resolves FLAG-OAPI-1).** The served `/hsp/v1/openapi.json` document describes **PUBLIC endpoints only.** Endpoints requiring authentication or capabilities (Doc 9 §22) are **EXCLUDED from the generated document** — an unauthenticated caller discovers only the public consumer-facing contract (Rule 6). The **exclusion is driven by the endpoint metadata auth field** (the enriched `auth requirement` from (c)), **not by route inspection** — the generator filters descriptors on their declared auth requirement, consistent with (a) (registry-driven, never route-scan-derived). The **generator endpoint itself remains public and stateless**: there is **no capability check inside request-time generation** (consistent with (e) — the endpoint is a synchronous public REST read; it neither authenticates the caller nor branches its output on caller identity). Since all six `hsp/v1` endpoints are public today, the v1 MVP document describes all of them; the filter takes effect the moment any authenticated `hsp/v1` endpoint is registered, keeping it out of the public document with no further ruling. The OAPI-S1 drift guard (f) additionally asserts, positively, that **no non-public-metadata route appears in the generated document** (exclusion test).

**(e) Request-time, stateless, no infrastructure coupling.** Generation runs **at request time** and holds **no state between requests**. It performs **NO persistence** (no spec table, no cache table, no rollups — the document is computed on demand from the registrations, mirroring the DECISION Q derived-on-demand discipline), **NO PostgreSQL read** (endpoint metadata comes from the in-process registry, not from `system.*`/`content.*`), **NO new connection handle** (DECISION L Ruling 0's four-connection topology is untouched), and **NO new `pg_*` wrapper** (DECISION E). It is **NOT part of the ADR-054 processing cycle** — the generator is a synchronous REST read served in the web request; the WP-Cron Processing Engine never invokes it and it never runs inside a bounded cycle. No schema migration and no new event contract are owed by this ruling.

**(f) Drift guard (CI).** A test enforces registry⇄route consistency and document validity:
1. **Every non-exempted registered `hsp/v1` REST route has a complete metadata entry.** The set of **live `hsp/v1` routes** (the external REST index — the ground truth, never the registry, so the assertion cannot be circular) is enumerated, the **structural exemption** below is subtracted, and the remainder is compared against the set of registered endpoint descriptors; a non-exempted route present in the REST index but missing (or incompletely enriched) in the registry **fails CI**. This is the one place route enumeration is permitted — as an **assertion that the explicit registrations are complete**, never as the generation source (which would violate (a)).

   **Structural exemption (ruled 2026-07-20, "A-modified" — v1.28).** Exactly **one** prefix is exempt from the completeness assertion: **`hsp/v1/onboarding/`**. Authority: DECISION W (e) — the onboarding first-run **admin** surface is deliberately outside the published delivery contract (Rule 6 treats the delivery API as the consumer contract; onboarding is a gated pre-completion admin surface, not a delivery endpoint). The exemption is a **single prefix frozen in this ADR**: the guard **hardcodes this one prefix** with an ADR-055 (f) citation comment, and **adding any further exempt prefix requires an architect ruling**. Any **future authenticated `hsp/v1` route OUTSIDE the exempted prefix must still carry a descriptor** (`auth = authenticated`), which keeps it out of the served document via the (d) auth-field filter (asserted by the exclusion test in (3)) — the exemption exempts a route from *needing a descriptor*, not from the public-only *document* scoping. **Net today: 13 live `hsp/v1` routes − 6 `onboarding/` = 7 guarded routes** (six content + `openapi.json`).
2. **The generated document validates against the OpenAPI 3.1 meta-schema.** The produced document is validated against the **pinned official OAS 3.1 meta-schema** so a malformed or non-conformant document fails CI. **Validator (ruling D, v1.29):** the gate runs via **ajv in the Node toolchain** (`tools/openapi-validator/validate-openapi.mjs`; Node is a sanctioned dev/CI dependency — DECISION W (a)), layered over a PHP structural pre-check (the fast-fail). `opis/json-schema` was evaluated and **rejected** — two reproduced 2020-12 conformance defects (dynamic-anchor indexing; `unevaluatedProperties` false-positives) make it unable to validate real OpenAPI 3.1 documents; no conformant PHP 2020-12 validator exists. The fixture is `tests/fixtures/openapi-3.1-meta-schema-pinned.json` (official meta-schema, `$id …/2022-10-07`, four semantics-preserving `$dynamicRef "#meta"` → `$ref "#/$defs/schema"` edits), **pinned and never fetched at test time**. **Environment contract:** node available → gate runs; node missing without `HSP_REQUIRE_NODE_GATE` → the meta-schema assertion SKIPS with a warning; `HSP_REQUIRE_NODE_GATE=1` (CI) with node missing → FAIL.
3. **Exclusion test (v1.27).** No endpoint whose metadata marks it non-public appears in the generated document — asserted positively with a fixture non-public descriptor (public-only scoping per (d)).

   A **non-circularity** assertion accompanies (1): a fixture route registered on `hsp/v1` **outside** the exempted prefix **without** a descriptor **fails the guard** — proving the guard reads the external route index, not the registry it is checking. **Enumeration funnel (omission-proof):** the guard collects the live index by driving the SAME boot path production uses — core REST registrars through the single `core/Rest/RestRegistrarRegistry` list that `headless-sync.php` iterates, and each module's real `boot()` (which hooks its own registrar onto `rest_api_init`) — then fires `rest_api_init`. Nothing is hand-listed in the test, so a registrar added to production but not to a test array cannot exist; adding a core REST registrar in that one list makes it visible to both production and the guard automatically.

A non-exempted route without metadata therefore cannot merge green — the registry stays the authoritative, complete description of the published contract.

**Rationale.** Hand-authored API specs drift the moment an endpoint changes; reflection-derived specs describe whatever WordPress happens to expose, including internals, and defeat the explicit-registration guarantee the console is built on. Deriving the document from the same endpoint registry the API Playground already uses (Doc 12 §15) gives one source of truth, an always-current spec, and a CI guard that makes "an endpoint without a described contract" a build failure — all without new persistence, a new PG handle, or any coupling to the background-processing execution model.

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

### DECISION O — Credential Resolution and Configuration Precedence (P1A-S8)

| Field | Value |
|---|---|
| **Status** | Accepted |
| **Date** | 2026-06-25 |
| **Session** | P1A-S8 |
| **Authority** | CLAUDE.md (config/ holds no business logic; bootstrap owns env loading); ADR-012 (constructor injection only); DECISION H (wp-config defines are available in the worker process because the worker loads WordPress); DECISION E/K/L (connection topology frozen — this decision changes credential SOURCE only) |

**Ruling:**

**(a) Credential precedence — define → getenv → default.**  
For every HSP credential key, the resolution order is:
1. `defined('HSP_*') ? constant('HSP_*') : null` — `wp-config.php` `define()` constants (highest precedence; the idiomatic WP way to configure plugins)
2. `getenv('HSP_*')` — environment variable fallback (Docker / CI / legacy `putenv()` callers)
3. Documented default (empty string / well-known port number), or **hard failure** for required credentials that have no safe default

**(b) Required PostgreSQL credentials fail loud.**  
`HSP_PG_HOST`, `HSP_PG_USER`, `HSP_PG_PASSWORD`, and `HSP_PG_DBNAME` are required. If any resolves to an empty/null value from both sources (define + getenv) and no meaningful default exists, `CredentialResolver` throws a `\RuntimeException` with a clear diagnostic message naming the missing credential. Silent defaults that produce a broken but non-fatal connection string are prohibited for these four keys. `HSP_PG_PORT` defaults to `5432` and is not required.

**(c) MySQL inherits WordPress DB_* constants by default.**  
`CredentialResolver` derives MySQL connection parameters from the WordPress native constants `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASSWORD` that WordPress sets in `wp-config.php` before any plugin runs. An optional `HSP_MYSQL_*` override path exists (`HSP_MYSQL_HOST`, `HSP_MYSQL_PORT`, `HSP_MYSQL_NAME`, `HSP_MYSQL_USER`, `HSP_MYSQL_PASSWORD`) but is unused by default. HSP does NOT duplicate WP DB credentials in `wp-config.php` — the resolver reads `DB_*` directly from WP constants when no `HSP_MYSQL_*` override is present.

**(d) One resolver; provider factories read the resolver.**  
All credential resolution runs through a single `HSP\Bootstrap\CredentialResolver` class. The four runtime PostgreSQL provider factories (Outbox, Queue, Delivery, Dispatcher) and the Relay MySQL factory each receive the resolver via constructor injection (ADR-012 compliant) and call its methods rather than reading `getenv()` or the config array directly for credential values. No provider factory may call `getenv()` directly for DB credentials.

**(e) Test DSN injection preserved.**  
Integration tests that inject raw `pg_connect()` DSNs continue to do so directly. The resolver is for runtime provider factories only. No integration test is rewired to the resolver.

**(f) wp-config.php uses define(), not putenv(), for HSP PostgreSQL credentials.**  
Local development sets HSP PG credentials via `define('HSP_PG_HOST', '127.0.0.1')` etc. in `wp-config.php`. The prior `putenv()` approach is replaced. No real secrets are committed — the `wp-config.php` local-dev block carries only the local Docker credentials used in the development environment. The credential key names are `HSP_PG_HOST`, `HSP_PG_PORT`, `HSP_PG_DBNAME`, `HSP_PG_USER`, `HSP_PG_PASSWORD`.

**(g) Connection topology is unchanged.**  
This decision changes only the credential source. The four FORCE_NEW handles established by DECISION K/E/L remain exactly as-is. FLAG-P1AS6D-1 stays Open and is not touched. No handle is merged, removed, or added.

**Rationale:** `define()` constants are the idiomatic WordPress mechanism for plugin configuration. Using `getenv()` as the primary source required callers (including CI) to duplicate secrets in both `wp-config.php` and environment variables. Centralising resolution in one class with explicit precedence eliminates that duplication, makes the configuration surface visible at a glance in `wp-config.php`, and ensures required credentials fail loudly rather than producing a mystery connection error at `pg_connect()` time.

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

### DECISION Y — PostgreSQL Full-Text Search Deferred from Phase 1B to Phase 5

| Field | Value |
|---|---|
| **Status** | Accepted |
| **Date** | 2026-09-05 |
| **Session** | P1B-S0 (Phase 1B planning) |
| **Authority** | Product decision 2026-09-05 (scope owner); Doc 11 §7 (Phase 1B deliverables), §14 (Phase 5 — Search Expansion), §17 (Search Roadmap); Doc 9 §14/§15 (search architecture, provider-based); Doc 3 §27 (search & indexing strategy) |
| **Resolves** | The Phase 1B/Phase 5 placement of PostgreSQL full-text search |
| **Amends** | Doc 11 §7 (v1.1 → v1.2 — "PostgreSQL Search" removed from the Phase 1B deliverable list and from the §7 "Search Queries" validation item); IMPLEMENTATION_PLAN.md §5 Phase 1B/Phase 5 pointers. **Nothing else** |

**Ruling.** **PostgreSQL full-text search is NOT a Phase 1B deliverable.** Phase 1B — Content
Enhancement is: **featured images, media synchronization, tags, basic ACF, and pagination**.
Search — the PostgreSQL provider included — is delivered in **Phase 5, Search Expansion**
(Doc 11 §14), which already states that PostgreSQL Search remains supported.

**Explicitly unchanged.** The **§17 Search Roadmap ordering stands**: PostgreSQL Search still
comes first, before the search provider contract and any OpenSearch/Typesense provider — only
its *phase placement* moved. **Doc 9 §14/§15** (`SearchProviderInterface`, provider-based search
strategy), **Doc 3 §27** (search & indexing strategy, `tsvector`), and the Doc 5/6/7
search-projection references are **untouched**: they describe Phase 5+ material and were never
Phase 1B commitments. No search contract, schema, index, migration or endpoint is created,
renamed, or removed by this decision — Phase 1B simply does not open the topic.

**Why it is recorded rather than edited in.** Doc 11 is frozen and listed "PostgreSQL Search"
among the Phase 1B deliverables. A scope change against a frozen document requires an explicit
ruling here (precedence: this document wins), with the superseded line retained under a banner
in Doc 11 §7 rather than deleted — the same treatment DOC-RECON-S1 applied to the ADR-054
sibling-document reconciliation.

**Consequence for planning.** The IMPLEMENTATION_PLAN §5b Phase 1B session map authored in
P1B-S0 contains **no search session**, and no Phase 1B session DoD may introduce a `tsvector`
column, a full-text index, or a search endpoint. A Phase 1B row that would benefit from search
must instead rely on the existing DECISION F filters and cursor pagination.

---

### DECISION Z — Lazy PostgreSQL Connections at the Container Boundary

| Field | Value |
|---|---|
| **Status** | Accepted |
| **Date** | 2026-09-05 |
| **Session** | LAZYPG-S1 (interstitial, inserted before P1B-S0) |
| **Authority** | ONB-S1b OBSERVATION ("lazy-connection ruling pre-Phase-1B", carried forward in STATUS.md); DECISION E (v1.6 — shared runtime PG connection layer, no new `pg_*` wrapper, boundary error translation); DECISION K (v1.11 — delivery connection isolation); DECISION L Ruling 0 (four-handle topology, frozen); ADR-012 (constructor injection); ADR-054 Principle 8 (the platform must not fatal on an unconfigured site) |
| **Resolves** | The ONB-S1b eager-connection observation; retires the "lazy-connection ruling pre-Phase-1B" carry-forward |
| **Amends** | Nothing frozen. No contract, schema, migration, event, or handle topology changes — only *when* a libpq link is opened |

**Ruling.** The four runtime PostgreSQL handles are opened **lazily, on first real use**, not at
container-resolution time. Each service-provider factory hands a **connector `\Closure`** to its
connection wrapper instead of an already-open handle; `PostgresDatabaseConnection` accepts either
form, invokes the connector at most once, memoizes the handle, and translates any connect failure
to `DatabaseException` at that boundary (subsystems keep translating it onward to
`OutboxWriteException` / `QueueException` per DECISION E v1.6). `rollback()` on a connection that
was never opened is a no-op — it must not dial the socket to undo a transaction that cannot exist.

**Why.** `PostgresDatabaseConnection` previously required an already-open handle, so all four
providers called `pg_connect()` inside their singleton factory and let a raw `\RuntimeException`
escape when PostgreSQL was unreachable or unconfigured. On the delivery handle this was
user-visible: `ContentModule::boot()` correctly defers registrar construction to `rest_api_init`,
but that hook fires on **every REST request to the site** — `wp/v2` and the block editor
included — and building the registrar resolves the query providers, which opened the socket.
An unreachable PostgreSQL therefore fatalled **every REST request**, not just `hsp/v1`. The
capture path had already been hotfixed this exact way (`MysqliOutboxConnection` takes a connector
closure); this ruling applies the same idiom to the PostgreSQL side, where all four callers route
through one class.

**Explicitly unchanged.** Still **four** handles, each still opened with its existing flags —
`PGSQL_CONNECT_FORCE_NEW` on delivery, queue-claim and dispatcher; no flag on the relay handle
(DECISION K isolation and DECISION L Ruling 0 topology are untouched). **No fifth handle, no new
`pg_*` wrapper class** (DECISION E), no persistence, no schema change, no contract change. The
migration engine's DDL-only `ConnectionFactory` is out of scope and keeps connecting eagerly —
it is only reached from explicit migrate actions, which already gate on reachability
(DECISION W (f)).

**Precedence note.** DECISION **Y** is reserved for the P1B-S0 Phase-1B search deferral; this
ruling landed first and took the next free letter after it.

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
| `system.dead_letter_jobs` | New column: `replayed_at TIMESTAMPTZ NULL` (NULL = not yet replayed; stamped in the single-transaction replay per DECISION S; DLQ rows are never deleted). Absent from migration 0004 — added by a forward migration authorized in OPS-S1; migration 0004 must not be edited. | DECISION S (v1.16) |
| `system.worker_heartbeats` | New table: PK `worker_id UUID`; `worker_type TEXT NOT NULL`, `status TEXT NOT NULL`, `last_heartbeat_at TIMESTAMPTZ NOT NULL`, `started_at TIMESTAMPTZ NOT NULL`. Single current-state row per worker, upserted per tick; no history table. Migration authorized in OPS-S1. | DECISION P (v1.16) |
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
| `bootstrap/CredentialResolver` | New class. Single source of truth for all database credential resolution. Implements `define()` → `getenv()` → default precedence. Required PG credentials (host, user, password, dbname) throw `\RuntimeException` when unresolvable. MySQL derives from WP `DB_*` constants by default; `HSP_MYSQL_*` overrides when present. Injected into provider factories via constructor (ADR-012). | DECISION O (v1.15) |
| `core/Container/Definitions/OutboxServiceProvider`, `QueueServiceProvider`, `DeliveryServiceProvider`, `DispatcherServiceProvider` | Each receives a `CredentialResolver` instance via constructor. Must not call `getenv()` directly for DB credentials. | DECISION O (v1.15) |
| `wp-config.php` (local dev) | HSP PostgreSQL credentials set via `define('HSP_PG_HOST', …)` etc. (not `putenv()`). MySQL credentials not duplicated — resolver reads `DB_*` WP constants directly. | DECISION O (v1.15) |
| `core/Workers/` heartbeat publisher | New `DatabaseHeartbeatPublisher` implements the existing `HeartbeatPublisherInterface` (replaces `NullHeartbeatPublisher` at runtime); upserts `system.worker_heartbeats` per tick; PG connection injected via constructor (ADR-012), using the worker-runtime handle (DECISION L Ruling 0) — no new handle/class/`pg_*` wrapper. | DECISION P (v1.16) |
| `core/Workers/Strategies/MaintenanceWorkerStrategy` | Un-stubbed: drives `DatabaseQueueProvider::requeueTimedOut()` on a config-driven cadence (no hardcoded timing); uses the worker-runtime handle. | DECISION R (v1.16) |
| DLQ replay path (`core/`) + WP-CLI `hsp dlq list\|inspect\|replay` | Replay runs in one PG transaction: verify DLQ row exists → verify `replayed_at IS NULL` → DELETE any `system.queue_jobs` row sharing `event_id` → INSERT fresh job `attempts = 0` → stamp `replayed_at`. Re-enters via normal queue/claim path; DECISION J Resolve-stage guard may ack with zero writes (correct). WP-CLI only; no admin UI. DLQ rows never deleted. | DECISION S (v1.16) |
| Metrics (no persistence) | No metrics table / rollups / external telemetry in MVP. Derived metrics (queue depth, DLQ depth, oldest-pending age, worker count) computed on demand via PostgreSQL aggregates; runtime counters (processed/retry/failure/replay) emitted as structured worker log events. "metrics emit" DoD = queryable status + structured logs. | DECISION Q (v1.16) |
| `core/Contracts/ReplayEmitterInterface` | New contract (core-owned). `emitForAggregate(string $aggregateType, string $aggregateId, string $correlationId, string $causationId): ?EventInterface` — reads current WP state, decides `.updated` (exists+public) vs `.deleted` (missing/non-public), emits ONE synthetic event through the outbox with a fresh counter version. Implemented in the Content module (`modules/Content/Replay/ContentReplayEmitter`); core never imports the module (Rule 5). | DECISION T (v1.17) |
| `core/Replay/ReplayService` | New class. Orchestrates entity replay (one aggregate) and date-range replay (`SELECT DISTINCT aggregate_type, aggregate_id FROM system.events` in `[from, to)`, read via the existing delivery `DatabaseConnectionInterface` handle — no fifth handle). Delegates per-aggregate emit to `ReplayEmitterInterface`. Assigns one `correlation_id` per run and a `causation_id` per replay operation. No projection writes; no historical-event mutation. | DECISION T (v1.17) |
| `core/Workers/Strategies/ReplayWorkerStrategy` | Un-stubbed: exposes `replayEntity()` / `replayRange()` delegating to `ReplayService`. `execute()` remains a no-op (`false`) — entity/date-range replay is a producer-side, CLI-triggered operation, not a `system`-queue consumer. **Doc 8 roster note (no Doc 8 edit):** Doc 8 §7 describes worker strategies as consumer-side `Claim→…→Ack` pipelines; `ReplayWorkerStrategy` is intentionally a producer-side strategy whose `execute()` is a deliberate no-op. If ever launched under a `WorkerEngine` it idles cleanly (returns false → 'idle' heartbeat + engine idle back-off; no busy-spin, no queue claim, no I/O, no exception — asserted by `ReplayWorkerStrategyTest::testIdlesCleanlyUnderWorkerEngine`). This is recorded here rather than by amending Doc 8. | DECISION T (v1.17) |
| WP-CLI `hsp replay entity <type> <id>` / `hsp replay range <from> <to>` | New CLI subcommands extending the OPS-S1 `hsp` surface (thin `\WP_CLI` shim → `ReplayWorkerStrategy`). WP-CLI only (consistent with DECISION S clause (d)). | DECISION T (v1.17) |
| `core/Contracts/WpReconciliationSourceInterface` | New core contract (core-owned; Content-module implementation `modules/Content/Reconciliation/WpReconciliationSource`). Detection-side WordPress reads only: list aggregate IDs by type (paged), fetch `post_modified_gmt` / existence / public-status, and the data to recompute the projection checksum for incremental/full modes. Symmetric with `ReplayEmitterInterface`; core never imports the module (Rule 5). Contract-only — no schema change. | DECISION U (v1.19) |
| `core/Reconciliation/ReconciliationService` | New class. Detection + batching + suppression; three modes as one detector + `mode` parameter (drift/incremental/full per D1). Reads `content.*` + `system.aggregate_versions` (+ `system.events` for suppression) via the existing delivery `DatabaseConnectionInterface`; reads WP via `WpReconciliationSourceInterface`; reads pending `wp_hsp_outbox` via the outbox read path. Repairs **only** by calling `ReplayService::replayEntity()` per genuinely-drifted aggregate — **no direct `content.*`/`system.*` projection writes.** Applies the D4 suppression rule. WordPress-wins by construction. | DECISION U (v1.19) |
| `core/Workers/Strategies/ReconciliationWorkerStrategy` | Un-stubbed in OPS-S3 as a façade over `ReconciliationService`: `reconcileDrift()` / `reconcileIncremental()` / `reconcileFull()`. `execute()` stays a producer-side no-op (`false`, B1 — matches `ReplayWorkerStrategy`); reconciliation is CLI/cron-triggered, not a `system`-queue consumer. Service dependency via constructor injection (ADR-012); `WorkerServiceProvider` wiring updated (was `new ReconciliationWorkerStrategy()`). Detection: missed captures (WP newer/absent vs delivery — DECISION 1 backstop) and orphans (full-mode only, PG→WP). Repair via DECISION T re-emission ONLY; no new handle (DECISION L Ruling 0), no new `pg_*` wrapper (DECISION E). | DECISION U (v1.19) |
| WP-CLI `hsp reconcile drift\|incremental\|full` (+ `status` dry-run) & WP-Cron triggers | New CLI subcommands extending the OPS-S1 `hsp` surface (thin `\WP_CLI` shim → `ReconciliationWorkerStrategy`); WP-CLI only. WP-Cron authorized (CLAUDE.md recovery-jobs carve-out) to *trigger* passes only via three schedules (hourly/nightly/weekly, cadence + page-size config-driven); callbacks call the same strategy methods; the worker-bootstrapped process remains the execution path. Trigger is swappable to external scheduling later with no repair-path change. | DECISION U (v1.18/v1.19) |
| `core/Operations/` | New core-infrastructure subtree (lowercase; `HSP\Core\Operations\`): Operations Registry (Page/Nav/Widget/Action/Asset), Providers (Health/Metrics/Worker-Status/Queue-Status/Endpoint), Services, Diagnostics, and **server-rendered PHP** admin UI (no node/npm/bundler toolchain, no shipped JS bundle; minimal vanilla JS only). Console is **observability/diagnostics only** — not a control plane. Registry-driven discovery (explicit registration, no reflection); providers resolve via constructor injection (ADR-012). | DECISION V (v1.20); ADR-047/048/052/053 |
| `core/Contracts/Operations/` | New namespace under the existing contracts root. **All operations contracts live here** (provider/widget/action/diagnostics/metrics interfaces), NOT under `core/Operations/Contracts/`. Keeps Rule 5 verbatim: modules (`modules/*/Operations/`) that provide console implementations depend on `core/Contracts/` only. | DECISION V (v1.20 — FLAG-11 A) |
| Console provider PG reads | Reuse the delivery `DatabaseConnectionInterface` (DECISION K) from the wp-admin request context — no fifth handle (DECISION L Ruling 0 topology unchanged), no new raw `pg_*` wrapper (DECISION E). Providers read `system.queue_jobs`, `system.dead_letter_jobs`, `system.worker_heartbeats`, `content.*`. | DECISION V (v1.20 — FLAG-10 A) |
| Console metrics | **No new persistence** — all metrics derived on-demand per DECISION Q (processing rate = rolling-window query; replay/reconciliation status = last-run summary from existing rows/logs). No metrics table, no rollups, no time-series store. | DECISION V (v1.20 — FLAG-5 A); DECISION Q |
| Console Operational Actions (`core/Operations/` + `modules/*/Operations/`) | **Replay + Reconcile only.** Thin delegators to `ReplayService` (DECISION T/S) and `ReconciliationService` (DECISION U); **no second repair path**, no direct `content.*`/`system.*` writes (write-spy proof required in OPSC-S4 DoD). **Flush Queue REMOVED** (destructive; violates Rule 4 / DECISION 1). **No Restart Workers action** — worker status/heartbeat/runbook links only; lifecycle belongs to the supervisor. Actions gated by capability + confirmation + audit. | DECISION V (v1.20 — FLAG-6/7/8); ADR-053; ADR-051 HELD |
| Coding standard (now settled) | PSR-12 for all platform code; WPCS security rules (escape/sanitize/capability/nonce) at WordPress entry points only. Lifts the IMPLEMENTATION_PLAN §3 "do not enforce until confirmed" hold; the WP-admin boundary is open. **DECISION W (v1.21) extends the WPCS boundary to the REST/ajax endpoints the React admin UI calls** (the untrusted-client JSON boundary). | DECISION V (v1.20 — FLAG-4 A); DECISION W (v1.21) |
| Admin UI stack (amends DECISION V (a)) | **React + shadcn is THE admin UI stack** (supersedes DECISION V (a)'s server-rendered PHP + no-node-toolchain ruling going forward). Build-artifact policy = **commit `dist/` to the repo** (npm build in dev/CI only; production deploy is a file copy per the CLAUDE.md robocopy step; **no host build step**). `package.json`/toolchain in repo for dev/CI; production carries only built assets. The **already-shipped OPSC-S1..S4 server-rendered PHP console remains as built** (not rewritten); only **new** admin UI (incl. onboarding) is React. Provider/registry architecture (registries, provider contracts, `OperationsService` seam, ADR-047/048/052/053, console-is-observability-only) is **unchanged**. | DECISION W (v1.21 — ruling (a)) |
| `core/Onboarding/` | New core-infrastructure subtree (`HSP\Core\Onboarding\`): first-run preflight/prerequisite checks, onboarding admin page (React), nav gating on `hsp_onboarding_state`, backfill trigger, derived progress. **Lifecycle/setup surface — NOT under `core/Operations/`** (keeps DECISION V (j) console-is-observability-only intact). Delegates to ratified services only (`ReconciliationService` DECISION U, migration engine, Worker Status/heartbeat read path); opens no PG handle (reuses delivery `DatabaseConnectionInterface` — DECISION K; no fifth handle DECISION L Ruling 0; no new `pg_*` wrapper DECISION E). Any onboarding contracts live under `core/Contracts/` (Rule 5). | DECISION W (v1.21 — ruling (e)) |
| Onboarding backfill (first-run content migration) | **Full-reconciliation re-emission via `ReconciliationService::reconcileFull()` (DECISION U) through the normal outbox→relay→dispatch→worker pipeline.** Thin delegator; **NO direct WP→PG copy path, no second repair path** — projections written only by the worker pipeline (write-spy proof required in ONB-S2 DoD, mirrors DECISION V (d)/GATE-S3). WordPress-wins by construction. No new event contracts (reuses OPEN-1 `.updated`/`.deleted`). | DECISION W (v1.21 — ruling (b)); DECISION U; DECISION T |
| Onboarding queue-drain prerequisite | **A live worker heartbeat is a HARD PREREQUISITE** for triggering backfill — a fresh `system.worker_heartbeats` row must exist (DECISION P age check vs config threshold). Workers drain the pipeline as normal (execution path); **no in-request tick drain** (the admin request never runs the worker engine inline). If no live worker, backfill is blocked → worker-status + runbook guidance (no Restart Workers — DECISION V (f)). | DECISION W (v1.21 — ruling (c)); DECISION P |
| Onboarding progress + completion state | Progress **derived on-demand per DECISION Q** (expected-count scan of in-scope WP aggregates vs processed/projection counts at read time); **zero new PG persistence** — no progress table/rollups/time-series. Completion signal = a single WordPress option **`hsp_onboarding_state`** in MySQL (WP options table) — **no schema migration, no new table/column**. | DECISION W (v1.21 — ruling (d)); DECISION Q |
| Onboarding nav gating + preflight hard-block | Until `hsp_onboarding_state = complete`, the **Operations + API Playground admin pages are not registered/visible** (menu registration gated on the flag); the onboarding page is the only HSP admin surface. **ONB-S1b environment-preflight hard-blocks (four checks):** (1) `pgsql` extension loaded; (2) PG constants defined in `wp-config` (DECISION O `HSP_PG_*`); (3) PG reachable; (4) PHP version ≥ platform minimum. **Amended v1.22:** the migration-engine-state check (required core+content migrations applied per `system.schema_versions`/`system.module_versions`, OPEN-8) is a **backfill prerequisite evaluated in ONB-S2**, not part of the ONB-S1b environment preflight — same hard-block semantics on the same delivery-handle read path, moved to where the delivery schema/data readiness actually gates work. A failed prerequisite is a hard block with remediation guidance, not a warning. | DECISION W (v1.21 — ruling (f), amended v1.22); DECISION O; OPEN-8 |
| Onboarding self-remediating backfill gates (ONB-S2) | The two backfill gates (migrations applied; processing pipeline advancing) are **self-remediating in-product** so a **zero-config fresh install** completes with **no manual CLI** (ADR-054 **Principle 8**) — each keeps its **hard block** (action, not bypass). **`POST hsp/v1/onboarding/migrate`** applies the outstanding core + content migrations through the **EXISTING** engine over the **DECISION W (e) delegate list** (core migrations + module `getMigrations()` — OPEN-9/Rule 5), a thin delegator (`core/Onboarding/MigrationApplier`; no new engine/DDL/schema/`pg_*` wrapper/handle), gated on the four env preflight checks (409 until they pass), re-evaluating `MigrationsAppliedCheck` after. **`POST hsp/v1/onboarding/spawn-worker`** issues a **non-blocking** WP-Cron spawn (`core/Onboarding/WorkerCronSpawner`) so a cycle runs + a heartbeat appears — **NO in-request drain (DECISION W (c) intact)**; WP-Cron-only warning when `DISABLE_WP_CRON` (no supervisor/systemd/daemon/restart). Plugin **`activate()`/`upgrade()` (OPEN-9)** attempt pending migrations through the same engine **IFF `HSP_PG_*` defined AND PG reachable**, silent no-op otherwise — **never fatal on an unconfigured site**. All WPCS-guarded (nonce+capability+sanitize — DECISION W (a)/V (b)). No second repair path; no schema change; no new handle/wrapper. | DECISION W (f) (amended **v1.23**); DECISION W (e)/(a)/(c); DECISION X (4); OPEN-8/OPEN-9; ADR-054 Principle 8 |
| Background execution model (WP-Cron only, v1.x) | **No long-running workers / supervisors / systemd / Docker workers / CLI daemons.** Background processing = a **WP-Cron-triggered Processing Engine** running one **bounded, stateless cycle** per tick (relay batch → dispatch batch → projection batch → maintenance → persist metrics → clean exit). Config keys in `config/worker.php`: `processing.relay_batch_size`, `processing.dispatch_batch_size`, `processing.projection_batch_size`, `processing.cycle_time_budget_seconds` (config-driven, sensible defaults; **no schema migration**). Overlapping cycles safe via **existing** guarantees only (SKIP LOCKED + aggregate versioning [DECISION J] + visibility timeout [OPEN-4/DECISION R] + single-txn commit [DECISION 3]) — **no new locking mechanism**. Implementation class names retained (`WorkerEngine`/`*WorkerStrategy`/`WorkerExecutionContext`) as processing components invoked by cron — **no rename**. `system.worker_heartbeats` (DECISION P) reused verbatim, reinterpreted as **per-cycle freshness/progress**, not daemon liveness. Metrics: `worker_uptime`/`restart_count` **removed**; replaced by cycles-completed / avg-cycle-duration / per-stage-throughput / queue-backlog / processing-lag (derived on demand — DECISION Q). Recovery = next cron execution + queue durability + visibility timeout + replay (DECISION T) + reconciliation (DECISION U). **No fifth PG handle, no new `pg_*` wrapper** (DECISION L Ruling 0 / E unchanged). | ADR-054 (v1.23); supersedes ADR-024; amends ADR-035/ADR-036; Doc 8 v2.0 |
| `core/Contracts/WorkerInterface` (contract correction) | `run()` / `shutdown()` **removed**; contract now expresses **one bounded processing cycle** (execute one cycle honouring configured per-stage batch limits + execution-time budget; return a processing-result value describing the completed cycle). Internal core contract (no module implements it). Retains the name (ADR-054 §8). | DECISION X (v1.24 — ruling (3)); FLAG-ALIGN-1 (c) |
| `core/Workers/` Processing Engine cycle + heartbeat identity | The engine composes the four consumer-side stages (relay → dispatch → projection → maintenance) into **one bounded cycle** and exits (no `run()` loop, no `usleep`, no daemon lifecycle). Each cycle mints a **fresh UUIDv7** `worker_id` at bootstrap → `system.worker_heartbeats` holds one row per recent cycle (per stage); maintenance prunes stale rows under existing retention. Heartbeat `status` set = **`'running'`/`'idle'` only** (`'processing'`→`'running'`; `'shutdown'` removed). `DatabaseHeartbeatPublisher` SQL + DECISION P schema **reused verbatim**. Per-strategy daemon-engine bindings (`worker.engine.event`/`worker.engine.maintenance`/`dispatcher.engine`) retired from the execution path; one cycle-engine binding composes the stages. | DECISION X (v1.24 — rulings (1)/(2)); ADR-054; DECISION P/Q |
| Processing-cycle WP-Cron trigger + activation scheduling | A recurring processing-cycle WP-Cron event (custom interval from config cadence, `wp_next_scheduled` guard — `ReconciliationCronRegistrar` precedent) whose callback runs **one bounded cycle**; bound in `headless-sync.php`. `Application::activate()` schedules the processing event (+ reconciliation events for consistency); `deactivate()` clears via `wp_clear_scheduled_hook`. **No `hsp worker`/`hsp process` daemon CLI** (superseded ADR-024 surface); the cadence trigger is `wp cron event run`. | DECISION X (v1.24); ADR-054 §1/§8b; DECISION R (precedent) |
| Onboarding backfill worker prerequisite (realigned — implemented ALIGN-S2) | The DECISION W (c) "live worker heartbeat" prerequisite becomes: **(i) the processing-cycle cron event is scheduled AND (ii) a recent processing heartbeat exists** (both required). Remediation references **only WP-Cron** (`wp cron event run --due-now`, "ensure the processing cron is scheduled/firing") — **never** supervisor/systemd/daemon/"restart the worker". `BackfillService` no-in-request-drain + re-emission repair unchanged. | DECISION X (v1.24 — ruling (4)); FLAG-ALIGN-2; DECISION W (c); ADR-054 §5 |
| `core/Contracts/Operations/EndpointDescriptor` + `EndpointProviderInterface` (additive enrichment) | Descriptor **additively enriched** (no field removed; existing five fields method/route/namespace/displayGroup/description retained) to carry OpenAPI 3.1 Operation metadata: **parameters** (path + query, incl. DECISION F filters + cursor), **request/response schema** (Rule 6 published shapes — not internal `content.*`/canonical), **auth requirement** (public/authenticated — Doc 9 §22), **cursor-pagination envelope** (`data`+`next_cursor` — Doc 9 §13 / DECISION F `CursorPage`), **deprecation status** (Doc 9 §26 → OpenAPI `deprecated`), **version** (Doc 9 §7), **module owner** (Doc 9 §6). Core owns the contract (`core/Contracts/`, Rule 5); modules populate their own descriptors (`modules/*/Operations/`, e.g. `ContentEndpointProvider`) depending on `core/Contracts/` only. | ADR-055 (v1.26); Doc 12 §15; Doc 9 §6/§7/§13/§22/§26 |
| OpenAPI generator (`core/`) + `GET /hsp/v1/openapi.json` | New core generator produces an **OpenAPI 3.1** document **from the endpoint registry** (`EndpointProviderInterface`) — **never hand-authored, never reflection/scan-derived** from WP routes (explicit-registration idiom, ADR-048/052). Single source of truth = the registrations; the served spec **auto-updates** because it is derived at request time. Route `GET /hsp/v1/openapi.json` registered on the `hsp/v1` namespace (DECISION N) at the normal REST boundary (WPCS per DECISION V (b)/W (a)); versioned per Doc 9 §7 (the v1 doc describes v1). **Scoped to PUBLIC endpoints only** (Doc 9 §22 — FLAG-OAPI-1 resolved v1.27): endpoints requiring auth/capabilities are **excluded from the generated document**, exclusion driven by the metadata **auth field** (not route inspection); the generator endpoint itself stays **public + stateless** (no capability check inside generation — consistent with ADR-055 (e)). **Request-time + stateless:** NO persistence, NO PG read, NO new handle (DECISION L Ruling 0), NO `pg_*` wrapper (DECISION E), **NOT part of the ADR-054 cron cycle**. No schema change; no new event contract. | ADR-055 (v1.26; scoping v1.27); ADR-050; DECISION N/F; Doc 9 §7/§22; DECISION E; DECISION L Ruling 0; ADR-054 |
| OpenAPI drift guard (CI test) | A test asserts **(1)** every **non-exempted** registered `hsp/v1` REST route has a **complete** endpoint-metadata entry — enumeration reads the **full live `hsp/v1` REST index** (external ground truth, never the registry — non-circular), **subtracts the one frozen structural exemption `hsp/v1/onboarding/`** (DECISION W (e) — first-run admin surface, outside the published contract; further exempt prefixes need an architect ruling; guard hardcodes this prefix with an ADR-055 (f) citation), then requires a complete descriptor per remaining route — a non-exempted route without metadata **fails CI** (net today 13 − 6 = **7** guarded routes); route enumeration permitted here ONLY as a completeness assertion, never as the generation source; **(2)** the generated document **validates against the pinned OpenAPI 3.1 meta-schema** — the gate runs via **ajv (Node toolchain, `tools/openapi-validator/`)** layered over a PHP structural pre-check (ruling D, v1.29 — `opis/json-schema` removed for two reproduced 2020-12 defects; no conformant PHP validator; Node is a sanctioned dev/CI dep per DECISION W (a)); node-missing SKIPs unless `HSP_REQUIRE_NODE_GATE=1` (then FAILs); **(3)** (exclusion test, v1.27) **no endpoint whose metadata marks it non-public appears in the generated document** — public-only scoping (ADR-055 (d)) asserted positively; **(4)** (non-circularity, v1.28) a fixture `hsp/v1` route **outside** the exempted prefix **without** a descriptor **fails the guard**. | ADR-055 (v1.26 — clause (f); scoping v1.27; enumeration v1.28; ajv gate v1.29); ADR-048/052; DECISION W (a)/(e) |
