# Roadmap

The bundle is built slice by slice from [CONTRACT.md](CONTRACT.md) (section 19). Each slice is one pull request with
tests, docs and a green CI; 0.x tags per slice after their audit, **1.0.0** after the REQ-SEC-004 audits and the
`bundle-req-audit` at 100 % of the applicable REQs.

## Slices

| Slice | Version | Content | Status |
| --- | --- | --- | --- |
| **S0** scaffold | `main`, no tag | Composer, Docker (MySQL 8.4, MariaDB 11.4, PostgreSQL 16, SQLite), Makefile + `release-check`, CI, PHPStan + phpstan-frankenphp, Igor, Rector, CS, coverage gate, Spec Kit baseline, docs set, Flex recipe (private entity manager), demo skeleton, strict configuration tree | Merged |
| **S1** register | 0.1.0 | Register domain and persistence, triggers on four platforms, hash chain, corrections, exports, UI | Released (0.1.0 – 0.1.2) |
| **S2** holidays + leave foundations | 0.2.0 | Holiday component (calendars, work centres, festivos.io / OpenHolidays / file imports, reader, UI), leave types + seeding, allowance policies and lines, pro-rata, carry-over (protected, expiry job), day counter, balances, register day annotations | In review |
| **S3** leave workflow | 1.0.0 | Requests with the per-worker lock, approval chain, calendar, payroll CSV | |
