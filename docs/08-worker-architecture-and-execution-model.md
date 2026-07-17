# Background Processing & Execution Architecture

**Project:** Headless Sync Platform (HSP)
**Version:** 2.0
**Status:** Approved
**State:** Frozen

**Supersedes:** Document 8 Version 1.0 ("Worker Architecture & Execution Model") — the v1.0 daemon/long-running-worker execution model is replaced by the WP-Cron Processing Engine model ratified in **ADR-054** (ARCHITECTURE_DECISIONS.md). ADR-054 supersedes the execution-model decision of **ADR-024** (CLI workers primary) and amends **ADR-035** and **ADR-036** where they assumed daemon workers. The pipeline, execution context, subscriber/handler model, registry-based resolution, aggregate-version ordering, visibility timeout, replay, reconciliation, correlation/causation IDs, failure isolation, stateless processing, and at-least-once delivery are **preserved unchanged**. Only the mechanism that *invokes* processing changes: from continuously running CLI worker processes under a supervisor to bounded processing cycles triggered by WP-Cron.

**Depends On:**

* Document 1 — Technical Architecture Specification
* Document 2 — Plugin Folder Structure & Code Organization
* Document 3 — Database Design & Persistence Architecture
* Document 4 — Queue & Event Processing Architecture
* Document 5 — Event Architecture & Contract Design
* Document 6 — Transformer Architecture & Canonical Model Design
* Document 7 — Adapter Architecture & Delivery Projection Design
* ARCHITECTURE_DECISIONS.md — **ADR-054** (authoritative execution-model ruling), DECISION L Ruling 0 (four-connection topology), DECISION P (heartbeat current-state), DECISION Q (metrics/progress without persistence), DECISION J (Resolve-stage stale guard), DECISION T/U (replay/reconciliation via re-emission)

---

# 1. Purpose

This document defines the runtime **background processing architecture** responsible for advancing synchronization work: relaying captured events, dispatching them onto the queue, and projecting them into the delivery store.

It establishes:

* The product rationale for WP-Cron-only execution (§2b) and Zero-Configuration Operation (Principle 8)
* The Processing Engine model (WP-Cron-triggered, bounded, stateless-between-runs)
* Processing components and ownership
* The execution pipeline (per-event, unchanged)
* Batch sizing, execution-time budget, and continuation across cron runs
* Concurrency safety for overlapping cron executions
* Processing health and processing metrics (replacing worker-liveness health)
* Recovery
* Execution context and event tracing
* Failure isolation and stateless processing

This document is the authoritative specification governing background execution throughout the platform.

> **Terminology (binding).** HSP v1.x has **no long-running worker processes.** The implementation class names retained from v1.0 — `WorkerEngine`, `RelayWorkerStrategy`, `EventWorkerStrategy` (dispatcher/event processing), `ReconciliationWorkerStrategy`, `MaintenanceWorkerStrategy`, `WorkerStrategyInterface`, `WorkerExecutionContext`, `HeartbeatPublisherInterface` — are kept as-is to avoid a churn-only rename. In this document they are defined as **processing components invoked by a WP-Cron-triggered Processing Engine**, not as daemons. Wherever the word "worker" survives in a class or column name, read it as "processing component." ADR-054 authorizes this naming continuity; no rename is proposed.

---

# 2. Architectural Principles

## Principle 1 — Processing Is Infrastructure

Core owns the processing engine and its strategies; modules own the domain execution logic (subscribers, handlers, projection). Unchanged from v1.0 Principle 1.

## Principle 2 — Processing Is Stateless Between Executions

A processing component holds **no durable business state**, and additionally holds **no state between cron executions**. Each cron-triggered cycle bootstraps, does bounded work, persists operational metrics, and exits cleanly. All durable state lives in the event store, the queue, the delivery database, and the reconciliation inputs (WordPress). Extends v1.0 Principle 2 (ADR-036).

## Principle 3 — Processing Failures Must Not Cause Event Loss

At-least-once delivery, the durable outbox, visibility timeout, retries, the DLQ, replay, and reconciliation guarantee that a cycle that is interrupted, killed, or errors mid-batch loses no event. Unchanged from v1.0 Principle 3.

## Principle 4 — Execution Is Triggered, Not Supervised

Processing is advanced by the WordPress scheduler (WP-Cron) firing a bounded cycle. There is **no external process supervisor** (systemd/Supervisor/container restart policy) in the v1.x execution model, because there is no persistent process to supervise. **Replaces** v1.0 Principle 4 ("Worker Lifecycle Is Managed Externally").

## Principle 5 — Execution Logic Belongs To Modules

Subscribers and handlers are owned by modules and resolved through registries. Unchanged from v1.0 Principle 5.

