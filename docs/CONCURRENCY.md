# Concurrency

Every write runs in a transaction-doctrine write unit on the bundle's entity manager (contract section 15;
REQ-RUNTIME-003): one transaction, flushed before the commit, replayed after a deadlock or a lock-wait timeout (up to
three attempts), the entity manager cleared afterwards. A conflict that a replay cannot settle answers
**409 + `Retry-After` + `X-Conflict-Code: CONFLICT_001`** (transaction-doctrine's responder; JSON
`{"error": "<code>", "code": "CONFLICT_001"}` on the bundle's routes), or **409 + `X-Conflict-Code: CONFLICT_002`**
without `Retry-After` when part of the request was already committed (see [Conflict answer](#conflict-answer)). Audit
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

## Conflict answer

REQ-RUNTIME-003 rule 6, on the bundle's routes only (host routes are never answered by the bundle):

| Failure | Status | Headers | JSON |
| --- | --- | --- | --- |
| Lock conflict, deadlock, lock-wait timeout or serialization failure; nothing committed | 409 (the policy's status: 503 when the host sets `conflict_response.status: 503`) | `Retry-After`, `X-Conflict-Code: CONFLICT_001`, `Cache-Control: no-store` | `{"error": "<code>", "code": "CONFLICT_001"}` |
| The same after part of the request committed (an earlier unit of the request, or a unit that committed before its conflict: both need NowoTransactionDoctrineBundle with `commit_detection`, on by default; a Messenger `HandlerFailedException` with a `HandledStamp`; any `DelayedMessageHandlingException`) | 409 | `X-Conflict-Code: CONFLICT_002`, no `Retry-After` | `{"error": "<code>" or "concurrency_conflict_partial", "code": "CONFLICT_002"}` |
| Unique violation, lost connection | not a conflict: the bundle's domain answer where it expects the race (see the table above), otherwise the host's error handling | | |

- The code is `ConflictCode::forRequest()` (transaction-doctrine >= 1.6), with the request's commit watermark when
  NowoTransactionDoctrineBundle takes one. **Detecting an earlier commit needs NowoTransactionDoctrineBundle with
  `conflict_response.commit_detection`** (on by default): it takes the request's watermark and tracks commits. Without
  the bundle, or with `commit_detection: false`, an earlier commit in the request (or a unit that committed before
  its conflict) answers `CONFLICT_001`; only the Messenger cases above still answer `CONFLICT_002`.
  `X-Conflict-Code` carries only `CONFLICT_001` or `CONFLICT_002`; the bundle's own codes (`settings_stale`,
  `chain_busy`, ...) stay in the JSON `error`.
- Detection is transaction-doctrine's bounded walk (`TransientFailureClassifier::findLockConflict()` /
  `findConflict()`, >= 1.7): the `getPrevious()` chain **and** Messenger's wrapped exceptions, so a deadlock in the
  second handler of a synchronous `HandlerFailedException` is found. Each throwable is read once, at most 256.
- NowoTransactionDoctrineBundle's responder (`kernel.exception`, priority 64) answers the lock conflicts, with
  `WorkingTimeConflictFormatter` shaping the JSON of the bundle's routes. The bundle's `ConflictFallbackListener`
  (priority 63, bundle routes only) answers what the responder leaves: every conflict when the responder's bundle is
  not registered, and otherwise the raw transient failures no write unit wrapped (the responder maps those only with
  `map_transient_failures: true`). When the responder exists the listener answers through its `respond()` (the
  branded HTML page, the bundle's JSON formatter); either way it logs one constant warning.
- A stale version re-rendered as a form (settings, leave settings, a correction decision) answers 409 with the same
  headers.

## Replays and the host's entity manager

- **Private entity manager** (`doctrine.entity_manager: nowo_working_time`, the default): the write unit is scoped to
  it with transaction-doctrine's `forManagers()` (>= 1.7). A replay clears or resets only the bundle's manager; the
  host's open default manager keeps its entities. Every write of a unit goes through the bundle's manager (the stores
  take it from the runner). Leftover transaction levels are rolled back on every connection, as before.
- **Host ports must not write through the host's entity manager inside a unit** (the audit sink, the worker directory;
  audit UZ-1). What happens if one does, on the private manager:

  | The port, inside the unit | Then a deadlock or lock-wait timeout |
  | --- | --- |
  | `persist()` without `flush()` | Not replayed: a 409 (`CONFLICT_001`). The runner detaches what the attempt added to the host's manager (an entity with an assigned id); an entity still waiting for a generated id makes transaction-doctrine abandon the replay and clear every manager. |
  | `persist()` + `flush()` on the same connection as the bundle (a savepoint inside the unit) | The deadlock rolls the insert back. The runner does not replay: it detaches the inserted entities from the host's manager, so no rolled-back entity stays managed in the worker, and answers 409 (`CONFLICT_001`). A host `flush()` that only **updates** an entity the host already managed does not grow the identity map: the unit is replayed and the host's copy keeps the rolled-back values (stale). |
  | `persist()` + `flush()` on another connection (the host's own) | That flush committed. The runner does not replay and answers 409 `CONFLICT_002`, without `Retry-After`. If the write unit has a commit ledger (NowoTransactionDoctrineBundle with `commit_detection`), it refuses the replay of any committed attempt with `CONFLICT_002` as well. Without a ledger, a host update on another connection is repeated by the replay. |

  Ports should log, or write after the commit (an outbox, an after-commit event).
- **Shared manager** (`doctrine.entity_manager: null`, or the default manager's name): the unit writes through the
  host's manager, so it is not scoped. The runner refuses to start while the host has unflushed changes, and only the
  bundle's entities are detached after a unit, but a deadlock replay clears the shared manager (transaction-doctrine's
  repair). Hosts that keep entities managed across a call to the register should use the private manager.

## Residual risks

- A host port that writes through the host's entity manager inside a unit: see the table above. An update of an
  entity the host already managed is replayed. On the same connection, the host's copy then keeps rolled-back values.
  On another connection without a commit ledger, the update is repeated.
- A failure carrying more than 256 throwables, or a broken Messenger wrapper, answers `CONFLICT_002` (the safe
  over-approximation of transaction-doctrine's walk).
