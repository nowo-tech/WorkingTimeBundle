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
`Register` imports nothing from `Leave`; `Domain` imports no Symfony, Doctrine or HTTP class. At S0 only
`Infrastructure/Symfony` exists (bundle class, configuration tree, private entity manager check).
