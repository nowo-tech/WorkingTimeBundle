# Security

## Status and scope

0.1 ships the working-hours register; 0.2 adds the holiday component and the leave foundations (types, allowances, balances). The security model is contract sections 6 (integrity: hash chain, triggers,
purge guard), 13 (permission matrix, deny by default), 14 (privacy and GDPR) and 15 (concurrency).

| Input | Handling |
| --- | --- |
| Configuration | Strict tree (unknown keys refused); closed PDF rendering (no remote resources, JavaScript or PHP); https-only provider URIs; permission role lists empty (deny by default); `purge_guard: db_user` needs `purge_db_user` and `purge_connection` |
| Clock, corrections, exports, settings | Checked in the services, not only the controllers: 404 for no tenant, feature off, non-member and other-tenant rows; 403 for a missing permission; nobody decides or records on their own day |
| Downloads | An own copy is its requester's only; an inspection export needs `export` at download time, also for its requester |
| Concurrent writes | A stale version or a chain head taken by another writer answers 409 + `Retry-After` (one replay first); nothing half-written is stored |
| Instants | Stored in UTC whatever the PHP default time zone (`nowo_wt_utc_datetime` DBAL type) |
| Holiday providers (0.2) | Called only on an admin action (`holidays_manage`: preview, then confirm), never on a page view or a schedule; fixed https base URIs from configuration; every request through OutboundUrlGuard (private and reserved targets refused, DNS pinned), timeout, no redirects; only the place (country, region, INE code) and the year are sent; any failure is one translated error and saves nothing; names are stripped of control and format characters, source URLs kept only when http(s) |
| Holiday files (0.2) | CSV / ICS only, UTF-8, at most `max_rows` rows and `max_bytes` bytes; nothing is saved by the preview; the confirm takes back only dates and names and checks them again (year, duplicates, length) |
| Concurrency (0.2) | Every S2 write path and its behaviour under contention: [CONCURRENCY.md](CONCURRENCY.md) |
| Leave retention (0.2) | Allowance lines and worker policies are in the GDPR export and erasure (free text blanked, amounts kept); their retention purge arrives with the requests (S3), until then they stay until a host removes them |
| Leave foundations (0.2) | No type, field or note holds a diagnosis, a cause or any health detail (the medical flag only hides the type as *Absent*); allowance-line reasons never reach audit rows; settings saves are versioned (409 + `Retry-After` when stale); every lookup is scoped by the tenant and re-checked with `===` |

## Integrity: what the bundle guarantees and what it cannot

The append-only triggers refuse UPDATE and DELETE on the register tables (PostgreSQL also TRUNCATE); the hash chain and
`nowo:working-time:verify` find a changed payload, a removed link, a removed chain, a forged or out-of-retention anchor,
a segment opened or closed without a link, a head older than the newest segment, and a removal of whole chains when
earlier heads are given (`--heads`, the `chain_heads` stored with every export, or heads published to the audit sink
with `--publish-heads`). `triggers:check` verifies that every trigger exists, is enabled and has the shipped body.

A published head carries its link's `seq`, time and workday date (`{hash, seq, at, day}`). A head that is no longer a
link is accepted only when its `seq` is below the chain's start (the first link, or the last anchor's
`first_kept_seq` for a chain the purge emptied) and its `day` is before the `first_kept_date` of the anchor at the
start: exactly the days the purge deletes, so a late correction or acknowledgement of a purged day is no false alarm,
and an anchor carrying the head's hash does not excuse it (audit ST-D1, ST-D1b, ST-H2). A forged anchor must keep a
`first_kept_date` at least four years before its purge date, and that date cannot be in the future (five minutes of
clock skew aside), so a head whose workday is younger than that cannot be hidden behind one (probes d2 and e2). An
anchor past the chain's first link is reported as `anchor_after_start`, and one keeping less than `seq` 2 as
`anchor_seq_invalid`, since a purge only removes a non-empty prefix. Heads without a `day` (0.1.1 heads files and
export rows) keep the 0.1.1 rule (below the start is enough), and heads without a `seq` (0.1.0 export rows, bare
hashes passed to the API) the weaker 0.1.0 rule. Still out of reach: a prefix of a chain whose published heads all
have a workday older than a forged anchor's `first_kept_date`, removed together with that anchor at the new start, by
a role that can delete with the purge flag (the residual risk below).

Store each published head entry whole, `day` included, write-once outside the database (`--heads-out` to write-once
storage, or `--publish-heads` to an audit sink kept elsewhere). The `day` is what ties a missing head to the purge
boundary: a heads file whose `day` was forged to an old date, or stripped (the 0.1.1 rule then applies), excuses a
prefix removed behind a forged anchor. The `chain_heads` stored with each export give no such protection on their own,
since the application can write those rows. A late link kept after its workday was purged (an orphan correction or
acknowledgement) is published with `day: null` and gets the weaker 0.1.1 rule.

**Residual risk (audit ST-I1).** The triggers are only as strong as the database role the application connects with.
When the application user owns the schema, it can drop or replace the triggers, disable them, or (in
`session_variable` mode) set the purge flag itself, and then rewrite the register and recompute the hashes. The chain
cannot detect a consistent rewrite of a whole chain on its own: only a head recorded outside the database can. For a
register that must stand up to an inspection, the host should:

1. Create the tables and install the triggers with a **separate schema owner**; the application role gets only
   `SELECT, INSERT, UPDATE, DELETE` on the tables, and **no `TRIGGER`, `ALTER`, `DROP` or ownership** (PostgreSQL:
   not the table owner and not a superuser).
2. Use `integrity.purge_guard: db_user` with a dedicated purge user and `purge_connection`: the triggers then let
   deletes through only for that user, whatever the application sets in its session.
3. Publish chain heads outside the database on a schedule (`nowo:working-time:verify --publish-heads` to an audit sink
   kept elsewhere, or `--heads-out` to write-once storage) and verify against them (`--heads`).
4. Keep `nowo:working-time:triggers:check` in the deploy and in monitoring.

## Shared entity manager (`doctrine.entity_manager: null`)

The same applies when `doctrine.entity_manager` names the registry's default manager (for example `default`).

With the private entity manager (default) the bundle clears it after each unit of work. With the host's default entity
manager the bundle never flushes or clears the host's work: before a register write it refuses (LogicException) when
the host has pending inserts, updates or deletions, and afterwards it detaches only the bundle's own entities. A write
that fails closes the shared entity manager, as Doctrine does for any failed flush; the host resets it.

## Reporting

Report vulnerabilities privately through GitHub Security Advisories of `nowo-tech/WorkingTimeBundle` or by e-mail to
**hectorfranco@nowo.tech**. Please do not open a public issue.
