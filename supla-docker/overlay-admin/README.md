Admin overlay for `supla-cloud`

Purpose

This directory contains the Symfony admin-panel overlay mounted by
`supla-docker/docker-compose.yml` into the upstream `supla/supla-cloud`
container.

Why it exists

The running image did not contain the admin panel routes and controllers used
by this installation. As a result:

- `/admin/login` and `/admin/account` were routed to the SPA fallback
- the admin firewall and provider were missing from container config
- the cyclic energy price forecast command crashed on malformed forecast data

What is mounted

- `app/config/routing.yml`
- `app/config/security.yml`
- `app/config/services/services.yml`
- admin controllers, listeners, and security classes under `src/SuplaBundle`
- `Command/Cyclic/EnergyPriceForecastFetchCommand.php`

Operational note

If the upstream image is updated and starts shipping these files natively,
review this overlay before removing it. Until then, treat this directory as the
source of truth for the local admin-panel customization.
