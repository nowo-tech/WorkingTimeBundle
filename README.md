# Working Time Bundle

[![CI](https://github.com/nowo-tech/WorkingTimeBundle/actions/workflows/ci.yml/badge.svg)](https://github.com/nowo-tech/WorkingTimeBundle/actions/workflows/ci.yml) [![Packagist Version](https://img.shields.io/packagist/v/nowo-tech/working-time-bundle.svg?style=flat)](https://packagist.org/packages/nowo-tech/working-time-bundle) [![Packagist Downloads](https://img.shields.io/packagist/dt/nowo-tech/working-time-bundle.svg)](https://packagist.org/packages/nowo-tech/working-time-bundle) [![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE) [![PHP](https://img.shields.io/badge/PHP-8.2%2B-777BB4?logo=php)](https://php.net) [![Symfony](https://img.shields.io/badge/Symfony-7.4%20%7C%208.0%20%7C%208.1%2B-000000?logo=symfony)](https://symfony.com) [![GitHub stars](https://img.shields.io/github/stars/nowo-tech/WorkingTimeBundle.svg?style=social&label=Star)](https://github.com/nowo-tech/WorkingTimeBundle) [![Coverage](https://img.shields.io/badge/Coverage-100%25-brightgreen)](#tests-and-coverage)

> ⭐ **Found this useful?** Install it from [Packagist](https://packagist.org/packages/nowo-tech/working-time-bundle) and give it a star on [GitHub](https://github.com/nowo-tech/WorkingTimeBundle): it helps us maintain and improve the project.

**A working-hours register (art. 34.9 ET), public holidays and leave for Symfony, in one bundle with three parts that
can be enabled separately, a private entity manager, a UiKit + FormKit UI and bridges to the other Nowo bundles.**

**Standard Symfony by default, DDD / hexagonal opt-in:** zero configuration in a standard project; public ports are
interfaces and value objects with no Symfony, Doctrine or HTTP types, the bundle-owned tables are mapped in XML (with an
attribute twin) and referenced by id, and the bundle never flushes your EntityManager.

> 🚧 **Status: S0 scaffold** (no tag yet). The bundle class, the strict configuration tree, the three part toggles and
> the private entity manager are in place; the register, holiday and leave features arrive slice by slice from
> [docs/CONTRACT.md](docs/CONTRACT.md) ([docs/ROADMAP.md](docs/ROADMAP.md)).

> 📋 **Compatible with Symfony 7.4, 8.0 and 8.1** · PHP 8.2+ (Symfony 8.x requires PHP 8.4+)

![FrankenPHP Friendly Worker Mode](docs/images/frankenphp-friendly.png)

This bundle is **FrankenPHP worker mode friendly** (kernel kept alive and not reset).

## Features

Three parts, switched separately (`register.enabled` on, `holidays.enabled` on, `leave.enabled` off):

- **Register**: workdays and segments, clock state machine, schedules, corrections with approval, append-only revisions
  with a SHA-256 hash chain and database triggers, verifier, inspection export (PDF / CSV / ZIP), retention purge.
- **Holidays** (stand-alone): holiday calendars, work centres, providers (festivos.io, OpenHolidays, CSV / ICS import).
- **Leave**: leave types, allowances, requests with a per-worker lock, approval chain, team calendar, iCal, payroll CSV.
- **Private entity manager** `nowo_working_time`, declared by the Flex recipe, so the bundle never flushes the host's
  unit of work.
- **Closed PDF rendering**: no remote resources, no JavaScript, no PHP.

Built slice by slice: S0 (this scaffold) → S1 register → S2 holidays + leave foundations → S3 leave workflow.

## Installation

```bash
composer require nowo-tech/working-time-bundle
```

See [docs/INSTALLATION.md](docs/INSTALLATION.md).

## Configuration

```yaml
# config/packages/nowo_working_time.yaml
nowo_working_time:
    doctrine:
        entity_manager: nowo_working_time
    register: { enabled: true }
    holidays: { enabled: true }
    leave: { enabled: false }
```

Every key of the contract is already accepted (strict tree): [docs/CONFIGURATION.md](docs/CONFIGURATION.md).

## Requirements

- PHP >= 8.2, < 8.6 (Symfony 8.x needs PHP 8.4+)
- Symfony 7.4, 8.0 or 8.1
- Doctrine DBAL 3.8+ / 4 and ORM 3.7+ with DoctrineBundle
- `nowo-tech/ui-kit-bundle` ^2.49, `nowo-tech/form-kit-bundle` ^2.22, `nowo-tech/outbound-url-guard-bundle` ^1.5

## Demo

`demo/symfony8` (Symfony 8.1, http://localhost:8241): FrankenPHP worker mode, MySQL, UiKit layout (`make -C demo up-symfony8`).
At S0 it is a single page showing the part toggles. See [docs/DEMO-FRANKENPHP.md](docs/DEMO-FRANKENPHP.md).

## Documentation

- [Installation](docs/INSTALLATION.md)
- [Configuration](docs/CONFIGURATION.md)
- [Usage](docs/USAGE.md)
- [Contributing](docs/CONTRIBUTING.md)
- [Code of Conduct](CODE_OF_CONDUCT.md)
- [Changelog](docs/CHANGELOG.md)
- [Upgrading](docs/UPGRADING.md)
- [Release](docs/RELEASE.md)
- [Security](docs/SECURITY.md)
- [Engram](docs/ENGRAM.md)
- [Spec-driven development](docs/SPEC-DRIVEN-DEVELOPMENT.md)
- [GitHub Spec Kit](docs/SPEC-KIT.md)
- [Roadmap](docs/ROADMAP.md)

### Additional documentation

- [Contract](docs/CONTRACT.md)
- [Architecture](docs/ARCHITECTURE.md)
- [Demo with FrankenPHP](docs/DEMO-FRANKENPHP.md)
- [PSR compliance](docs/PSR.md)
- [GitHub CI](docs/GITHUB_CI.md)

## Tests and coverage

```bash
make test               # PHPUnit in Docker
make test-coverage-100  # fails below 100 % of src/
make test-with-db       # Database suite on MySQL 8.4, MariaDB 11.4 and PostgreSQL 16
make release-check      # every gate, demo included
```

- PHP: 100 % of `src/` (gate in CI). Database suite on SQLite, MySQL 8.4, MariaDB 11.4 and PostgreSQL 16 in CI.

## License

MIT. See [LICENSE](LICENSE).

## Author

Created by [Héctor Franco Aceituno](https://github.com/HecFranco) at [Nowo.tech](https://nowo.tech).
