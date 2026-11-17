# Installation

```bash
composer require nowo-tech/working-time-bundle
```

With Symfony Flex the recipe registers the bundle, writes `config/packages/nowo_working_time.yaml` and declares the
**private entity manager** `nowo_working_time` in `config/packages/nowo_working_time_doctrine.yaml`.

Without Flex, register `Nowo\WorkingTimeBundle\NowoWorkingTimeBundle` in `config/bundles.php` and declare the entity
manager yourself:

```yaml
# config/packages/doctrine.yaml (the host must use the multi-entity-manager form)
doctrine:
    orm:
        default_entity_manager: default
        entity_managers:
            default:
                connection: default
                auto_mapping: true
            nowo_working_time:
                connection: default
```

A host that must keep a single entity manager sets `nowo_working_time.doctrine.entity_manager: null`: the bundle then
uses the default one with explicit transactions (contract Q-O3a). The container build fails with the fix when the
configured manager is not declared.

Requirements: PHP >= 8.2 < 8.6, Symfony 7.4 / 8.x, Doctrine DBAL 3.8+ / 4 and ORM 3.7+, `nowo-tech/ui-kit-bundle`,
`nowo-tech/form-kit-bundle`, `nowo-tech/outbound-url-guard-bundle`.