## Principle 6 — Observability Is A First-Class Concern

Processing health and metrics are derived on demand from existing operational tables and emitted as structured logs (DECISION P / DECISION Q), never from a liveness daemon. Unchanged in spirit; the health *model* is redefined in §15–17.

## Principle 7 — Processing Must Be Bounded

Every cycle is bounded by a maximum batch size per stage and an execution-time budget well inside the PHP `max_execution_time` ceiling. Work that does not fit in one cycle is safely continued by the next cron execution. **Replaces** v1.0 Principle 7 ("Workers Must Scale Horizontally") — horizontal process scaling is not the v1.x scaling model; see §10.

## Principle 8 — Zero-Configuration Operation

The platform must operate immediately after plugin activation **without requiring operating-system services, external supervisors, container orchestration, or manual infrastructure configuration.** Background processing is provided entirely through WordPress scheduling (WP-Cron) in Version 1.x. This principle makes the primary product goal behind ADR-054 an explicit architectural objective, not merely an implementation detail: an operator installs the plugin and synchronization begins — nothing outside WordPress is provisioned. (New in v2.0; see §2b "Why WP-Cron?".)

---

# 2b. Why WP-Cron?

HSP Version 1.x is designed to behave like a **standard WordPress plugin.**

The platform should:

* install directly from the WordPress Plugins screen
* activate without server configuration
* begin synchronizing automatically
* require no external process manager
* require no CLI setup

For these reasons, **WP-Cron is the only supported execution mechanism in Version 1.x** (ADR-054; the product ruling). This is what makes Zero-Configuration Operation (Principle 8) achievable: every deployment target that runs WordPress can run HSP's background processing, with no supervisor, systemd unit, container restart policy, or daemon to stand up.

Future versions may introduce **additional** execution drivers (e.g. an external scheduler or a supervised runner) **without changing the underlying processing architecture** — the Processing Engine, per-event pipeline, bounded-batch model, and every correctness guarantee in this document are trigger-agnostic. Such a driver would be a new *trigger* for the same engine, ratified by its own ADR; it is out of scope for v1.x.

---

# 3. High-Level Architecture

```text
WP-Cron Trigger (scheduled event)
        ↓
Processing Engine (Core)  —  one bounded cycle
        ↓
Processing Strategy (Relay | Dispatch | Event/Projection | Maintenance)
        ↓
Execution Pipeline (per event)
        ↓
Subscriber (Module)
        ↓
Handler (Module)
        ↓
Persist operational metrics  →  Clean exit
```

A cycle is a **single PHP execution**: it starts on a cron tick, advances the pipeline by bounded batches, records operational metrics, and returns. Nothing runs between cycles.

---

# 4. Processing Ownership

## Decision

Core owns processing infrastructure. Modules own execution logic. (Unchanged from v1.0 §4; ADR-035 as amended by ADR-054.)

Architecture:

```text
Processing Engine (Core)
        ↓
Router / Registry (Core)
        ↓
Subscriber (Module)
        ↓
Handler (Module)
```

## Core Responsibilities

Core owns:

* Processing Engine (`WorkerEngine`) — bootstraps a cycle, runs a strategy over a bounded batch, records metrics, exits
* Job claiming (`FOR UPDATE SKIP LOCKED`)
* Heartbeat / processing-liveness recording (per-cycle, current-state row — DECISION P)
* Metrics derivation (DECISION Q)
* Retry coordination
* Execution context
* Tracing (correlation/causation IDs)

## Module Responsibilities

Modules own:

* Subscribers
* Handlers
* Domain logic
* Projection logic

---

# 5. Processing Components (Strategies)

## Decision

Processing components share one common Processing Engine and are specialized as strategies. (ADR-035, as amended by ADR-054 — "shared worker engine + specialized strategies" is retained; only the trigger changes from supervisor-launched daemon to cron-triggered cycle.)

## ADR-035 (as amended by ADR-054)

### Status

Accepted — **execution-model clause amended by ADR-054.**

### Decision

The platform uses a **Shared Processing Engine** (`WorkerEngine`) with **Specialized Processing Strategies**. Infrastructure concerns (claiming, batching, metrics, tracing, execution context) are not duplicated across strategy types.

> **Amendment note (ADR-054).** ADR-035's original reasoning is unchanged: a shared engine with specialized strategies avoids duplicating infrastructure. What ADR-054 amends is the **invocation model** — the shared engine is entered once per WP-Cron tick to run a bounded cycle, not started once by a supervisor and left claiming in a loop. The strategy set and the engine's per-event pipeline are unchanged.

---

# 6. Processing Strategies

