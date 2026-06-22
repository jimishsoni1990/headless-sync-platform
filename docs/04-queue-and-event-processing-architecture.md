# Queue & Event Processing Architecture

**Project:** Headless Sync Platform (HSP)
**Version:** 1.0
**Status:** Approved
**State:** Frozen

**Depends On:**

* Document 1 — Technical Architecture Specification
* Document 2 — Plugin Folder Structure & Code Organization
* Document 3 — Database Design & Persistence Architecture

---

# 1. Purpose

This document defines the event processing, queueing, worker execution, retry, replay, reconciliation, and recovery architecture of the Headless Sync Platform.

The primary objective is:

```text
Never Lose An Event
```

The platform accepts:

```text
Delayed Processing
Duplicate Processing
```

The platform does not accept:

```text
Event Loss
```

---

# 2. Architectural Principles

## Principle 1

Events Are Durable.

---

## Principle 2

Queue Jobs Are Disposable.

---

## Principle 3

Workers Are Stateless.

---

## Principle 4

Processing Is Idempotent.

---

## Principle 5

At-Least-Once Delivery.

---

## Principle 6

WordPress Remains Source Of Truth.

---

## Principle 7

Correct Final State Is More Important Than Sequential Event Execution.

---

# 3. High-Level Processing Flow

```text
WordPress Hook
        ↓
Event Builder
        ↓
Outbox
        ↓
Dispatcher
        ↓
Queue Provider
        ↓
Worker
        ↓
Module Handler
        ↓
Transformer
        ↓
Adapter
        ↓
PostgreSQL
```

---

# 4. Synchronization Model

## ADR-017

### Status

Accepted

### Decision

The platform is a state synchronization platform.

The platform is not an event-sourced platform.

Workers load current source state during processing.

Workers do not rebuild delivery state from event payloads.

---

### Reasoning

The objective is:

```text
Correct Final State
```

not:

```text
Historical State Reconstruction
```

---

### Consequence

Events act as synchronization triggers.

Events are not authoritative state containers.

---

# 5. Delivery Guarantee

## ADR-018

### Status

Accepted

### Decision

The platform uses:

```text
At-Least-Once Delivery
```

---

### Consequence

Duplicate processing may occur.

Event loss must not occur.

---

### Requirement

All handlers, transformers, adapters, and persistence operations must be idempotent.

---

# 6. Event Source Of Truth

## ADR-019

### Status

Accepted

### Decision

The Outbox is the authoritative source of synchronization history.

Queue providers are transport mechanisms.

---

### Recovery Rule

If queue state is lost:

```text
Outbox
    ↓
Queue Rebuild
    ↓
Resume Processing
```

must be possible.

---

# 7. Event Durability Model

## ADR-020

### Status

Accepted

### Decision

Events are durable.

Queue jobs are disposable.

---

### Reasoning

The platform guarantees:

```text
Never Lose An Event
```

not:

```text
Never Lose A Queue Job
```

---

# 8. Event Envelope

Every event follows a versioned envelope.

Example:

```json
{
  "event_id": "uuidv7",
  "event_type": "product.updated",
  "event_version": 1,
  "aggregate_type": "product",
  "aggregate_id": "123",
  "aggregate_version": 42,
  "source_updated_at": "2026-01-01T10:00:00Z",
  "created_at": "2026-01-01T10:00:01Z",
  "checksum": "sha256",
  "metadata": {}
}
```

---

# 9. Event Versioning

All events must include:

```text
event_version
```

Purpose:

* Schema evolution
* Backward compatibility
* Replay safety

---

# 10. Aggregate Versioning

## ADR-021

### Status

Accepted

### Decision

Aggregate ordering uses version tracking.

Aggregate locking is not used in Phase 1.

---

### Event Requirement

Events include:

```text
aggregate_version
```

---

### Worker Rule

If:

```text
event.aggregate_version
<=
latest_processed_version
```

then:

```text
Skip Event
Mark Complete
```

because the event is stale.

---

### Reasoning

The platform requires:

```text
Correct Final State
```

rather than strict event execution ordering.

---

# 11. Aggregate Version Tracking

Table:

```text
system.aggregate_versions
```

Fields:

```text
aggregate_type

aggregate_id

latest_processed_version

latest_processed_at
```

Purpose:

* Stale event detection
* Aggregate progress tracking
* Ordering validation

---

# 12. Queue Architecture

Queue providers are abstracted.

Workers depend only on:

```php
QueueProviderInterface
```

---

Supported Providers:

```text
Database Queue

Redis Queue

RabbitMQ Queue

Kafka Queue

Amazon SQS
```

---

Phase 1:

```text
Database Queue Provider
```

---

# 13. Queue Partitioning

## Phase 1

Domain Queues

```text
content

commerce

system
```

---

## Future

Priority-Aware Domain Queues

Examples:

```text
high-commerce

normal-commerce

low-commerce
```

No redesign should be required.

---

# 14. Queue Job Lifecycle

States:

```text
queued

processing

retry_scheduled

completed

failed

dead_letter
```

---

## queued

Awaiting execution.

---

## processing

Claimed by worker.

---

## retry_scheduled

Waiting for retry window.

---

## completed

Successfully processed.

---

## failed

Execution failed.

Awaiting retry decision.

---

## dead_letter

Exceeded retry threshold.

Requires intervention.

---

# 15. Retry Strategy

## ADR-022

