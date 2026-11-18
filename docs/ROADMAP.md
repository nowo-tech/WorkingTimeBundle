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

## After 1.0: feature gap review (2026-11-18)

A review of what a working-time and leave product is usually expected to offer, measured against what the bundle has
(0.1.x on `main`) and what the slices above already plan (S2, S3). Items already in S1–S3 are not repeated here. Each
item below becomes a Spec Kit feature with its own spec, tests and audit before it is built; the order is the
priority, and versions are not fixed yet.

The register exists for art. 34.9 of the Estatuto de los Trabajadores (a daily record of each worker's start and end
time, kept for four years and available to the worker, their representatives and the Labour Inspectorate) and for the
right to digital disconnection (LOPDGDD art. 88). Items marked **(compliance)** close a gap against those rules; the
others are product features. The bundle is still not legal advice.

### Next

**Legal register and audit**

| Item | What it is | Why it helps |
| --- | --- | --- |
| **Representatives' access** (compliance) | A permission and read-only view for workers' representatives over the register of the workers they represent, with exports, scoped by the tenant's policy and audited | Art. 34.9 ET makes the register available to the representatives; today the employer hands over an export by hand |
| **Inspection access on demand** (compliance) | A time-limited, revocable access (link or account) that lets an inspector download the verified inspection export for a chosen range, with an audit row of what was shown and when | The inspectorate can ask at any time; a ready, verified channel avoids ad-hoc exports and proves what was delivered |
| **Monthly overtime report to representatives** (compliance) | A monthly summary of the overtime of each worker, delivered to the representatives' view and exportable | Art. 35.5 ET: overtime is recorded day by day, totalled with the pay period and reported to the representatives |
| **Monthly sign-off by the worker** | The worker confirms their month (or contests days, which opens corrections); the confirmation is a chain link | Evidence that the worker saw and accepted their record, and an early catch for forgotten clock-ins |
| **Watch for the digital-register rules** (compliance) | Track the pending regulation on a digital working-time register (remote access for the inspectorate, mandatory fields, traceable changes) and map each rule to a feature or a test | Keeps the bundle ahead of a rule change that would otherwise land as a rush release |

**Digital disconnection**

| Item | What it is | Why it helps |
| --- | --- | --- |
| **Disconnection policy** (compliance) | A versioned policy text per tenant that workers read and acknowledge, shown next to their schedule | LOPDGDD art. 88 asks the employer for an internal disconnection policy; storing who acknowledged which version is the evidence |
| **Quiet hours for every notice** (compliance) | One rule, from the schedule and the policy, that holds every bundle notice (reminders, leave, corrections) until the recipient's next working period, except notices the policy marks urgent | Notices already wait for the recipient's hours in the register; making it one rule for leave and every later notice keeps the policy true |

**Balances and schedules**

| Item | What it is | Why it helps |
| --- | --- | --- |
| **Hours balance per worker** | Worked versus expected time per week, month and year, with ratified overtime, compensatory time taken and the remaining credit, for the worker and their managers | The most asked question of a working-time tool ("how many hours do I owe or am I owed?"), built on data S1 and S3 already store |
| **Annual and flexible schedules** | Schedules defined by an annual or monthly hour target, core hours and flexible windows, and an irregular distribution within the limits the tenant sets | Many agreements count hours per year or allow flexitime; a weekly fixed schedule alone flags normal days as overtime |
| **Next year's holidays reminder** | A notice to holiday managers late in the year when a work centre has no calendar for the coming year, with a link to the import preview | Providers only run on an admin action (by design); a reminder keeps leave counts right on 1 January |

**API and integrations**

| Item | What it is | Why it helps |
| --- | --- | --- |
| **Read API** | `<prefix>/working-time/v1` for the worker's own month, balances and leave, token-authenticated, off by default (the 1.x read API of contract Q-O10) | Lets mobile shells, intranets and HR systems read the register without screen scraping |
| **Outgoing events for HR and payroll** | Signed webhooks (or Messenger messages) when a month is closed, a leave request is decided or a correction applied | Payroll and HR systems react to changes instead of polling the payroll CSV |

### Later

| Item | What it is | Why it helps |
| --- | --- | --- |
| **Shift planning** — after 1.0 (owner 2026-11-18) | Shift templates, a rota per team and work centre, publication to workers with a notice, swap requests with approval, and the rota as the day's schedule | Shift work is the main use of a register outside offices; today staffing is a warning only (a v1 non-goal, contract §1) |
| **Mobile-first clock-in** | A phone-first page (installable, with an offline queue that keeps the real time of each action) for clock-in, breaks and leave requests, without location | Workers away from a desk need a register they can reach; the server already stores offline times with `received_at` |
| **Hour classes for payroll** | Classify worked time as night, holiday, weekend or ordinary from the schedule and the holiday calendar, in the month view and the payroll CSV | Night and holiday hours are paid differently (art. 36 ET and agreements); payroll otherwise recomputes them by hand |
| **Absence and overtime reports** | Dashboards per team and work centre: absence rate by leave type (medical shown only as a count), overtime trend, leave taken versus allowance, pending approvals | Managers and HR plan with numbers instead of exports |
| **Leave forecast** | Projected balance at the end of the year from approved and pending requests and the remaining allowance | Prevents lost days and year-end rushes |
| **Calendar push of approved leave** | Write approved leave to the worker's connected calendar (on top of the iCal feeds of S3) | Colleagues see absences where they already plan meetings |
| **Document acknowledgements** | Workers acknowledge policies (schedule, disconnection, register notice) with a dated record | One place for the notices the register depends on |

### Considering

| Item | What it is | Why it helps | Note |
| --- | --- | --- | --- |
| **Shared-terminal clock-in** — after 1.0 (owner 2026-11-18) | A terminal page for a shared device where workers clock in with a PIN, a QR code or a badge number; never photos or biometrics | Factories, shops and clinics share one device | Kiosks are a v1 non-goal (contract §1); needs a threat model for PIN guessing and shared sessions |
| **Allowed networks** — after 1.0 (owner 2026-11-18) | A tenant may limit clocking to listed networks (the stored /24 or /64 prefix), warning or refusing elsewhere | A light, location-free check for on-site work | Opt-in; the worker notice must say it; never a precise location |
| **Collective-agreement rules** | Rule sets for seniority days, agreement-specific paid leave and accrual formulas beyond pro-rata | Removes manual allowance lines for each agreement | A v1 non-goal (contract §1); large and agreement-specific |
| **Project time from the register** | Split a workday's worked time across tasks through the TimeTrack bridge, with the register as the total | Avoids double entry where both registers are used | The register stays the legal total; task time never edits it |

### Not planned (decided)

- Geolocation, photo or biometric clocking (contract §1; data the register does not need, LOPDGDD art. 90).
- Any health detail in leave (owner rule; contract §14.3).
- Payroll calculation (the bundle exports; payroll systems compute).
