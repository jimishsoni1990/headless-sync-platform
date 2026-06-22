# Worker Architecture & Execution Model

**Project:** Headless Sync Platform (HSP)
**Version:** 1.0
**Status:** Approved
**State:** Frozen

**Depends On:**

* Document 1 — Technical Architecture Specification
* Document 2 — Plugin Folder Structure & Code Organization
* Document 3 — Database Design & Persistence Architecture
* Document 4 — Queue & Event Processing Architecture
* Document 5 — Event Architecture & Contract Design
* Document 6 — Transformer Architecture & Canonical Model Design
* Document 7 — Adapter Architecture & Delivery Projection Design

---

# 1. Purpose

This document defines the runtime worker architecture responsible for processing events and executing synchronization workflows.

It establishes:

* Worker ownership
* Worker lifecycle
* Execution pipelines
* Concurrency model
* Worker categories
* Resource management
* Health monitoring
* Failure isolation
* Execution context
* Event tracing
* Horizontal scaling

This document is the authoritative specification governing worker execution throughout the platform.

---

# 2. Architectural Principles

## Principle 1

Workers Are Infrastructure.

---

## Principle 2

Workers Are Stateless.

---

## Principle 3

Worker Failures Must Not Cause Event Loss.

---

## Principle 4

Worker Lifecycle Is Managed Externally.

---

## Principle 5

Execution Logic Belongs To Modules.

---

## Principle 6

Observability Is A First-Class Concern.

---

## Principle 7

Workers Must Scale Horizontally.

---

# 3. High-Level Architecture

```text
Worker Engine (Core)
        ↓
Worker Strategy
        ↓
Execution Pipeline
        ↓
Subscriber
        ↓
Handler
```

---

# 4. Worker Ownership

## Decision

Core owns worker infrastructure.

Modules own execution logic.

---

Architecture:

```text
Worker Engine (Core)
        ↓
Router (Core)
        ↓
Subscriber (Module)
        ↓
Handler (Module)
```

---

## Core Responsibilities

Core owns:

* Worker Engine
* Job Claiming
* Heartbeats
* Metrics
* Resource Monitoring
* Retry Coordination
* Execution Context
* Tracing

---

## Module Responsibilities

Modules own:

* Subscribers
* Handlers
* Domain Logic
* Projection Logic

---

# 5. Worker Categories

## Decision

Worker categories share a common worker engine.

---

## ADR-035

### Status

Accepted

### Decision

The platform uses:

```text
Shared Worker Engine
```

with

```text
Specialized Worker Strategies
```

---

### Reasoning

Infrastructure concerns should not be duplicated across worker types.

---

# 6. Worker Strategies

Examples:

```text
EventWorkerStrategy

ReplayWorkerStrategy

ReconciliationWorkerStrategy

MaintenanceWorkerStrategy
```

---

All strategies execute through the same worker engine.

---

# 7. Worker Execution Pipeline

## Standard Pipeline

```text
Claim Job
        ↓
Load Event
        ↓
Create Execution Context
        ↓
Validate Event
        ↓
Resolve Subscriber
        ↓
Execute Handler
        ↓
Commit State
        ↓
Acknowledge Job
```

---

## Benefits

* Consistency
* Observability
* Extensibility
* Predictable execution

---

# 8. Worker Execution Context

## Decision

Handlers receive a WorkerExecutionContext.

---

Example:

```text
WorkerExecutionContext
```

contains:

```text
Event

Queue Job

Worker ID

Attempt Count

Correlation ID

Causation ID

Trace Metadata
```

---

## Purpose

Provides:

* Diagnostics
* Tracing
* Retry Awareness
* Operational Visibility

---

# 9. Concurrency Model

## Decision

Multi-Process Concurrency

---

Preferred:

```text
Worker 1

Worker 2

Worker 3

Worker N
```

---

Avoid:

```text
Worker
 ├─ Thread
 ├─ Thread
 └─ Thread
```

---

## Reasoning

Aligns naturally with:

* PHP
* WP-CLI
* Supervisor
* systemd
* Container Platforms

---

# 10. Horizontal Scaling

Workers may scale horizontally.

Example:

```text
1 Worker
```

or

```text
100 Workers
```

without architectural changes.

---

## Requirements

Horizontal scaling must preserve:

* At-Least-Once Delivery
* Aggregate Version Ordering
* Visibility Timeout Recovery

---

# 11. Stateless Worker Design

## ADR-036

### Status

Accepted

### Decision

Workers maintain no durable business state.

---

Durable state belongs to:

* Event Store
* Queue Infrastructure
* Delivery Database
* Reconciliation Systems

---

### Consequence

Workers may be:

* Restarted
* Recycled
* Replaced
* Horizontally Scaled

without affecting correctness.

---

# 12. Resource Management

Workers monitor:

```text
Memory Usage

Runtime

Processed Jobs

Heartbeat Age
```

---

Purpose:

* Stability
* Leak Detection
* Capacity Planning

---

# 13. Worker Recycling

## Decision

Workers are recycled periodically.

---

Recycling thresholds may include:

```text
Maximum Jobs Processed

Maximum Runtime

Maximum Memory Usage
```

---

Flow:

```text
Threshold Reached
        ↓
Graceful Shutdown
        ↓
Supervisor Restart
```

---

## Reasoning

Mitigates:

* Memory Drift
* Resource Fragmentation
* Long-Running PHP Risks

---

# 14. Failure Isolation

## Decision

Job failures and worker failures are separate concerns.

---

Example:

```text
Invalid Product
```

must not terminate:

```text
Worker Process
```

---

Flow:

```text
Handler Failure
        ↓
Job Failure
        ↓
Retry Workflow
```

---

Worker remains healthy.

---

# 15. Worker Health Monitoring

Workers publish:

```text
worker_id

status

started_at

last_heartbeat_at

current_job

memory_usage

processed_count
```

---

Purpose:

* Health Monitoring
* Capacity Tracking
* Failure Detection

---

# 16. Worker Status States

Example states:

```text
starting

idle

processing

recycling

stopping

failed
```

---

Used for:

* Monitoring
* Dashboards
* Diagnostics

---

# 17. Observability Requirements

Workers expose:

```text
Worker Count

Worker Status

Queue Lag

Processing Rate

Failure Rate

Retry Rate

DLQ Rate

Memory Usage

Runtime
```

---

Observability is mandatory.

---

# 18. Event Traceability

## ADR-037

### Status

Accepted

### Decision

Every event contains:

```text
correlation_id

causation_id
```

---

# 19. Correlation ID Rules

Correlation ID identifies:

```text
Root Business Transaction
```

---

Example:

```text
User Updates Product
```

creates:

```text
correlation_id = event_id
```

---

Correlation ID remains unchanged throughout the event chain.

---

# 20. Causation ID Rules

Causation ID identifies:

```text
Immediate Parent Event
```

---

Example:

```text
Event A
        ↓
Event B
```

Event B contains:

```text
causation_id = Event A
```

---

# 21. Traceability Example

```text
Product Updated
        ↓
Inventory Changed
        ↓
Search Reindexed
        ↓
Analytics Updated
```

All events share:

```text
Same Correlation ID
```

---

Each event references:

```text
Immediate Parent
```

through:

```text
causation_id
```

---

# 22. Worker Resolution

## Decision

Worker runtime uses registries.

---

Resolution flow:

```text
Event Type
        ↓
Event Registry
        ↓
Subscriber
        ↓
Handler
```

---

Benefits:

* Loose Coupling
* Module Independence
* Extensibility

---

# 23. Supervisor Architecture

## Decision

Worker lifecycle is externally managed.

---

Supported Supervisors:

```text
systemd

Supervisor

Container Restart Policies
```

---

Workers must not:

* Self-respawn
* Manage sibling workers
* Manage cluster topology

---

# 24. Worker Startup Flow

```text
Supervisor
        ↓
Start Worker
        ↓
Initialize Engine
        ↓
Register Heartbeat
        ↓
Begin Claim Loop
```

---

# 25. Worker Shutdown Flow

```text
Shutdown Signal
        ↓
Stop Claiming Jobs
        ↓
Finish Current Job
        ↓
Publish Final Heartbeat
        ↓
Exit
```

---

Graceful shutdown is required.

---

# 26. Worker Recovery

Recovery mechanisms:

```text
Visibility Timeout

Retries

Dead Letter Queue

Replay

Reconciliation
```

---

Workers must recover safely from:

* Crashes
* VPS Restarts
* Deployments
* Process Terminations

---

# 27. Worker Metrics

Minimum metrics:

```text
jobs_processed

jobs_failed

jobs_retried

jobs_dead_lettered

average_processing_time

memory_usage

worker_uptime
```

---

Purpose:

* Capacity Planning
* Alerting
* Performance Analysis

---

# 28. Security Considerations

Workers must:

* Use least-privilege credentials
* Respect queue permissions
* Respect database permissions
* Emit security audit events when required

---

Worker credentials must not be embedded in code.

---

# 29. Testing Strategy

Worker infrastructure should be tested independently.

---

Examples:

```text
Job Claiming

Retry Logic

Heartbeat Updates

Execution Context Creation

Pipeline Execution
```

---

Domain handlers are tested separately.

---

# 30. Approval Checklist

* [x] Worker ownership defined
* [x] Shared worker engine approved
* [x] Worker strategies approved
* [x] Execution pipeline defined
* [x] Execution context defined
* [x] Multi-process concurrency approved
* [x] Horizontal scaling supported
* [x] Stateless worker design approved
* [x] Resource monitoring approved
* [x] Worker recycling approved
* [x] Failure isolation approved
* [x] Observability requirements defined
* [x] Correlation ID rules defined
* [x] Causation ID rules defined
* [x] Registry-based resolution approved
* [x] Supervisor architecture approved
* [x] Recovery architecture defined

---

# Approval Status

**Version:** 1.0

**Status:** Approved

**State:** Frozen

This document is the authoritative Worker Architecture & Execution Model specification for the Headless Sync Platform.
