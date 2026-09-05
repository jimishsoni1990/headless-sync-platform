# Operations, Deployment & Runtime Architecture

**Project:** Headless Sync Platform (HSP)
**Version:** 1.1
**Status:** Approved
**State:** Frozen

**Amended by ADR-054 (2026-07-17; applied 2026-09-05).** Every execution-model assumption in this
document is superseded by the WP-Cron Processing Engine model in **Document 8 v2.0** and
**ADR-054** (ARCHITECTURE_DECISIONS.md): HSP v1.x runs background work **only** as bounded,
stateless WP-Cron processing cycles — **no** CLI daemons, WP-CLI worker processes, Supervisor,
systemd units, or container-restart workers, and process supervision is **never** a support
requirement (Principle 8 — zero-configuration operation). Affected sections carry an explicit
`> **AMENDED BY ADR-054**` banner: **§4/§5** (topologies), **§7** (worker execution strategy),
**§20** (worker monitoring), **§23** (alerting), **§24** (worker availability target), **§26**
(runbooks), **§27** (deployment tooling boundary), **§28** (compatibility matrix). Superseded text
is retained as history and must not be cited as current authority. All non-execution content
(environments, config, upgrades, migrations, rollback, backup, recovery, queue/API monitoring,
capacity planning, DR) is **unchanged and current**.

**Depends On:**

* Document 1 — Technical Architecture Specification
* Document 2 — Plugin Folder Structure & Code Organization
* Document 3 — Database Design & Persistence Architecture
* Document 4 — Queue & Event Processing Architecture
* Document 5 — Event Architecture & Contract Design
* Document 6 — Transformer Architecture & Canonical Model Design
* Document 7 — Adapter Architecture & Delivery Projection Design
* Document 8 v2.0 — Background Processing & Execution Architecture
* Document 9 — Delivery API & Consumption Architecture
* ARCHITECTURE_DECISIONS.md — **ADR-054** (authoritative execution-model ruling)

---

# 1. Purpose

This document defines the operational architecture of the Headless Sync Platform.

It establishes:

* Deployment models
* Runtime architecture
* Environment strategy
* Configuration management
* Upgrade procedures
* Rollback procedures
* Backup strategy
* Recovery strategy
* Monitoring
* Alerting
* Operational ADRs
* Performance targets
* Operational runbooks

This document is the authoritative specification governing production operations.

---

# 2. Architectural Principles

## Principle 1

Operational Simplicity Over Infrastructure Complexity.

---

## Principle 2

WordPress Remains The Source Of Truth.

---

## Principle 3

Events Are Durable.

---

## Principle 4

Queue Jobs Are Disposable.

---

## Principle 5

Workers Are Stateless.

---

## Principle 6

Recovery Must Be Possible Without Data Loss.

---

## Principle 7

Operational Visibility Is Mandatory.

---

# 3. Runtime Architecture

```text
WordPress
        ↓
Outbox
        ↓
Queue Provider
        ↓
Workers
        ↓
Transformers
        ↓
Adapters
        ↓
PostgreSQL
        ↓
Delivery API
        ↓
Consumers
```

---

# 4. Supported Deployment Topologies

> **AMENDED BY ADR-054.** "Workers" in the topology diagrams below is **not** a separate process
> or tier: processing cycles run inside the WordPress PHP runtime, triggered by WP-Cron. Topology B
> therefore splits **PostgreSQL and the delivery API**, not a worker fleet, and Topology C's
> "Multiple Workers" means **higher cron frequency / larger batches / overlapping cycles**
> (ADR-054 §4), never supervised daemon processes.

## Topology A — Single Server

```text
WordPress

Workers

PostgreSQL

Delivery API

(Optional Redis)

Single VPS
```

### Intended For

* Development
* Small sites
* Initial production deployments

---

## Topology B — Split Services

```text
WordPress + API
        ↓
Workers
        ↓
PostgreSQL
```

Separate infrastructure.

### Intended For

* Medium traffic
* WooCommerce deployments
* Dedicated production environments

---

## Topology C — Horizontally Scaled

```text
WordPress
        ↓
Multiple Workers
        ↓
PostgreSQL

(Optional Redis)
```

### Intended For

