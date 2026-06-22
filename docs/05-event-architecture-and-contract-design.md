# Event Architecture & Contract Design

**Project:** Headless Sync Platform (HSP)
**Version:** 1.0
**Status:** Approved
**State:** Frozen

**Depends On:**
* Document 1 — Technical Architecture Specification
* Document 2 — Plugin Folder Structure & Code Organization
* Document 3 — Database Design & Persistence Architecture
* Document 4 — Queue & Event Processing Architecture

---

# 1. Purpose

This document defines the event architecture used throughout the Headless Sync Platform.

It establishes:

* Event ownership
* Event contracts
* Event versioning
* Event lifecycle management
* Event metadata standards
* Event registration
* Event creation rules
* Event consumption architecture
* Subscriber responsibilities
* Handler responsibilities
* Backward compatibility requirements

This document serves as the authoritative specification for all current and future modules.

---

# 2. Architectural Principles

## Principle 1

Events Are Domain Contracts.

---

## Principle 2

Events Are Immutable.

---

## Principle 3

Events Are Versioned.

---

## Principle 4

Events Are Durable.

---

## Principle 5

Event Contracts Must Be Backward Compatible.

---

## Principle 6

Modules Own Event Definitions.

---

## Principle 7

Subscribers Coordinate.

Handlers Execute.

---

# 3. Event Ownership

## Decision

Modules own event definitions.

---

### Examples

```text
Modules/Content/Events

Modules/WooCommerce/Events

Modules/Membership/Events

Modules/LMS/Events
```

---

### Core Responsibilities

Core owns:

* Event contracts
* Event infrastructure
* Event registry
* Event dispatcher
* Event interfaces

Core does not own business-domain events.

---

### Reasoning

Events are business-domain concepts.

Examples:

```text
content.page.published

content.post.updated

commerce.product.updated

commerce.inventory.changed
```

These belong to modules rather than platform infrastructure.

---

# 4. Event Naming Convention

## Standard

```text
<domain>.<aggregate>.<action>
```

---

### Examples

```text
content.page.created

content.page.updated

content.page.deleted

content.post.published

commerce.product.created

commerce.product.updated

commerce.inventory.changed
```

---

### Naming Rules

Events must:

* Be lowercase
* Use dot notation
* Be domain-oriented
* Avoid technology-specific terminology

---

### Prohibited

```text
woocommerce.product.updated

wp_post_saved

acf_field_changed
```

Event names should describe business domains rather than implementation details.

---

# 5. Event Categories

Events are grouped into categories.

---

## Domain Events

Represent business activity.

Examples:

```text
content.page.updated

commerce.product.updated
```

---

## System Events

Represent platform activity.

Examples:

```text
system.queue.failed

system.reconciliation.completed
```

---

## Security Events

Represent security-related activity.

Examples:

```text
security.credential.rotated

security.api_key.revoked
```

---

# 6. Event Contract Structure

All events must implement:

```php
EventInterface
```

---

## Required Fields

Every event contains:

```text
event_id

event_type

event_version

aggregate_type

aggregate_id

aggregate_version

source_updated_at

created_at

checksum

metadata
```

---

# 7. Event Envelope

Example:

```json
{
  "event_id": "uuidv7",
  "event_type": "commerce.product.updated",
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

# 8. Event Immutability

## Decision

Events are immutable.

---

### Rule

After creation:

```text
No Mutation Allowed
```

---

### Consequences

Events:

* Cannot be edited
* Cannot be rewritten
* Cannot be reused

Any change requires:

```text
New Event
```

or

```text
New Event Version
```

---

# 9. Event Definition Strategy

## Decision

Strongly Typed Event Classes

---

### Example

```php
ProductUpdatedEvent
```

implements:

```php
EventInterface
```

---

### Benefits

* Validation
* Discoverability
* Static analysis
* Version control
* Better testing

---

### Prohibited

Unstructured array-only event definitions.

---

# 10. Event Registration

## Decision

Explicit Registration

---

### Example

```php
public function registerEvents(): void
{
    ...
}
```

---

### Rule

Modules explicitly register:

* Event definitions
* Event subscribers
* Event handlers

No automatic event scanning.

No reflection-based discovery.

No hidden registration mechanisms.

---

# 11. Event Granularity

## Decision

Hybrid Event Strategy

---

### Required

Aggregate-level events.

Examples:

```text
commerce.product.updated

content.page.updated
```

---

### Optional

Specialized events.

Examples:

```text
commerce.inventory.changed

commerce.price.changed
```

---

### Reasoning

Avoid event explosion while preserving future optimization opportunities.

---

# 12. Event Versioning

## Decision

Versioned Contracts

---

### Rule

Breaking changes require new versions.

Example:

```text
commerce.product.updated v1

↓