### Status

Accepted

### Decision

Use:

```text
Exponential Backoff + Jitter
```

---

### Default Retry Limit

```text
10 retries
```

Configurable.

---

### Future

Per-event-type retry policies may be supported.

---

# 16. Dead Letter Queue

Table:

```text
system.dead_letter_jobs
```

---

Captured Data:

```text
job_id

event_id

failure_reason

stack_trace

attempt_count

worker_id

payload_snapshot

created_at
```

---

Purpose:

* Diagnostics
* Recovery
* Replay

---

# 17. Queue Claiming Strategy

## ADR-023

### Status

Accepted

### Decision

Database Queue Provider uses:

```sql
FOR UPDATE SKIP LOCKED
```

for job claiming.

---

### Reasoning

Supports:

* Multiple workers
* Horizontal scaling
* Low contention
* Safe concurrency

---

### Example

```sql
SELECT id
FROM system.queue_jobs
WHERE status = 'queued'
FOR UPDATE SKIP LOCKED
LIMIT 50;
```

---

# 18. Visibility Timeout

Every claimed job receives:

```text
visibility_timeout_at
```

---

Purpose:

Worker crash recovery.

---

Example:

```text
Worker Claims Job
        ↓
Worker Crashes
        ↓
Visibility Timeout Expires
        ↓
Job Requeued
```

---

# 19. Worker Heartbeats

Workers publish:

```text
worker_id

status

current_job

memory_usage

started_at

last_heartbeat_at
```

---

Purpose:

* Health monitoring
* Crash detection
* Operational visibility

---

# 20. Worker Execution Model

## ADR-024

### Status

Accepted

### Decision

Primary:

```text
CLI Workers
```

Examples:

```text
WP-CLI

Supervisor

Systemd
```

---

Fallback:

```text
WP-Cron
```

---

### Reasoning

CLI workers provide:

* Better throughput
* Better reliability
* Better scalability

WP-Cron provides recovery capability.

---

# 21. Worker Architecture

```text
Worker
    ↓
Event Router
    ↓
Module Handler
```

---

Responsibilities

Worker:

* Claim jobs
* Track retries
* Handle visibility timeout
* Publish heartbeats

---

Module Handler:

* Load source state
* Build canonical models
* Invoke transformers
* Invoke adapters

---

# 22. Event Batching

Approved Strategy:

```text
Batch Claim
```

↓

```text
Single Event Execution
```

---

Example:

```text
Claim 50 Jobs
```

Process:

```text
1
2
3
...
50
```

individually.

---

Reasoning:

Improves throughput while preserving isolation.

---

# 23. Idempotency Strategy

## ADR-025

### Status

Accepted

### Decision

Dual Idempotency Model.

---

Mechanism 1

Event ID Tracking

---

Mechanism 2

Checksum Validation

---

Purpose:

* Duplicate protection
* Write suppression
* Reconciliation support

---

# 24. Replay Architecture

Supported Replay Modes:

```text
Single Event Replay

Entity Replay

Date Range Replay

Full Replay
```

---

## Single Event Replay

Operational recovery.

---

## Entity Replay

Repair one aggregate.

Example:

```text
Product 123
```

---

## Date Range Replay

Repair outage windows.

---

## Full Replay

Complete rebuild.

---

# 25. Reconciliation Architecture

## ADR-026

### Status

Accepted

### Decision

Reconciliation is scheduled.

---

Default Schedule

Hourly:

```text
Drift Detection
```

---

Nightly:

```text
Incremental Validation
```

---

Weekly:

```text
Full Reconciliation
```

---

Schedules remain configurable.

---

# 26. Reconciliation Authority

## ADR-027

### Status

Accepted

### Decision

WordPress Wins.

---

When divergence is detected:

```text
WordPress
    ≠
PostgreSQL
```

the platform repairs PostgreSQL.

The reverse must never occur.

---

# 27. Queue Observability

The platform must expose:

```text
Queue Depth

Processing Rate

Retry Rate

Failure Rate

Dead Letter Count

Worker Health

Worker Throughput

Reconciliation Status
```

---

Purpose:

Operational visibility.

---

# 28. Failure Recovery

Recovery Mechanisms:

```text
Visibility Timeout

Retries

Dead Letter Queue

Replay

Reconciliation
```

---

The platform must survive:

* Worker crashes
* Queue corruption
* VPS restarts
* Deployment interruptions
* Temporary infrastructure failures

without event loss.

---

# 29. Horizontal Scaling

Supported Model:

```text
Worker 1

Worker 2

Worker 3

Worker N
```

Workers may consume concurrently.

Aggregate version tracking prevents stale processing.

---

# 30. Approval Checklist

* [x] At-Least-Once Delivery
* [x] Outbox Source Of Truth
* [x] Event Durability Model
* [x] Versioned Event Envelope
* [x] Aggregate Versioning
* [x] Queue Provider Abstraction
* [x] Domain Queue Strategy
* [x] Retry Architecture
* [x] Dead Letter Queue
* [x] Visibility Timeout
* [x] Worker Heartbeats
* [x] CLI Worker Strategy
* [x] Replay Architecture
* [x] Reconciliation Architecture
* [x] Horizontal Scaling

---

# Approval Status

**Version:** 1.0

**Status:** Approved

**State:** Frozen

This document is the authoritative Queue & Event Processing Architecture specification for the Headless Sync Platform.
