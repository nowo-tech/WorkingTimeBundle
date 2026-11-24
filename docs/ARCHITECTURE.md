# Architecture

Layout and rules: [CONTRACT.md](CONTRACT.md) section 4.

```
src/Domain/{Register,Leave,Holiday,Shared}/   VOs, enums, entities, ports, events (no Symfony / Doctrine / HTTP)
src/Application/{Register,Leave,Holiday}/     services using the ports
src/Infrastructure/Doctrine/                   mapping, repositories, triggers
src/Infrastructure/Symfony/                    DI, configuration tree, controllers, commands
src/Bridge/<Package>/                          the only code that imports optional packages
```

Rules enforced by `tests/Architecture/LayerDependencyTest`: `Holiday` imports nothing from `Register` or `Leave`;
`Register` imports nothing from `Leave`; `Domain` imports no Symfony, Doctrine or HTTP class. Persistence ports the
application owns (`Application\Register\Port\RegisterStoreInterface`, `RegisterPurgeStoreInterface`,
`TransactionRunnerInterface`) are implemented on Doctrine in `Infrastructure/Doctrine`; the entities are plain PHP in
`Domain\Register\Model` with the XML mapping in `src/Resources/config/doctrine`.

0.2 adds `Domain\Holiday` / `Application\Holiday` (calendars, days, work centres, the reader, the administration
service; persistence port `HolidayStoreInterface`, providers in `Infrastructure\Symfony\Holiday`) and `Domain\Leave` /
`Application\Leave` (types, policies, lines, settings, the allowance resolver, the day counter, balances; persistence
port `LeaveStoreInterface`, usage port `LeaveUsageInterface` filled by S3). The register reads holidays only through
`Domain\Port\RegisterDayAnnotationProviderInterface` (adapter `Application\Register\HolidayDayAnnotations`) and the
reader interface; the holiday component imports nothing from the register or leave. Shared refusals live in
`Domain\Shared\Exception` (404 `NotAvailableException`, 403 `AccessDeniedException`, translated `RefusedException`).
XML mappings: `Resources/config/doctrine` (register), `doctrine-holiday`, `doctrine-leave`.

## Architecture checks

[Deptrac](https://github.com/deptrac/deptrac) checks the dependencies between the layers on every CI run (REQ-ARCH-002; `deptrac.yaml`, `composer deptrac` / `make deptrac`, part of `make release-check` and of the PHPStan CI job). A violation, a dependency on an unlisted vendor namespace or a class under `src/` that belongs to no layer fails the build.

| Deptrac layer | Namespaces | May depend on |
| --- | --- | --- |
| `Domain` | `Domain` | itself, `psr/clock`, `psr/log`, `psr/event-dispatcher`, `nowo-tech/transaction-contract`, `nowo-tech/brand-contracts` |
| `Application` | `Application` | `Domain` and the same contract packages |
| `Infrastructure` | `Infrastructure` except the UI edge and DI below (plus `Symfony\Http\EntityManagerHygieneListener`, a Doctrine adapter) | `Domain`, `Application`, Symfony, Doctrine, Twig, PSR-6 cache, transaction-doctrine, UiKit / FormKit / OutboundUrlGuard |
| `UI` | `Infrastructure\Symfony\{Controller, Form, Routing, Command, Http, Twig}` | `Domain`, `Application`, Symfony, Twig, transaction-doctrine, UiKit / FormKit |
| `Bridge` | `Bridge\{Dompdf, TimeTrack, UserKit}` | `Domain`, `Application`, `Infrastructure`, Symfony, Doctrine, Twig and the bridged packages |
| `DependencyInjection` | `Infrastructure\Symfony\DependencyInjection`, the bundle class | every layer (composition root) |

Besides `LayerDependencyTest`, the trigger commands depend on the port `Application\Register\Port\RegisterTriggersInterface`
(`Infrastructure\Doctrine\Trigger\DoctrineRegisterTriggers`; `TriggerInstallException` now extends
`Application\Register\Port\TriggerInstallRefusedException`), the pages format local times with `Domain\Shared\LocalTime`
(`RegisterCsv::local()` delegates to it), and the translation domain and FormKit profile are read from
`Domain\TranslationDomain::NAME` and `Infrastructure\Symfony\Form\FormProfile::NAME` (the bundle constants keep the same
values). Skipped entries: the five conflict exceptions that implement transaction-doctrine's
`LockConflictExceptionInterface` (REQ-RUNTIME-003 rule 6); they go once `nowo-tech/transaction-contract` ships
framework-free conflict types (REQ-ARCH-002 rule 8).
