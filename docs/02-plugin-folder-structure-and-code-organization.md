# Plugin Folder Structure & Code Organization

**Project:** Headless Sync Platform (HSP)
**Version:** 1.1
**Status:** Approved
**State:** Frozen

**Depends On:** Technical Architecture Specification v1.1

---

# 1. Purpose

This document defines the physical code organization, namespace strategy, module architecture, dependency injection model, migration ownership, and testing strategy for the Headless Sync Platform.

The structure is designed to support:

* Event-driven architecture
* Internal modular architecture
* Future module extraction
* Queue provider abstraction
* Canonical delivery models
* Independent schema evolution
* Long-term maintainability

Target lifespan:

* 5+ years without major reorganization

---

# 2. Architectural Principles

## Principle 1

Organize by domain before technical type.

---

## Principle 2

Core owns infrastructure.

Modules own business domains.

---

## Principle 3

Contracts define dependency boundaries.

---

## Principle 4

Modules remain logically independent.

No module-to-module dependencies.

---

## Principle 5

Physical packaging does not define architecture.

Single plugin.

Multiple independent modules.

---

## Principle 6

Core remains domain agnostic.

Core never contains business-domain implementations.

---

# 3. Root Folder Structure

```text
headless-sync/
│
├── headless-sync.php
├── composer.json
├── composer.lock
│
├── bootstrap/
├── config/
├── core/
├── modules/
├── database/
├── resources/
├── storage/
├── tests/
├── tools/
├── docs/
│
└── vendor/
```

---

# 4. Bootstrap Structure

```text
bootstrap/
│
├── Application.php
├── Bootstrapper.php
├── Environment.php
├── Constants.php
└── Version.php
```

Responsibilities:

* Startup sequence
* Environment loading
* Module discovery initialization
* Container initialization
* Configuration loading

---

# 5. Configuration Structure

```text
config/
│
├── app.php
├── queue.php
├── database.php
├── modules.php
├── security.php
├── logging.php
└── observability.php
```

Responsibilities:

* Global platform configuration
* Environment overrides
* Infrastructure configuration

No business logic permitted.

---

# 6. Core Platform Structure

```text
core/
│
├── Contracts/
├── Container/
├── Modules/
├── Events/
├── Queue/
├── Workers/
├── Delivery/
├── Security/
├── Observability/
├── Reconciliation/
├── Configuration/
├── Support/
└── Exceptions/
```

Core contains infrastructure only.

Core must remain independent of business domains.

---

# 7. Contracts Structure

```text
core/Contracts/
│
├── ModuleInterface.php
├── EventInterface.php
├── EventProviderInterface.php
├── TransformerInterface.php
├── QueueProviderInterface.php
├── WorkerInterface.php
├── AdapterInterface.php
├── CanonicalModelInterface.php
├── MigrationInterface.php
├── EntityProviderInterface.php
└── ServiceProviderInterface.php
```

All modules depend on contracts.

Contracts never depend on modules.

---

# 8. Namespace Strategy

Root Namespace:

```php
HSP\
```

Core:

```php
HSP\Core\
```

Contracts:

```php
HSP\Core\Contracts\
```

Modules:

```php
HSP\Modules\
```

Examples:

```php
HSP\Modules\Content\
HSP\Modules\WooCommerce\
HSP\Modules\Membership\
HSP\Modules\LMS\
```

Namespace structure mirrors folder structure.

---

# 9. Dependency Injection Container

```text
core/Container/
│
├── Container.php
├── ContainerBuilder.php
├── ServiceRegistry.php
├── ServiceProvider.php
└── Definitions/
```

Responsibilities:

* Dependency injection
* Service registration
* Service resolution
* Module integration

---

# 10. Module Infrastructure

```text
core/Modules/
│
├── ModuleManager.php
├── ModuleRegistry.php
├── ModuleDiscovery.php
├── ModuleLoader.php
└── ModuleManifest.php
```

Responsibilities:

* Module discovery
* Module registration
* Lifecycle management
* Version management

---

# 11. Module Discovery

Modules are discovered through:

```text
modules/*/module.json
```

Example:

```json
{
  "name": "woocommerce",
  "version": "1.0.0",
  "module_class": "HSP\\Modules\\WooCommerce\\Module",
  "schema_version": "1.0.0",
  "requires": []
}
```

Module manifests are mandatory.

---

# 12. Module Lifecycle

All modules implement:

```php
ModuleInterface
```

Required methods:

```php
register()

boot()

activate()

deactivate()

upgrade()
```

Responsibilities:

### register()

Register services.

### boot()

Initialize runtime functionality.

### activate()

Install resources.

### deactivate()

Remove runtime registrations.

### upgrade()

Run migrations and version upgrades.

---

# 13. Module Folder Structure

```text
modules/
│
├── Content/
├── WooCommerce/
├── Membership/
├── LMS/
├── Directory/
└── Booking/
```

Only implemented modules require code.

---

# 14. Standard Module Layout

```text
modules/WooCommerce/
│
├── module.json
├── Module.php
│
├── Config/
├── Events/
├── Providers/
├── Subscribers/
├── Transformers/
├── CanonicalModels/
├── Validation/
├── Migrations/
├── Resources/
└── Tests/
```

Every module follows the same structure.

---

# 15. Module Registration Flow

```text
Application Start
        ↓
Module Discovery
        ↓
Module Registry
        ↓
Service Registration
        ↓
Event Registration
        ↓
Transformer Registration
        ↓
Migration Registration
        ↓
Module Boot
```

