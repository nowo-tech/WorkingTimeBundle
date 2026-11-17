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