* High-volume commerce
* Large content platforms
* Heavy synchronization workloads

---

# 5. Topology Migration Rule

The platform must support moving between deployment topologies without architectural redesign.

Example:

```text
Single Server
        ↓
Split Services
        ↓
Horizontally Scaled
```

without application changes.

---

# 6. Redis Strategy

## Decision

Redis is optional.

---

Phase 1 requirements:

```text
WordPress

PostgreSQL

Workers
```

only.

---

Redis may be introduced later for:

* Queue providers
* Caching
* Performance optimization

---

Redis is not a platform requirement.

---

# 7. Worker Execution Strategy

> **AMENDED BY ADR-054 — this section is rewritten; the superseded v1.0 text follows as history.**

## Production Standard (ADR-054)

WP-Cron is the **only** background execution mechanism in v1.x.

---

```text
hsp_processing_cycle (recurring WP-Cron event)
        ↓
ONE bounded, stateless Processing Engine cycle
        ↓
relay → dispatch → project → maintenance
        ↓
exit before max_execution_time
```

A backlog larger than one cycle is continued by the next tick. Nothing is supervised, nothing is
long-running, and nothing needs to be restarted.

---

## Reliable Cadence (optional, recommended)

WP-Cron only fires on site traffic. For deterministic cadence, point a system scheduler at:

```text
wp cron event run --due-now
```

This is a **trigger** for WP-Cron, not a daemon: each invocation still runs one bounded cycle and
exits (ADR-054 §23). It is optional — the platform operates with zero configuration on plain
WordPress hosting (Principle 8).

---

## Not Supported in v1.x

* CLI worker daemons / WP-CLI long-running workers
* systemd units, Supervisor programs, container-restart workers
* Any execution path that bypasses `hsp_processing_cycle`

---

## Superseded v1.0 text (history — not current authority)

> CLI workers are required. Example: `WP-CLI Workers` running under systemd / Supervisor /
> Container Runtime. WP-Cron may execute recovery jobs, safety checks, emergency processing.
> WP-Cron is not the primary execution mechanism.
>
> **This is exactly the decision ADR-054 inverts** (see ADR-024 in Doc 4 §20).

---

# 8. Environment Strategy

Required environments:

```text
Local

Development

Staging

Production
```

---

Each environment must have:

* Independent databases
* Independent queues
* Independent credentials
* Independent configuration

---

Shared infrastructure between environments is prohibited.

---

# 9. Configuration Management

## Configuration Hierarchy

```text
Environment Variables
        ↓
Platform Configuration
        ↓
Module Configuration
```

---

## Secrets

Secrets must never be stored in:

```text
wp_options
```

or source code.

---

Examples:

```text
Database Credentials

API Keys

Queue Credentials

Search Credentials
```

---

Secrets belong in environment configuration.

---

# 10. Upgrade Architecture

## Supported Upgrade Types

### Platform Upgrade

```text
Core Platform
```

---

### Module Upgrade

```text
Content Module

WooCommerce Module

Future Modules
```

---

### Schema Upgrade

```text
Core Migrations

Module Migrations
```

---

### Worker Upgrade

```text
Worker Runtime

Execution Strategies
```

---

# 11. Migration Strategy

## Migration Engine

Core owns:

```text
Migration Runner
```

---

Responsibilities:

* Execute migrations
* Track versions
* Validate execution
* Support rollback

---

Migration execution order:

```text
Core Migrations
        ↓
Module Migrations
```

---

# 12. Rollback Strategy

## Supported Rollbacks

### Application Rollback

Plugin version rollback.

---

### Module Rollback

Module version rollback.

---

### Schema Rollback

Migration rollback.

---

# 13. Rollback Protection Rules

Rollbacks must never destroy:

```text
Events

Audit Records

Queue History

Schema Version Records
```

---

Rollback safety takes priority over rollback speed.

---

# 14. Backup Strategy

## Mandatory Backups

### PostgreSQL

Daily minimum.

---

### Event Store

Included.

---

### Audit Data

Included.

---

### Schema Metadata

Included.

---

# 15. Backup Validation

Backups are not considered valid until restore testing succeeds.

---

Required:

```text
Backup
        ↓
Restore Test
        ↓
Verification
```