---

# 16. Event Infrastructure

```text
core/Events/
│
├── Dispatcher/
├── Outbox/
├── Builders/
├── Replay/
├── Versioning/
└── Subscribers/
```

Responsibilities:

* Event creation
* Event dispatching
* Event replay
* Event versioning

---

# 17. Queue Infrastructure

```text
core/Queue/
│
├── Contracts/
├── Dispatchers/
├── Providers/
├── Retries/
├── DeadLetter/
├── Scheduling/
└── Monitoring/
```

Queue implementation remains provider-based.

---

# 18. Queue Providers

```text
core/Queue/Providers/
│
├── Database/
├── Redis/
├── RabbitMQ/
├── Kafka/
└── SQS/
```

Phase 1:

* Database Queue Provider

Future:

* Redis
* RabbitMQ
* Kafka
* SQS

No architectural changes required.

---

# 19. Worker Infrastructure

```text
core/Workers/
│
├── Consumers/
├── Scheduling/
├── Recovery/
├── Monitoring/
└── Contracts/
```

Workers remain domain agnostic.

Workers process events.

Workers do not know business entities.

---

# 20. Transformer Structure

Transformers belong to modules.

Example:

```text
modules/WooCommerce/Transformers/
```

Examples:

```text
ProductTransformer
VariationTransformer
InventoryTransformer
```

Transformers produce canonical models.

Transformers never perform persistence.

---

# 21. Canonical Model Structure

Canonical Models belong to modules.

Examples:

```text
modules/Content/CanonicalModels/

modules/WooCommerce/CanonicalModels/

modules/Membership/CanonicalModels/
```

Examples:

```text
CanonicalPage
CanonicalProduct
CanonicalVariation
CanonicalSubscription
```

All canonical models must implement:

```php
CanonicalModelInterface
```

located in:

```text
core/Contracts/
```

Core owns contracts.

Modules own implementations.

---

# 22. Delivery Infrastructure

```text
core/Delivery/
│
├── Contracts/
├── Adapters/
├── Serialization/
├── Mapping/
└── Persistence/
```

Responsibilities:

* Delivery abstractions
* Storage adapters
* Canonical model persistence
* Serialization

---

# 23. Adapter Structure

```text
core/Delivery/Adapters/
│
├── PostgreSQL/
├── Redis/
├── Search/
└── Webhooks/
```

Adapters depend only on:

```php
CanonicalModelInterface
```

Adapters must not depend on module implementations.

---

# 24. Migration Ownership

Core infrastructure migrations:

```text
database/
└── Core/
```

Examples:

* Outbox
* Queue
* Audit
* Security
* SchemaVersion
* ModuleRegistry

---

Module migrations:

```text
modules/Content/Migrations/

modules/WooCommerce/Migrations/

modules/Membership/Migrations/
```

Modules own all business-domain schema changes.

---

# 25. Schema Versioning

Version tracking includes:

* Core Schema Version
* Module Schema Version

Supports:

* Upgrades
* Rollbacks
* Compatibility Tracking

---

# 26. Configuration Ownership

Global Configuration:

```text
config/
```

Module Configuration:

```text
modules/*/Config/
```

Resolution Order:

```text
Global
 ↓
Module
 ↓
Environment Override
```

---

# 27. Security Structure

```text
core/Security/
│
├── Encryption/
├── Credentials/
├── Signing/
├── Authentication/
├── Authorization/
└── Audit/
```

Responsibilities:

* Secret management
* Credential storage
* API credentials
* Queue credentials
* Webhook signing
* Security auditing

---

# 28. Observability Structure

```text
core/Observability/
│
├── Logging/
├── Metrics/
├── Tracing/
├── HealthChecks/
└── Dashboards/
```

Responsibilities:

* Monitoring
* Metrics
* Diagnostics
* Operational visibility

---

# 29. Reconciliation Structure

```text
core/Reconciliation/
│
├── FullSync/
├── IncrementalSync/
├── DriftDetection/
└── Repair/
```

Responsibilities:

* Drift detection
* Replay support
* Data repair
* Synchronization validation

---

# 30. Testing Structure

```text
tests/
│
├── Unit/
├── Integration/
├── Contract/
├── Module/
├── Performance/
└── EndToEnd/
```

### Unit

Class-level testing.

### Integration

Infrastructure testing.

### Contract

Interface compliance testing.

### Module

Module-level testing.

### Performance

Scalability testing.

### End-to-End

Complete synchronization workflow testing.

---

# 31. Future Module Extraction Strategy

Modules are internally packaged.

Architecture must support future extraction.

Requirements:

* No module-to-module dependencies
* Module-owned canonical models
* Module-owned migrations
* Module-owned configuration
* Core-owned contracts
* Core-owned infrastructure

A module should be extractable with minimal changes.

---

# ADR-012

## Dependency Injection Rule

Status: Accepted

### Decision

Service Locator usage is prohibited.

Dependencies must be injected through constructors or explicit registration mechanisms.

Allowed:

```php
public function __construct(
    QueueProviderInterface $queue
)
```

Prohibited:

```php
Container::get(...)
```

inside business logic.

Prohibited:

```php
global $container;
```

### Reasoning

* Better testability
* Explicit dependencies
* Easier maintenance
* Reduced coupling

---

# Approval Status

Version: 1.1

Status: Approved

State: Frozen

This document is the authoritative code organization specification for the Headless Sync Platform.