The strategies, mapped onto the resolved pipeline (Doc 4 §3; DECISION L):

```text
RelayWorkerStrategy          — wp_hsp_outbox (MySQL) → system.events (PG)
DispatcherWorkerStrategy     — system.events → system.queue_jobs (anti-join, ON CONFLICT DO NOTHING)
EventWorkerStrategy          — claim system.queue_jobs → project into content.*
MaintenanceWorkerStrategy    — requeue visibility-timeout expiries (DECISION R)
ReplayWorkerStrategy         — producer-side, CLI/cron-triggered re-emission (DECISION T); execute() is a no-op
ReconciliationWorkerStrategy — producer-side, CLI/cron-triggered detection + repair (DECISION U); execute() is a no-op
```

All consumer-side strategies (Relay, Dispatch, Event/Projection, Maintenance) execute through the same Processing Engine on a bounded batch per cycle. `ReplayWorkerStrategy` and `ReconciliationWorkerStrategy` remain **producer-side** (their `execute()` returns `false` — they are triggered by CLI/WP-Cron entry points, not queue consumers — DECISION T/U D5); this is unchanged by ADR-054.

---

# 7. Per-Event Execution Pipeline

## Standard Pipeline (unchanged from v1.0 §7)

```text
Claim Job
        ↓
Load Event
        ↓
Create Execution Context
        ↓
Validate Event
        ↓
Resolve Subscriber            ← DECISION J Layer-1 stale guard reads here
        ↓
Execute Handler
        ↓
Commit State                  ← DECISION 3 three-op single-PG-transaction
        ↓
Acknowledge Job
```

This per-event pipeline is **identical** to v1.0. The DECISION J Resolve-stage stale-event guard, the DECISION 3 three-op single-PG-transaction commit (projection upsert + `system.processed_events` insert + `system.aggregate_versions` upsert), and at-least-once acknowledgement are unchanged. The only difference under ADR-054 is that this pipeline runs inside a **bounded batch within one cron cycle** rather than inside an endless supervised claim loop.

## Benefits

* Consistency — one pipeline for every event, regardless of the strategy that drove the cycle
* Observability — every step is traceable via the execution context
* Extensibility — new strategies reuse the pipeline
* Predictable, bounded execution

---

# 8. Execution Context

## Decision

Handlers receive a `WorkerExecutionContext`. (Unchanged from v1.0 §8; the class name is retained per ADR-054.)

The context contains:

```text
Event
Queue Job
Processing-Component ID   (the per-cycle self-assigned UUIDv7 — see §15)
Attempt Count
Correlation ID
Causation ID
Trace Metadata
```

## Purpose

Provides diagnostics, tracing, retry awareness, and operational visibility — unchanged from v1.0. The "Worker ID" field is retained under its existing name but is a **per-cycle processing-component identity** (§15), not a long-lived daemon identity.

---

# 9. The Processing Engine Model (Cycle)

## Decision (ADR-054)

Background processing is advanced by a **WP-Cron-triggered Processing Engine** that runs one **bounded, stateless cycle** per invocation and exits cleanly.

## Cycle shape

```text
WP-Cron tick
        ↓
Bootstrap engine (assign per-cycle component ID; record start-of-cycle heartbeat)
        ↓
Relay batch        (≤ MAX_RELAY_BATCH rows; time budget respected)
        ↓
Dispatch batch     (≤ MAX_DISPATCH_BATCH rows)
        ↓
Projection batch   (≤ MAX_PROJECTION_BATCH jobs claimed via SKIP LOCKED)
        ↓
Maintenance        (requeue visibility-timeout expiries — DECISION R — as scheduled)
        ↓
Persist operational metrics  (current-state heartbeat + derived counters — DECISION P/Q)
        ↓
Clean exit
```

Each stage is a **batch, not a loop-to-empty**: a stage processes at most its per-stage maximum and then yields to the next stage. If the queue holds more than one cycle can drain, the remainder is left durably in place and the **next cron execution continues** it (§12).

## Stateless between executions

A cycle carries nothing forward in memory. All continuation state is the **durable state already in the pipeline**: unrelayed `wp_hsp_outbox` rows (`status='pending'`), undispatched `system.events` (absent from `system.queue_jobs`), and unclaimed/expired `system.queue_jobs`. The next cycle re-derives exactly what remains from those tables. This satisfies Principle 2 and ADR-036.

## Bounded processing (per-stage maximum batch sizes)

Each stage has a configuration-driven maximum batch size (no hardcoded values; following the DECISION R config-driven-cadence precedent):

