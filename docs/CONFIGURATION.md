# Configuration

The tree is strict: unknown keys fail the container build. Every key of contract section 10 is accepted; 0.1 reads the
ports, `permissions`, `doctrine`, `integrity`, `register` and `ui` (holidays and leave keys are read from S2 / S3).
Values are published as container parameters `nowo_working_time.<path>`.

## Register (0.1)

```yaml
nowo_working_time:
    tenant_resolver: ~             # service id; null = single tenant "default"
    permission_checker: ~          # service id; null = role map below (every list empty = denied)
    worker_directory: ~            # service id; null = only the signed-in user
    brand_provider: ~              # service id; null = the container's BrandProviderInterface, else a neutral brand
    clock: ~                       # service id; null = the framework clock
    notifier: auto                 # auto (Symfony Mailer when installed) | null | <service id>
    notifier_from: no-reply@localhost
    audit_sink: auto               # auto (one PSR log line per entry) | <service id>
    permissions:
        roles: { clock: [], manage_team: [], export: [], manage_settings: [] }
    integrity:
        triggers: warn             # warn | require | off
        purge_guard: session_variable   # session_variable | db_user (purge_db_user + purge_connection)
        verify_before_export: true
    register:
        schedule:
            default_timezone: Europe/Madrid
            default: ~             # { ranges: { mon: ['09:00-14:00'] }, timezone: … } or { daily_target_minutes: 420 }
        missing_clock_out_grace_minutes: 120
        long_day_hours: 16
        late_client_minutes: 10
        correction_window_days: 31 # default of new tenants (they choose 1..31)
        retention_years: 4         # default of new tenants (they choose 4..10)
        ip_evidence: prefix        # off | prefix (tenants opt in with a notice; /24 and /64 only)
        reminders: { window_minutes: 15 }
        monthly_summary: { enabled: true }
        export:
            formats: [pdf, csv]    # pdf needs dompdf/dompdf or a renderer service; the recipe ships [csv]
            max_months: 12
            download_days: 7
            async: auto            # auto (Messenger when enabled) | sync | messenger
        task_timer_link: auto      # auto (TimeTrack when installed) | off | <service id of a TaskTimerLinkInterface>
    ui:
        enabled: false             # routes are opt-in
        register_path: /working-time
        settings_path: /working-time/settings
        page_size: 25
        layout: ~                  # null = nowo_ui_layout.admin
```

`doctrine.mapping: attribute` is refused in 0.x (the XML mapping is the only one shipped). A `pdf` format without a
renderer, `export.async: messenger` without Messenger and an invalid `register.schedule.default` fail the build with
the fix.

## Whole tree

```yaml
nowo_working_time:
    doctrine:
        entity_manager: nowo_working_time   # private EM declared by the recipe; null = the default EM
        table_prefix: nowo_
        mapping: xml                        # xml | attribute
    holidays: { enabled: true }             # part toggle
    leave:                                  # part toggle, off by default; needs register and holidays
        enabled: false
        lock:
            store: db_row                   # db_row (default) | lock_factory
            lock_factory: ~                 # service id of a shared lock store, required for lock_factory
            wait_seconds: 5                 # 1..30, then 409 + Retry-After
            ttl_seconds: 30
    register:                               # part toggle
        enabled: true
        export:
            formats: [pdf, csv]
            pdf:                            # closed rendering (REQ-REPORT-001)
                renderer: dompdf
                remote_resources: false     # fixed: true is refused
                javascript: false           # fixed: true is refused
                php: false                  # fixed: true is refused
```

## Part toggles

| Key | Default | Rule |
| --- | --- | --- |
| `register.enabled` | `true` | Workdays, schedules, corrections, exports |
| `holidays.enabled` | `true` | Stand-alone holiday component (imports nothing from register or leave) |
| `leave.enabled` | `false` | Needs `register.enabled` and `holidays.enabled`, else the configuration is refused |

## Validation (container build)

`leave.enabled` needs register and holidays; `leave.lock.store: lock_factory` needs `leave.lock.lock_factory`;
`leave.attachments.enabled` needs a store; `register.retention_years` is 4..10; `integrity.purge_guard: db_user` needs
`purge_db_user`; UI paths are distinct and start with `/`; provider base URIs are https; the PDF renderer cannot enable
remote resources, JavaScript or PHP; `permissions.not_available_status` is fixed to 404. The permission role lists are
empty by default: everything is denied until the host maps roles.

The full default tree with every key is in [CONTRACT.md](CONTRACT.md) section 10.
