# Working Time Bundle demo (Symfony 7.4, FrankenPHP worker, MySQL)

```bash
make up      # http://localhost:8240 (127.0.0.1 only)
make setup   # composer install + database
make down
```

Sign in as `ana` (worker), `mia` (manager) or `admin` (admin); the password is `demo` for all three. The register starts
off: `admin` turns it on in `/working-time/settings`. The bundle runs on its own entity manager (`nowo_working_time`),
exports are built in the request (`register.export.async: sync`, no Messenger) and the entrypoint installs the
append-only triggers (`nowo:working-time:triggers:install`) after creating the schema.

The people come from `App\WorkingTime\DemoWorkerDirectory` (three fixed users of the in-memory security provider).