| Stage | Config key (in `config/worker.php`) | Purpose |
|---|---|---|
| Relay | `processing.relay_batch_size` | Max `wp_hsp_outbox` rows relayed per cycle |
| Dispatch | `processing.dispatch_batch_size` | Max `system.events` rows enqueued per cycle |
| Projection | `processing.projection_batch_size` | Max `system.queue_jobs` claimed + projected per cycle |

Sensible defaults are provided (e.g. 100–500 per stage). The maxima exist so a single cycle's runtime and memory stay bounded regardless of backlog size.

## Execution-time budget

A cycle carries an **execution-time budget** (`processing.cycle_time_budget_seconds`, config-driven) set well inside the environment's PHP `max_execution_time`. Before starting each stage — and, for the projection stage, before each claim iteration within the batch — the engine checks elapsed time against the budget. When the budget is reached, the engine **stops claiming new work, finishes any in-flight event's single transaction, records metrics, and exits cleanly.** The budget is the soft stop that keeps a cycle from being hard-killed by `max_execution_time`; the visibility timeout (§14, §26) is the hard-kill backstop if the process is terminated anyway.

---

# 10. Scaling Model

Horizontal *process* scaling (running N supervised daemon workers) is **not** the v1.x scaling model. Throughput is scaled by:

* **Cron frequency** — more frequent WP-Cron ticks (or an external system cron / `wp cron event run` invoked on a tighter schedule) advance the pipeline more often.
* **Batch sizes** — larger per-stage maxima drain more per cycle, bounded by the cycle time budget and available memory.

Overlapping cron executions are **safe** (§11), so a tighter cron schedule that causes two cycles to overlap does not corrupt processing — it simply increases effective throughput up to the point where SKIP LOCKED contention and the per-cycle budget balance out. The <30s sync SLA (Doc 11 §24 / Doc 4) and <60s queue-lag target (Doc 10 §24) are met by tuning cron frequency and batch size, not by adding worker processes.

> **Note.** This replaces v1.0 §9 (Multi-Process Concurrency) and §10 (Horizontal Scaling: "1 Worker or 100 Workers"). The at-least-once, aggregate-version-ordering, and visibility-timeout guarantees those sections required are preserved; the mechanism that exploits them is overlapping bounded cron cycles, not a worker pool.

---

# 11. Concurrency Model — Overlapping Cron Cycles

## Decision

Overlapping cron executions are **safe using existing guarantees only.** ADR-054 introduces **no new locking mechanism.** Safety rests entirely on three mechanisms already frozen in the architecture:

1. **`FOR UPDATE SKIP LOCKED`** on every claim (`wp_hsp_outbox` relay claim — OPEN-6; `system.events` dispatch anti-join — DECISION L (c); `system.queue_jobs` projection claim — OPEN-4).
2. **Aggregate-version ordering** — the DECISION J two-layer stale guard (Resolve-stage non-locking SELECT + in-transaction `FOR UPDATE` + `GREATEST()` monotonic upsert on `system.aggregate_versions`).
3. **Visibility timeout** — a claimed-but-uncompleted `system.queue_jobs` row (`visibility_timeout_at`) is requeued after expiry (OPEN-4 / DECISION R).

## Analysis 1 — Two overlapping cycles claiming from the same queue

Cycle A and Cycle B fire close enough to run concurrently. Both enter the projection stage and issue the OPEN-4 claim `SELECT … FOR UPDATE SKIP LOCKED LIMIT n`. Because each claimed row is row-locked and `SKIP LOCKED` skips rows locked by the other transaction, **A and B claim disjoint job sets** — no job is claimed by both, and neither blocks the other (a claimant never waits on a lock the other holds; it skips to the next available row). This is the exact property proven for concurrent claimants in GATE-S2 criterion 1 (two physical PG sessions, genuinely concurrent, no double-claim, no blocking). The same holds for the relay claim (`wp_hsp_outbox` `SKIP LOCKED`) and the dispatch anti-join (`system.events … NOT EXISTS(system.queue_jobs) FOR UPDATE SKIP LOCKED` + `ON CONFLICT(event_id) DO NOTHING` on enqueue — DECISION L (c)/(d)): two cycles dispatching the same event either claim disjoint rows or the second enqueue is a no-op via the UNIQUE(event_id) conflict. **No double-processing, no head-blocking.**

Should two cycles nonetheless both advance the *same aggregate* (e.g. a relay cycle and a projection cycle racing on a fresh event), the DECISION J guard is authoritative: the Resolve-stage read plus the in-transaction `FOR UPDATE` + `GREATEST()` upsert ensure the stored `latest_processed_version` only advances and a superseded event is acked with zero writes. This closes the Resolve→write TOCTOU window without any new lock.

## Analysis 2 — A cycle killed mid-batch (max_execution_time) recovering on the next cycle

