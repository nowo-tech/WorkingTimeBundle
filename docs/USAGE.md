# Usage

S0 ships the scaffold only: register the bundle, write the configuration and switch the parts you want
([CONFIGURATION.md](CONFIGURATION.md)). The services a host calls (`TimeClock`, `RegisterReader`, `LeaveRequestService`,
`HolidayCalendarReaderInterface`, and the rest of contract section 7) arrive with their slices
([ROADMAP.md](ROADMAP.md)).

```php
// The private entity manager (alias), once Doctrine is configured:
$em = $container->get('nowo_working_time.entity_manager');
```
