# Code inventory - 100% traceability

**Baseline spec**: [`spec.md`](spec.md)  
**Package**: `nowo-tech/working-time-bundle`  
**Last audited**: 2026-11-18 (S0 scaffold)

Scope: every file under `src/`. Audit command: `find src -type f | wc -l` → **4**.

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `src/NowoWorkingTimeBundle.php` | Install and configure; Private entity manager | FR-CONFIG-001, FR-PARTS-001, FR-EM-001 |
| `src/Infrastructure/Symfony/DependencyInjection/ConfigurationTree.php` | Install and configure | FR-CONFIG-001, FR-PARTS-001, FR-LOCK-001, FR-PDF-001 |
| `src/Infrastructure/Symfony/DependencyInjection/ParameterFlattener.php` | Install and configure | FR-CONFIG-001 |
| `src/Infrastructure/Symfony/DependencyInjection/Compiler/PrivateEntityManagerPass.php` | Private entity manager | FR-EM-001 |

**Total production sources**: 4