Cycle A claims job J (sets `worker_id`, `visibility_timeout_at = now() + timeout`, `status='claimed'`), begins projecting, and is then **hard-killed** by `max_execution_time` (or a deploy, or a VPS restart) before the DECISION 3 commit and before acknowledging J. Because the projection + `processed_events` + `aggregate_versions` writes commit in **one** PG transaction (DECISION 3), a kill before commit leaves **no partial projection** — the transaction rolls back atomically. J remains `claimed` with an unexpired-then-expiring `visibility_timeout_at`. On a later cron tick, `MaintenanceWorkerStrategy` (DECISION R) requeues J once `visibility_timeout_at` has passed; the **next** projection cycle re-claims and reprojects it. If J had in fact committed just before the kill (committed but not yet acked), the requeued re-delivery is absorbed idempotently: the DECISION J stale guard / DECISION 3 `processed_events` dedup ack it with zero writes. Either way the outcome is correct — **at-least-once with idempotent redelivery** (Principle 3; ADR-036).

## No new locking mechanism

The two analyses are fully covered by SKIP LOCKED + aggregate versioning + visibility timeout + the DECISION 3 atomic commit. **ADR-054 adds no cron-lock, no advisory lock, no "singleton cycle" mutex.** Overlap is a throughput lever, not a hazard.

> **Gap check (explicit).** No gap was found that would require a new locking mechanism. A belt-and-suspenders single-flight guard (e.g. a WP transient or a PG advisory lock to prevent overlap entirely) is **deliberately not introduced** — introducing one would be an unratified new mechanism, and the existing guarantees already make overlap safe. If a future workload proves a gap (e.g. cron storms causing pathological contention), that requires a new DECISION before any lock is added — it must not be improvised.

---

# 12. Continuation Across Cron Runs

Because each stage is bounded (§9) and cycles are stateless (§9), a backlog larger than one cycle is drained across **successive** cron executions:

```text
Cron tick N   : relay 100, dispatch 100, project 100  → 300 events advanced, budget hit, exit
Cron tick N+1 : re-derive remaining from durable tables → relay/dispatch/project the next batch
Cron tick N+2 : … continues until the pipeline is empty; then cycles are cheap no-ops
```

Continuation state is never held in memory or in a checkpoint row — it is the **residual durable state** in `wp_hsp_outbox`, `system.events`, and `system.queue_jobs`. A cycle that finds nothing to do performs its claim queries (which return zero rows), records a heartbeat, and exits — an idle cycle is cheap and safe. This is the mechanism that lets a bounded per-cycle engine process an unbounded backlog without ever running long.

---

# 13. Resource Management

A cycle is bounded by construction, so the v1.0 concerns of memory drift and long-running-PHP leak accumulation **do not apply** — the PHP process exits at the end of every cycle and is recreated fresh on the next tick.

A cycle monitors, for the current execution only:

```text
Elapsed time vs. cycle time budget
Peak memory vs. a configured soft cap (optional early exit)
Jobs processed this cycle
```

There is **no worker recycling, no max-jobs-then-restart, no max-runtime-then-restart** — those v1.0 mechanisms (§13 in v1.0) existed only to bound a daemon that never exits. A cron cycle already exits; recycling is a no-op concept here and is **removed**.

---

# 14. Failure Isolation

Job failures and processing failures are separate concerns (unchanged from v1.0 §14).

```text
Handler Failure
        ↓
Job Failure (retry / eventually DLQ per ADR-022 retry limit)
        ↓
Cycle continues to the next job in the batch (or exits cleanly at budget)
```

A single bad event (e.g. an invalid entity) fails its own job and is retried or dead-lettered; it does **not** abort the cycle's remaining batch beyond its own job, and it never leaves a partial projection (DECISION 3 atomicity). A crash of the whole cycle is recovered by visibility timeout (§11 Analysis 2, §26). "Worker remains healthy" (v1.0) becomes "the next cycle proceeds unaffected."

---

# 15. Processing Health (replaces Worker Health Monitoring)

There are no long-running workers to be "up" or "down", so **liveness of a daemon is not the health signal.** Processing health is expressed as the **freshness and progress of processing cycles**, derived on demand (DECISION P / DECISION Q).

Each cycle records a current-state heartbeat row per DECISION P (`system.worker_heartbeats`, single row per processing-component identity, upserted per cycle — schema unchanged):

```text
worker_id          — per-cycle UUIDv7 processing-component identity (self-assigned at cycle bootstrap)
worker_type        — 'relay' | 'dispatch' | 'event' | 'maintenance'
status             — 'running' | 'idle'  (a cycle is running while executing, idle when it found no work)
last_heartbeat_at  — updated at cycle start/end; "processing freshness" reads this
started_at         — this cycle's start time
```

