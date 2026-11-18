# Concurrency

Every write runs in a transaction-doctrine write unit on the bundle's entity manager (contract section 15;
REQ-RUNTIME-003): one transaction, flushed before the commit, replayed after a deadlock or a lock-wait timeout (up to
three attempts), the entity manager cleared afterwards. A conflict that a replay cannot settle answers
**409 + `Retry-After`** (transaction-doctrine's responder; JSON `{"error": "<code>"}` on the bundle's routes). Audit
rows, events and notices go out after the commit, so a replayed or refused attempt records nothing twice.

## Write paths

| Write | Protection | Under contention | Code (JSON) | Test |
| --- | --- | --- | --- | --- |
| Clock action (register) | Unique `(tenant, worker, seq)` and `(tenant, worker, request_key)` on the chain | One more run against the new head, then 409 | `chain_busy` | `RaceTest` (two connections) |
| Correction decision | `version` on the correction | 409, nothing stored, no notice | `correction_decision_stale` | `RaceTest` |
| Register settings | `version` (first save: primary key) | 409, form shown again | `settings_stale` | `RaceTest` |
| Export generation | Claim `UPDATE … WHERE status = 'pending'` | One generator wins | — | `RegisterServicesTest` |
| Notice delivery | Unique `dedupe_key`; `sent_at` set `WHERE sent_at IS NULL` | Delivered once | — | `RegisterServicesTest` |
| Purge | One transaction per batch; anchors in the same transaction | — | — | `PurgeTest` |
| **Leave type seed** (enable, *Add the default types*) | Unique `(tenant_id, seed_code)` (NULL for custom types); seeds looked up byte-exact over the tenant's types | The loser runs once more and finds them seeded; a second refusal is a 409 | `leave_seed_conflict` | `S2RaceTest` (two connections + in-connection) |
| **Leave type edit** | Row `FOR UPDATE` + `version` (hidden field) | A stale edit is a 409; a unit change waits for a running `addPolicy` / `addLine` and is checked after it | `leave_type_stale` | `AuditTdTest` |
| Allowance policy / line | The type's row `FOR UPDATE` (serialised with its edits); lines append-only; an adjustment re-reads the entitlement under the lock | — | — | `AuditTdTest`, `LeaveServicesTest` |
| Leave settings | `version` (first save: primary key) | 409, form shown again | `leave_settings_stale` | `LeaveServicesTest` |
| Carry-over expiry (daily) | `expired_at` set once (`??=`); batches of 500 | A parallel run expires nothing twice | — | `LeaveServicesTest` |
| **Default work centre** | One `UPDATE … SET is_default = (id = :id) WHERE tenant_id = :t` after the centre's own changes | The last switch wins; never two defaults | — | `S2RaceTest` (two connections + in-connection) |
| **Holiday calendar edit** | Row `FOR UPDATE` + `version` (hidden field) | A stale edit is a 409; a year change waits for a running `addDay` | `holiday_calendar_stale` | `AuditTdTest` |
| Holiday day (by hand) | Unique `(calendar_id, holiday_date)`; the calendar's row `FOR UPDATE` | A duplicate date is a form error | — | `HolidayServicesTest` |
| **Import confirm** (file, provider) | Unique `(calendar_id, holiday_date)`; the calendar's row `FOR UPDATE`; the provider is asked before the transaction | The loser runs once more and skips the dates the other stored; a second refusal is a 409 | `holiday_import_conflict` | `S2RaceTest` (two connections + in-connection) |

The two-connection tests boot a second kernel on its own database connection and run the competing write inside the
first one's transaction (MySQL 8.4, MariaDB 11.4, PostgreSQL 16 in CI; skipped on SQLite, which has one writer). The
in-connection tests insert, on every database, what the other writer would commit between our read and our flush.

## Isolation and locks

The bundle assumes READ COMMITTED (REQ-DB-003) and short lock waits. Locks are taken in a fixed order inside a unit
(the type or calendar row first, then its children), so the S2 paths cannot deadlock against themselves; a deadlock with
another path is replayed by the write unit. No lock or entity survives the call (FrankenPHP worker mode, kernel reset
false).
