# PSR compliance

Package: `nowo-tech/working-time-bundle` (`symfony-bundle`)

| PSR | Status | Notes |
| --- | --- | --- |
| PSR-1 / PSR-12 Coding style | **Adopted** | PHP-CS-Fixer (`@PSR12` + `@Symfony`), `make cs-check` in CI |
| PSR-4 Autoloading | **Adopted** | `Nowo\WorkingTimeBundle\` maps to `src/` |
| PSR-3 Logger | **Planned** (S1) | Services take an optional `Psr\Log\LoggerInterface`; no personal data in log context |
| PSR-6 Cache | **Planned** (S2) | Holiday providers cache through a PSR-6 pool (`holidays.cache_pool`) |
| PSR-14 Event dispatcher | **Planned** (S1) | Domain events are plain classes dispatched after commit, ids and value objects only |
| PSR-20 Clock | **Planned** (S1) | Every timestamp reads an injected `Psr\Clock\ClockInterface` (`clock` port) |
| PSR-7 / PSR-15 / PSR-17 / PSR-18 | **N/A** | Symfony HttpFoundation and HttpClient are used directly |