> **Table-name note (implementation continuity).** The existing `system.worker_heartbeats` table is **retained in Version 1.x for implementation continuity and migration stability.** Although it now represents **processing-cycle health rather than daemon-worker health**, the schema name remains unchanged (DECISION P is not re-migrated by ADR-054). A future major version may introduce a dedicated schema migration to rename it to `system.processing_heartbeats` (or an equivalent name); until then, contributors must read `worker_heartbeats` / `worker_id` / `worker_type` as **processing-cycle** identifiers, not daemon-worker identifiers. This mirrors the class-name continuity policy (§Terminology; ADR-054 §8): no churn-only rename in v1.x.

**Processing health signals** (all derived on demand, no new persistence):

| Signal | Source | Meaning |
|---|---|---|
| Last cron execution | most recent `last_heartbeat_at` across rows | when a cycle last ran at all |
| Last successful cycle | most recent cycle that completed its batch without error (from `last_heartbeat_at` + structured logs) | processing is advancing |
| Per-stage last-run | `worker_type`-scoped `last_heartbeat_at` | each stage is being exercised |
| Queue depth | `COUNT(*) FROM system.queue_jobs WHERE status='queued'` | backlog size |
| Oldest pending job | `MIN(available_at)` over queued jobs | how stale the head is |
| Processing lag | now − oldest unprocessed event/outbox row | end-to-end latency |

A **stalled pipeline** is detected when `last_heartbeat_at` age exceeds a config threshold **while** queue depth is non-zero — i.e. there is work but no recent cycle advanced it (cron not firing, or every cycle erroring). This replaces the v1.0 "worker offline within one heartbeat cycle" signal; the DECISION P age-check mechanism is reused verbatim, only its interpretation changes from "a daemon crashed" to "cycles are not advancing the backlog."

> **Heartbeat is progress, not liveness.** Under ADR-054 a fresh heartbeat means "a cycle ran recently," not "a process is alive." Do not treat a missing heartbeat as a dead daemon to restart — there is nothing to restart; treat it as "cron may not be firing" and follow the runbook (Doc 10 §26).

---

# 16. Processing Status States

A cycle (and its per-`worker_type` row) reports one of:

```text
running    — a cycle is executing this stage
idle       — the last cycle found no work and exited
```

The v1.0 daemon lifecycle states `starting`, `recycling`, `stopping`, `failed` are **removed** — a cron cycle has no long-lived lifecycle to model; it either ran (`running`/`idle`) or its heartbeat is stale (detected by age, §15). A cycle that errors is visible via structured error logs and a non-advancing `last_successful_cycle`, not a persisted `failed` state.

---

# 17. Observability Requirements

Processing exposes (all derived on demand or emitted as structured logs — DECISION Q; no metrics table, no rollups, no time-series store):

```text
Cycles completed            (count over a window, from structured logs / heartbeat updates)
Avg cycle duration          (from per-cycle start/end timestamps)
Per-stage throughput        (relay/dispatch/projection events per cycle and per window)
Queue backlog               (queue depth; DLQ depth)
Processing lag              (now − oldest unprocessed event)
Oldest pending job age
Failure rate / retry rate / DLQ rate   (structured-log counters)
```

These **replace** the v1.0 worker-centric metrics. In particular:

* `worker_uptime` → **removed** (no process to have uptime). Use **cycles completed** + **last cron execution**.
* `restart_count` → **removed** (nothing restarts). Use **failure rate** / **last successful cycle** instead.
* `worker_count` (v1.0 / Doc 4 §17) → reinterpreted as **the count of distinct processing-component rows that heartbeated within the freshness window** (i.e. how many cycles/stages ran recently), not a live-daemon population.

Observability remains mandatory.

---

# 18. Event Traceability

## ADR-037 (unchanged)

### Status

Accepted

### Decision

Every event carries:

```text
correlation_id
causation_id
```

ADR-037 is fully preserved by ADR-054 — tracing is a property of events, independent of how processing is triggered.

---

# 19. Correlation ID Rules (unchanged)

Correlation ID identifies the **root business transaction** and remains unchanged throughout the event chain. A root event sets `correlation_id = event_id`. (Unchanged from v1.0 §19.)

---

# 20. Causation ID Rules (unchanged)

Causation ID identifies the **immediate parent event**: for `Event A → Event B`, `B.causation_id = A.event_id`. Root events carry `causation_id = NULL` (OPEN-6 outbox DDL). (Unchanged from v1.0 §20.)

Synthetic re-emissions from replay/reconciliation (DECISION T/U) carry a `causation_id` referencing the replay/reconcile operation and share one `correlation_id` per run — unchanged.

