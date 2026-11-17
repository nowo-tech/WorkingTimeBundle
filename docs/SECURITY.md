# Security

## Status and scope

0.1 ships the working-hours register. The security model is contract sections 6 (integrity: hash chain, triggers,
purge guard), 13 (permission matrix, deny by default), 14 (privacy and GDPR) and 15 (concurrency).

| Input | Handling |
| --- | --- |
| Configuration | Strict tree (unknown keys refused); closed PDF rendering (no remote resources, JavaScript or PHP); https-only provider URIs; permission role lists empty (deny by default); `purge_guard: db_user` needs `purge_db_user` and `purge_connection` |
| Clock, corrections, exports, settings | Checked in the services, not only the controllers: 404 for no tenant, feature off, non-member and other-tenant rows; 403 for a missing permission; nobody decides or records on their own day |
| Downloads | An own copy is its requester's only; an inspection export needs `export` at download time, also for its requester |
| Concurrent writes | A stale version or a chain head taken by another writer answers 409 + `Retry-After` (one replay first); nothing half-written is stored |
| Instants | Stored in UTC whatever the PHP default time zone (`nowo_wt_utc_datetime` DBAL type) |

## Integrity: what the bundle guarantees and what it cannot

The append-only triggers refuse UPDATE and DELETE on the register tables (PostgreSQL also TRUNCATE); the hash chain and
`nowo:working-time:verify` find a changed payload, a removed link, a removed chain, a forged or out-of-retention anchor,
a segment opened or closed without a link, a head older than the newest segment, and a removal of whole chains when
earlier heads are given (`--heads`, the `chain_heads` stored with every export, or heads published to the audit sink
with `--publish-heads`). `triggers:check` verifies that every trigger exists, is enabled and has the shipped body.

A published head carries its link's `seq` (`{hash, seq, at}`; exports `{hash, seq}`). A head that is no longer a link
is accepted only when its `seq` is below the chain's start (the first link, or the last anchor's `first_kept_seq` for a
chain the purge emptied): a removed tail is found even after the chain's purges, and an anchor carrying the head's hash
does not excuse it (audit ST-D1, ST-D1b). An anchor past the chain's first link is reported as `anchor_after_start`,
and one keeping less than `seq` 2 as `anchor_seq_invalid`, since a purge only removes a non-empty prefix. Heads without
a `seq` (0.1.0 export rows, bare hashes passed to the API) keep the weaker 0.1.0 rule. Still out of reach: a whole chain removed together with a
forged anchor past its published head, by a role that can delete with the purge flag (the residual risk below).

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
