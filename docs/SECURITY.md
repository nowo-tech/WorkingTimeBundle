# Security

## Status and scope

S0 is the scaffold: no endpoint, no table and no personal data yet. The security model that the slices implement is
contract sections 6 (integrity: hash chain, triggers, purge guard), 13 (permission matrix, deny by default), 14 (privacy
and GDPR) and 15 (concurrency).

| Input | Handling at S0 |
| --- | --- |
| Configuration | Strict tree (unknown keys refused); closed PDF rendering (no remote resources, JavaScript or PHP); https-only provider URIs; permission role lists empty (deny by default) |

## Reporting

Report vulnerabilities privately through GitHub Security Advisories of `nowo-tech/WorkingTimeBundle` or by e-mail to
**hectorfranco@nowo.tech**. Please do not open a public issue.