---

# 21. Traceability Example (unchanged)

```text
Post Updated
        ↓
(future) Search Reindexed
        ↓
(future) Analytics Updated
```

All events in a chain share the same `correlation_id`; each references its immediate parent via `causation_id`. (Unchanged from v1.0 §21; the MVP chain is content-only.)

---

# 22. Processing Resolution (Registry-Based)

## Decision

Processing resolves subscribers and handlers through **registries** — unchanged from v1.0 §22.

```text
Event Type
        ↓
Event Registry
        ↓
Subscriber
        ↓
Handler
```

Benefits: loose coupling, module independence, extensibility. Registry-based resolution is entirely unaffected by the trigger change.

---

# 23. Trigger Architecture (replaces Supervisor Architecture)

## Decision (ADR-054)

Processing is advanced by the **WordPress scheduler (WP-Cron)**. There is **no external process supervisor** in the v1.x execution model.

Supported trigger:

```text
WP-Cron scheduled event  →  one bounded Processing Engine cycle
```

Operationally, WP-Cron may itself be driven either by WordPress's default request-triggered cron or, for reliable cadence, by a **system cron / scheduled task invoking `wp cron event run --due-now`** (a *trigger* for WP-Cron, not a long-running worker). Invoking the cycle this way is a scheduling detail; it does not reintroduce a daemon — each invocation still runs one bounded cycle and exits.

Processing components must not:

* Self-respawn or fork
* Manage sibling processes
* Manage cluster topology
* Run a claim loop that does not terminate

> **Supersedes v1.0 §23 (Supervisor Architecture).** systemd, Supervisor, and container restart policies are **not** part of the v1.x execution model. The v1.0 "worker lifecycle is externally managed" decision is replaced by "processing is triggered by the scheduler and each invocation is bounded." (Docs 10 §7/§27 systemd/Supervisor templates and worker launch scripts are conflicting artifacts — see the ADR-054 conflict report; they are not authorized under this document.)

---

# 24. Cycle Startup Flow (replaces Worker Startup Flow)

```text
WP-Cron tick
        ↓
Bootstrap Processing Engine (load config, resolve strategies via container)
        ↓
Assign per-cycle processing-component ID (UUIDv7)
        ↓
Record start-of-cycle heartbeat (DECISION P upsert)
        ↓
Begin bounded batch (per §9)
```

There is no persistent "begin claim loop" — the cycle runs its bounded batches and proceeds to shutdown (§25).

---

# 25. Cycle Shutdown Flow (replaces Worker Shutdown Flow)

```text
Batch complete OR time budget reached
        ↓
Stop claiming new work
        ↓
Finish the in-flight event's single transaction (DECISION 3) — never abandon a half-written projection
        ↓
Persist operational metrics + final heartbeat
        ↓
Exit (PHP process ends)
```

A clean exit at the batch boundary or the time budget is the **normal** end of every cycle — it is not a "graceful shutdown on a signal" (there is no signal and no daemon). If the process is instead hard-killed mid-transaction, DECISION 3 atomicity + visibility timeout recover it (§11 Analysis 2). "Graceful shutdown is required" (v1.0 §25) becomes "clean exit at the batch/budget boundary is the norm; hard kills are recovered by visibility timeout."

---

# 26. Recovery

Recovery mechanisms (unchanged set; the trigger is now cron):

```text
Next Cron Execution     — re-derives and continues residual durable work (§12)
Queue Durability        — claimed-but-uncompleted jobs persist in system.queue_jobs
Visibility Timeout      — expired claims are requeued by MaintenanceWorkerStrategy (DECISION R)
Retries                 — per-job retry budget (ADR-022) before DLQ
Dead Letter Queue       — terminal failures preserved (OPEN-3, DECISION A); replayable (DECISION S)
Replay                  — synthetic re-emission repair (DECISION T)
Reconciliation          — drift/incremental/full repair via re-emission (DECISION U)
```

Processing must recover safely from:

* A cycle hard-killed by `max_execution_time`
* VPS restarts
* Deployments (a deploy that kills an in-flight cycle)
* WP-Cron not firing for a period (the next tick continues the backlog; nothing is lost)

The recovery guarantees are exactly those of v1.0 §26, with **"next cron execution"** taking the place of "a supervised worker restart" as the primary re-drive mechanism. Because all continuation state is durable (§12), a gap in cron firing delays processing but never loses an event.

---

# 27. Processing Metrics

Minimum metrics (derived on demand / structured logs — DECISION Q):