---

# 16. Recovery Architecture

The platform supports:

```text
Replay

Reconciliation

DLQ Recovery

Worker Recovery

Database Recovery
```

---

Recovery workflows must prioritize correctness over speed.

---

# 17. Recovery Authority

## ADR-041

### Status

Accepted

### Decision

WordPress remains the authoritative source of truth.

---

If divergence exists:

```text
WordPress
        ↓
Repair Delivery State
```

---

Never:

```text
PostgreSQL
        ↓
Repair WordPress
```

---

# 18. Operational ADRs

## ADR-042

### Events Are Durable

Events are the authoritative synchronization history.

Events must survive:

* Worker failures
* Queue failures
* Process crashes

---

## ADR-043

### Queue Jobs Are Disposable

Queue jobs are processing artifacts.

Queue jobs may be recreated from events.

---

## ADR-044

### Workers Are Stateless

Workers maintain no durable business state.

---

## ADR-045

### WordPress Wins Reconciliation

When conflict exists:

```text
WordPress Wins
```

always.

---

# 19. Monitoring Architecture

## Three Pillars

### Metrics

Examples:

```text
Queue Lag

Processing Rate

Worker Count

Replay Rate
```

---

### Logs

Examples:

```text
Retries

Failures

DLQ Entries

Migration Failures
```

---

### Traces

Examples:

```text
Correlation ID

Causation ID
```

---

All three are required.

---

# 20. Worker Monitoring

> **AMENDED BY ADR-054 §5–6.** These are **processing-cycle** metrics, not daemon metrics.
> `uptime` is **removed** — a cycle has no uptime — and `heartbeat_age` is **cycle freshness**
> ("are cycles advancing?"), not liveness. `worker_id` is the fresh UUIDv7 of the last cycle
> (DECISION X (1)); `status` is the cycle state set (`running` / `idle`). Metrics are derived
> on-demand (DECISION Q), never newly persisted.

Minimum processing-cycle metrics:

```text
worker_id (per-cycle UUIDv7)

status

memory_usage

jobs_processed

jobs_failed

heartbeat_age (cycle freshness)
```

---

# 21. Queue Monitoring

Minimum queue metrics:

```text
queue_depth

queue_lag

retry_count

dlq_count

processing_rate
```

---

# 22. API Monitoring

Minimum API metrics:

```text
request_count

response_time

cache_hit_rate

cache_miss_rate

error_rate
```

---

# 23. Alerting Strategy

> **AMENDED BY ADR-054 §5.** "Worker Offline" does not exist — there is no process to be offline.
> The equivalent condition is **processing stalled**: the heartbeat is stale (no cycle within the
> freshness window) *while* the queue is non-empty. Remediation is "make WP-Cron fire" (traffic, or
> `wp cron event run --due-now`), never "restart the worker".

Minimum alert conditions:

```text
Processing Stalled (stale heartbeat while queue non-empty)

Queue Lag Threshold Exceeded

DLQ Growth

Replay Failure

Migration Failure

Reconciliation Failure
```

---

Alerts must be actionable.

---

# 24. Performance Targets

## Queue Lag

Target:

```text
< 60 seconds
```

during normal operation.

---

## Sync Delay

Target:

```text
< 30 seconds
```

for standard updates.

---

## Processing Freshness

> **AMENDED BY ADR-054 §5.** The v1.0 "Worker Availability: 99.9%" target is **removed** — there is
> no persistent process to have an availability figure. The equivalent target is cycle cadence.

Target:

```text
A completed processing cycle within the configured freshness window
```

whenever the queue is non-empty. A stale heartbeat with an empty queue is **normal idle**, not a
fault.

---

## Replay

Target:

```text
Zero Data Loss
```

takes priority over replay speed.

---

# 25. Reconciliation Targets

Approved schedules:

```text
Hourly Drift Detection

Nightly Validation

Weekly Full Reconciliation
```

---

Schedules remain configurable.

---

# 26. Operational Runbooks

> **AMENDED BY ADR-054.** The "Worker Failure" runbook becomes **"Processing Stalled / Cron Not
> Firing"** — diagnose WP-Cron scheduling and site traffic, not a crashed process.

The platform must provide runbooks for:

```text
DLQ Recovery

Processing Stalled / Cron Not Firing

Queue Backlog

Replay Execution

Reconciliation Execution

Migration Failure

Rollback Execution
```

---

Runbooks must be maintained alongside releases.

---

# 27. Deployment Tooling Boundary

## Decision

The platform provides operational reference assets.

The platform does not provide infrastructure orchestration.

---

> **AMENDED BY ADR-054.** **systemd Templates**, **Supervisor Templates** and **Worker Launch
> Scripts** are **removed** from the supported asset list — they presuppose supervised daemons,
> which v1.x does not have. The only cadence asset is an optional system-cron example invoking
> `wp cron event run --due-now` (a WP-Cron trigger, not a daemon).

Supported:

```text
Docker Examples

docker-compose Examples

Environment Templates

System-Cron Trigger Example (wp cron event run --due-now)

Health Check Examples

Backup Examples
```

---

Not Supported:

```text
Terraform Ownership

Kubernetes Ownership

Cloud Provisioning

Infrastructure Automation Frameworks
```

---

# 28. Infrastructure Compatibility Matrix

## Supported

```text
VPS Deployments

Enhance Control Panel

Dedicated Servers

Docker Deployments
```

---

> **AMENDED BY ADR-054 / Principle 8.** Shared hosting **without** CLI access or process
> supervision is a **first-class supported target** — that is the point of WP-Cron-only execution.
> Neither CLI access nor process supervision may gate support.

## Supported (continued)

```text
Shared Hosting (no CLI, no process supervision required)
```

Requires only: PHP with the `pgsql` extension, reachable PostgreSQL, and WP-Cron enabled.

---

## Supported With Limitations

```text
Sites with DISABLE_WP_CRON set and no external trigger
```

Processing only advances when something fires WP-Cron. Point a system scheduler at
`wp cron event run --due-now`, or re-enable WP-Cron.

---

## Unsupported

```text
Environments Without the pgsql PHP Extension

Environments That Cannot Reach PostgreSQL

Environments With WP-Cron Permanently Disabled And No External Trigger
```

---

# 29. Capacity Planning

The platform must monitor growth of:

```text
Events

Queue Jobs

Delivery Data

Audit Records

API Traffic
```

---

Capacity planning should occur before resource exhaustion.

---

# 30. ADR-046

## Operational Simplicity Over Infrastructure Complexity

### Status

Accepted

### Decision

The platform prioritizes operational simplicity.

Examples:

```text
PostgreSQL First

Database Queue First

Redis Optional

Single Server Supported
```

---

Additional infrastructure should be introduced only when justified by workload requirements.

---

### Consequence

The platform remains:

* Easy to adopt
* Easy to operate
* Easy to scale

without forcing unnecessary infrastructure complexity.

---

# 31. Security Operations

Operational security requirements:

* Credential rotation
* Secret management
* Least-privilege access
* Audit event retention
* Backup protection

---

Credentials must never be embedded in source code.

---

# 32. Disaster Recovery Objectives

## Recovery Point Objective (RPO)

Target:

```text
Near-Zero Data Loss
```

using:

* Durable events
* Backups
* Replay

---

## Recovery Time Objective (RTO)

Target:

```text
Operational Recovery Within Defined Runbook Procedures
```

---

Exact values may vary by deployment.

---

# 33. Approval Checklist

* [x] Deployment topologies defined
* [x] Redis strategy defined
* [x] Worker execution strategy approved
* [x] Environment strategy approved
* [x] Configuration hierarchy defined
* [x] Upgrade strategy defined
* [x] Migration strategy defined
* [x] Rollback strategy defined
* [x] Backup strategy defined
* [x] Recovery strategy defined
* [x] Operational ADRs defined
* [x] Monitoring architecture defined
* [x] Alerting strategy defined
* [x] Performance targets defined
* [x] Runbooks defined
* [x] Deployment tooling boundary defined
* [x] Compatibility matrix defined
* [x] Capacity planning defined
* [x] Disaster recovery objectives defined

---

# Approval Status

**Version:** 1.1

**Status:** Approved

**State:** Frozen

This document is the authoritative Operations, Deployment & Runtime Architecture specification for the Headless Sync Platform.
