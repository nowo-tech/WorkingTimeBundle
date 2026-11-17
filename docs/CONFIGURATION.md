# Configuration

The tree is strict: unknown keys fail the container build. Every key of contract section 10 is accepted from S0; each
slice starts reading its part. Values are published as container parameters `nowo_working_time.<path>`.

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