```text
cycles_completed
average_cycle_duration
per_stage_throughput        (relay / dispatch / projection)
queue_backlog               (queue depth; DLQ depth)
processing_lag              (now − oldest unprocessed event)
oldest_pending_job_age
jobs_processed / jobs_failed / jobs_retried / jobs_dead_lettered   (structured-log counters)
```

**Removed from the v1.0 §27 set:** `worker_uptime` (no process uptime) and `restart_count` (nothing restarts). See §17 for the replacements. `average_processing_time` (per event) is retained as a structured-log counter; `memory_usage` is retained as a per-cycle diagnostic only (not a leak-tracking metric).

Purpose: capacity planning, alerting, performance analysis — unchanged.

---

# 28. Security Considerations (unchanged)

Processing must:

* Use least-privilege credentials
* Respect queue permissions
* Respect database permissions
* Emit security audit events when required (`system.security_events`, OPEN-8)

Credentials are resolved via `CredentialResolver` (DECISION O) and must not be embedded in code. Unchanged from v1.0 §28.

---

# 29. Testing Strategy

Processing infrastructure is tested independently of domain handlers. Under ADR-054 the test surface targets **cycles and bounded batches**, not daemon lifecycle:

```text
Processing cycle           — a single cron-triggered cycle advances relay → dispatch → projection and exits
Batch execution            — a stage processes at most its configured maximum, leaving the remainder durable
Cron-triggered execution   — the WP-Cron callback runs exactly one bounded cycle (no loop, no daemon)
Bounded processing          — a cycle stops at the time budget mid-backlog, finishes its in-flight transaction, exits cleanly
Recovery across cycles     — a backlog larger than one cycle drains across successive cron executions;
                             a cycle killed mid-batch is recovered by visibility timeout on the next cycle
Concurrency safety         — two overlapping cycles claim disjoint jobs (SKIP LOCKED), never double-process,
                             never head-block (mirrors GATE-S2 criterion 1)
Idempotent redelivery      — a committed-but-unacked job re-delivered on the next cycle is acked with zero writes
Job claiming / retry / heartbeat-per-cycle / execution-context creation / pipeline execution
```

Domain handlers are tested separately. The v1.0 "heartbeat updates" test is retained but reframed as "a per-cycle current-state heartbeat is upserted and its age drives stall detection" (§15).

> These processing-cycle / bounded-batch / recovery-across-cycles behaviours are the DoD acceptance surface for any ADR-054 implementation session. No implementation code is authored by this document.

---

# 30. Approval Checklist

* [x] Zero-Configuration Operation principle defined (Principle 8) + "Why WP-Cron?" rationale (§2b)
* [x] Heartbeat table-name continuity note recorded (`system.worker_heartbeats` retained in v1.x; future rename noted)
* [x] Processing ownership defined (core engine + module logic)
* [x] Shared Processing Engine + specialized strategies retained (ADR-035 as amended by ADR-054)
* [x] Processing strategies defined (implementation class names retained)
* [x] Per-event execution pipeline preserved (Claim→Load→Context→Validate→Resolve→Execute→Commit→Ack)
* [x] Execution context preserved
* [x] Processing Engine cycle model defined (WP-Cron trigger → bounded batches → metrics → clean exit)
* [x] Max batch sizes per stage + execution-time budget + bounded processing + continuation across cron runs defined
* [x] Overlapping-cron concurrency proven safe via existing guarantees only (SKIP LOCKED + aggregate versioning + visibility timeout); no new locking mechanism introduced
* [x] Scaling model = cron frequency + batch size (not a worker pool)
* [x] Stateless-between-executions design defined (ADR-036 extended)
* [x] Failure isolation preserved
* [x] Processing health (cycle freshness / progress) replaces worker-liveness health
* [x] Processing metrics (cycles/duration/throughput/backlog/lag) replace worker_uptime/restart_count
* [x] Correlation/causation ID rules preserved (ADR-037)
* [x] Registry-based resolution preserved
* [x] Trigger architecture (WP-Cron) replaces supervisor architecture
* [x] Recovery architecture defined (next cron execution + durability + visibility timeout + replay + reconciliation)
* [x] Testing strategy targets cycles / bounded batches / recovery across cron runs
* [x] Daemon/supervisor/systemd/recycling/heartbeat-as-liveness assumptions removed

---

# Approval Status

**Version:** 2.0

**Status:** Approved

**State:** Frozen

**Authorized by:** ADR-054 (ARCHITECTURE_DECISIONS.md) — supersedes ADR-024's execution-model decision; amends ADR-035 and ADR-036 for the daemon assumption; preserves ADR-037 and all pipeline/queue/idempotency guarantees.

This document is the authoritative Background Processing & Execution Architecture specification for the Headless Sync Platform, v1.x.