commerce.product.updated v2
```

---

### Rule

Existing versions must never change.

---

### Prohibited

Silent contract modification.

---

# 13. Event Contract Lifecycle

## ADR-028

### Status

Accepted

### Lifecycle States

```text
Supported

Deprecated

Removed
```

---

## Supported

Active contract.

Fully supported.

---

## Deprecated

Still supported.

Not recommended for new development.

---

## Removed

No longer supported.

Cannot be emitted.

Cannot be depended upon.

---

# 14. Contract Evolution Rules

## Rule 1

Contracts are immutable.

---

## Rule 2

Breaking changes require new versions.

---

## Rule 3

Optional fields may be added when backward compatibility is preserved.

---

## Rule 4

Required fields must not be added to an existing version.

---

## Rule 5

Contracts must transition:

```text
Supported
    ↓
Deprecated
    ↓
Removed
```

---

Direct removal is prohibited.

---

# 15. Deprecation Policy

Deprecated contracts remain supported for:

```text
12 months
```

or

```text
2 major platform releases
```

whichever is longer.

---

# 16. Migration Strategy

During migration windows modules may emit:

```text
v1
and
v2
```

simultaneously.

---

### Goal

Allow downstream consumers to migrate safely.

---

# 17. Transactional Event Creation

## ADR-029

### Status

Accepted

### Decision

Business change,

event creation,

and outbox persistence

must occur within the same transaction boundary.

---

### Requirement

Either:

```text
Business Change
+
Event Creation
+
Outbox Persistence
```

all succeed

or

all fail.

---

### Prevents

```text
State Changed
No Event
```

and

```text
Event Exists
No Durable Outbox Record
```

failure scenarios.

---

# 18. Event Creation Flow

```text
Business Change
        ↓
Create Event
        ↓
Persist Outbox Record
        ↓
Commit Transaction
        ↓
Dispatcher
        ↓
Queue
```

---

No event may be dispatched prior to successful outbox persistence.

---

# 19. Event Consumption Architecture

## Standard Flow

```text
Event
    ↓
Subscriber
    ↓
Handler(s)
```

---

# 20. Subscriber Responsibilities

Subscribers are coordinators.

---

### Responsibilities

* Listen for events
* Route events
* Invoke handlers
* Coordinate execution

---

### Subscribers Must Remain Thin

Subscribers must not:

* Perform transformations
* Update projections
* Execute domain workflows
* Contain business logic

---

# 21. Handler Responsibilities

Handlers execute business logic.

---

### Responsibilities

* Load source state
* Build canonical models
* Execute transformations
* Update delivery projections
* Trigger downstream actions

---

### Examples

```text
ProductProjectionHandler

SearchIndexHandler

AnalyticsHandler
```

---

# 22. Multi-Handler Events

A single subscriber may invoke multiple handlers.

Example:

```text
commerce.product.updated
        ↓
ProductUpdatedSubscriber
        ↓
ProductProjectionHandler

SearchIndexHandler

AnalyticsHandler
```

---

### Benefits

* Clear separation of concerns
* Easier testing
* Easier extraction
* Improved maintainability

---

# 23. Event Registry

Core maintains a centralized registry.

---

Responsibilities:

* Event registration
* Contract validation
* Version tracking
* Subscriber mapping

---

Registry does not contain business logic.

---

# 24. Event Validation

Events must be validated before persistence.

Validation includes:

```text
Required Fields

Contract Version

Aggregate Metadata

Checksum

Timestamp Integrity
```

---

Invalid events must be rejected.

---

# 25. Event Compatibility Rules

Consumers must explicitly declare supported versions.

Example:

```text
Supports:

commerce.product.updated v1

commerce.product.updated v2
```

---

This enables controlled migrations.

---

# 26. Event Replay Compatibility

Replay must use original event versions.

Example:

```text
Replay Event

v1
```

must remain:

```text
v1
```

during replay.

---

Replay must never mutate historical contracts.

---

# 27. Event Documentation Requirements

Every event contract must define:

```text
Event Name

Category

Version

Aggregate Type

Aggregate Identifier

Metadata Schema

Lifecycle State

Deprecation Status
```

---

Events are part of the platform API surface.

They must be documented.

---

# 28. Approval Checklist

* [x] Module-owned events
* [x] Domain-based naming
* [x] Event categories
* [x] Standard envelope
* [x] Immutable events
* [x] Strongly typed event classes
* [x] Explicit registration
* [x] Hybrid granularity strategy
* [x] Versioned contracts
* [x] Event lifecycle management
* [x] Transactional event creation
* [x] Subscriber/Handler separation
* [x] Event validation
* [x] Replay compatibility

---

# Approval Status

**Version:** 1.0

**Status:** Approved

**State:** Frozen

This document is the authoritative Event Architecture & Contract Design specification for the Headless Sync Platform.