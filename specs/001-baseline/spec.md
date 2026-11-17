# Feature Specification: WorkingTimeBundle baseline

**Feature Branch**: `001-baseline`
**Created**: 2026-11-18
**Status**: Baseline (S0 scaffold)
**Input**: Contract v1.0 ([`docs/CONTRACT.md`](../../docs/CONTRACT.md)), owner (Q-O) and Beacon (Q-B) answers of
2026-11-18.

Related: [`docs/SPEC-DRIVEN-DEVELOPMENT.md`](../../docs/SPEC-DRIVEN-DEVELOPMENT.md),
[`docs/CONFIGURATION.md`](../../docs/CONFIGURATION.md), [`docs/ROADMAP.md`](../../docs/ROADMAP.md),
[`code-inventory.md`](code-inventory.md).

## Summary

One bundle, three parts that can be enabled separately: the working-hours **register** (art. 34.9 ET), the **holiday**
component and **leave**. S0 guarantees: the bundle registers in a standard or DDD-style Symfony host; the configuration
root `nowo_working_time` is strict and already holds every key of contract section 10; the three part toggles exist
(register on, holidays on, leave off) and `leave` requires the other two; the bundle's data lives on a private entity
manager `nowo_working_time` declared by the Flex recipe; the leave lock defaults to `db_row`; PDF rendering is closed
(no remote resources, no JavaScript, no PHP). S0 registers no domain service, route, listener or table.

## User scenarios

### US-01 - Install and configure (S0)

- **Given** a host with the multi-entity-manager Doctrine form, **when** the bundle is registered without
  configuration, **then** the container builds with the contract defaults.
- **Given** an unknown key, a `leave` part without `register` or `holidays`, a retention below 4 years, a
  `lock_factory` store without its service, or a PDF switch turned on, **when** the container builds, **then** it fails
  and names the path.

### US-02 - Private entity manager (S0)

- **Given** `doctrine.entity_manager: nowo_working_time` (default), **when** the container builds, **then** the alias
  `nowo_working_time.entity_manager` is that manager and never the host's default one.
- **Given** the manager is not declared, **when** the container builds, **then** it fails with the fix; **given**
  `entity_manager: null`, **then** the default manager is used (explicit transactions).

### US-03 - Layer rules (S0)

- **Given** the source tree, **then** `Holiday` imports nothing from `Register` or `Leave`, `Register` imports nothing
  from `Leave`, and `Domain` imports no Symfony, Doctrine or HTTP class (architecture test).

## Requirements

- **FR-CONFIG-001**: strict tree, defaults of contract section 10, parameters `nowo_working_time.<path>`.
- **FR-PARTS-001**: part toggles `register.enabled`, `holidays.enabled`, `leave.enabled`; leave needs the other two.
- **FR-EM-001**: private entity manager, alias `nowo_working_time.entity_manager`, checked at container build.
- **FR-LOCK-001**: `leave.lock.store` is `db_row` (default) or `lock_factory` (then `lock_factory` is required).
- **FR-PDF-001**: closed PDF rendering, `remote_resources`, `javascript` and `php` cannot be enabled (REQ-REPORT-001).
- **FR-ARCH-001**: layer dependency rules (REQ-ARCH-001).

## Non-goals (S0)

Domain code, tables, routes, UI, bridges: they arrive with S1 to S3.
