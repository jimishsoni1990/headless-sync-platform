# Technical Architecture Specification (TAS)

**Project:** Headless Sync Platform (HSP)
**Version:** 1.1
**Status:** Approved
**State:** Frozen

---

# 1. Purpose

The Headless Sync Platform (HSP) transforms WordPress into a scalable, event-driven headless CMS platform.

WordPress remains the editorial source of truth.

The platform synchronizes transformed content and commerce data into optimized delivery stores while remaining independent of any specific frontend technology.

The platform is intended to serve as a long-term reusable foundation for multiple project types including:

* Content Sites
* WooCommerce Stores
* Membership Platforms
* LMS Platforms
* Directory Platforms
* Booking Systems
* Events Platforms
* Custom Business Applications

---

# 2. Core Objectives

## Reliability

No synchronization event should be lost.

Delayed synchronization is acceptable.

Lost synchronization is unacceptable.

---

## Scalability

Support:

* 100,000+ content records
* 500,000+ products
* 1,000,000+ events/day

without architectural redesign.

---

## Extensibility

Future domains must be added through modules without modifying the platform core.

---

## Maintainability

Business logic must remain isolated from infrastructure concerns.

---

# 3. Architectural Principles

## Principle 1

WordPress is the Source of Truth.

---

## Principle 2

PostgreSQL is a Delivery Database.

Not a WordPress replica.

---

## Principle 3

Transform Before Persist.

Direct replication of WordPress tables is prohibited.

---

## Principle 4

Everything is Event Driven.

Direct synchronization from hooks is prohibited.

---

## Principle 5

Idempotency is Mandatory.

All processing must safely handle duplicate events.

---

## Principle 6

Eventual Consistency.

Synchronization is asynchronous.

---

## Principle 7

Domain Features Must Be Modular.

Business domains are implemented as modules.

---

## Principle 8

Physical Packaging Must Not Define Architecture.

The platform is packaged as a single plugin but architected as independent modules.

---

# 4. High-Level Architecture

WordPress

↓

Core Platform

↓

Module Registry

↓

Domain Modules

↓

Event Builder

↓

Outbox

↓

Dispatcher

↓

Queue Provider

↓

Workers

↓

Transformers

↓

Canonical Delivery Models

↓

Adapters

↓

PostgreSQL

---

Consumer Systems Boundary

Examples:

* Next.js
* Mobile Applications
* Search Engines
* AI Systems
* Internal APIs
* Partner APIs

---

# 5. Platform Domains

## Core Platform

Responsibilities:

* Contracts
* Module Registry
* Event Infrastructure
* Queue Infrastructure
* Worker Infrastructure
* Adapter Infrastructure
* Security Infrastructure
* Configuration
* Observability

---

## Domain Modules

Examples:

* Content Module
* WooCommerce Module
* Membership Module
* LMS Module
* Directory Module
* Booking Module

---

## Security Domain

Responsibilities:

* Secret Management
* Credential Encryption
* Queue Authentication
* API Credentials
* Webhook Signing
* Security Audit Events

---

## Observability Domain

Responsibilities:

* Metrics
* Audit Logs
* Health Checks
* Tracing
* Operational Visibility

---

# 6. Event Architecture

The platform uses the Outbox Pattern.

Flow:

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

---

## Event Rules

Events are immutable.

Events describe facts.

Examples:

* page.created
* page.updated
* product.updated
* inventory.changed

---

# 7. Event Versioning

All events are versioned.

Example:

{
"event_id": "uuid",
"event_type": "product.updated",
"event_version": 1,
"payload": {}
}

Versioning allows schema evolution without breaking consumers.

---

# 8. Queue Architecture

Queue implementations are abstracted.

Workers depend only on QueueProviderInterface.

Supported Providers:

* Database Queue
* Redis Queue
* RabbitMQ Queue
* Kafka Queue
* Amazon SQS

Phase 1 recommendation:

Database Queue Provider

Phase 2:

Additional providers as needed.

---

# 9. Canonical Delivery Models

Transformers produce Canonical Delivery Models.

Flow:

WordPress Entity

↓

Transformer

↓

Canonical Model

↓

Adapter

↓

Delivery Store

Canonical Models must remain independent of storage implementations.

---

# 10. Data Storage Strategy

## JSONB Storage

Used for:

* Pages
* Posts
* ACF
* Flexible Content
* Repeaters
* Nested Repeaters

---

## Relational Storage

Used for:

* Products
* Variations
* Attributes
* Categories
* Inventory

---

# 11. Reliability Strategy

Required Components:

* Outbox Pattern
* Retry Processing
* Dead Letter Queue
* Reconciliation Engine
* Full Sync
* Incremental Sync
* Event Replay

The platform must survive:

* Worker Failures
* PHP Failures
* Queue Failures
* VPS Restarts
* Deployment Interruptions

without data loss.

---

# 12. Module Architecture

Physical Packaging:

Single Plugin

Logical Architecture:

Independent Modules

Modules register:

* Events
* Transformers
* Entity Providers
* Validation Rules
* Migrations

through Core Contracts.

---

# 13. Dependency Rules

## Allowed

Core

▲

Modules

Modules may depend on:

* Core Contracts
* Core Services
* Event Infrastructure

---

## Prohibited

Module

▲

Module

Direct module-to-module dependencies are prohibited.

Cross-module communication must occur through:

* Events
* Core-defined Contracts

---

# 14. Schema Versioning

Schema versioning is mandatory.

Version tracking includes:

* Core Schema
* Module Schemas

Supports:

* Upgrades
* Rollbacks
* Compatibility Tracking

---

# 15. Scalability Targets

Phase 1

* 10,000 content records
* 50,000 products

Phase 2

* 100,000 content records
* 500,000 products

Phase 3

* 1,000,000+ events/day

---

# ADR-001

WordPress is the Source of Truth.

---

# ADR-002

Outbox Pattern is Mandatory.

---

# ADR-003

Redis is Disposable Infrastructure.

---

# ADR-004

Transform Before Persist.

---

# ADR-005

Content and Commerce are Separate Domains.

---

# ADR-006

All Synchronization Must Be Idempotent.

---

# ADR-007

Queue Providers Must Be Abstracted Behind Interfaces.

---

# ADR-008

Canonical Delivery Models are Required.

---

# ADR-009

Security is a First-Class Architectural Domain.

---

# ADR-010

Modules May Not Depend on Other Modules.

Cross-module communication occurs through events and contracts.

---

# ADR-011

Core Dependency Rule

Core may depend only on:

* Core Contracts
* Core Infrastructure
* Module Interfaces

Core must never depend on module implementations.

Core must not contain:

* WooCommerce-specific logic
* Membership-specific logic
* LMS-specific logic
* Directory-specific logic
* Booking-specific logic

This preserves long-term platform independence.

---

# Approval Status

Status: Approved

Version: 1.1

State: Frozen

This document is the authoritative architectural specification for the Headless Sync Platform.
