# Upgrading

## Table of contents

- [Unreleased](#unreleased)

## Unreleased

First release (0.1.0, the register). After installing: create the tables on the bundle's entity manager, run
`bin/console nowo:working-time:triggers:install` (the database user needs the TRIGGER privilege; with MySQL binary
logging also `SUPER` or `log_bin_trust_function_creators = 1`), map the permission roles or configure your
permission checker (everything is denied by default), and turn the register on per tenant in the settings page.
`register.export.formats` containing `pdf` needs `dompdf/dompdf`.
