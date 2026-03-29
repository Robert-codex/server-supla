# Local Cloud Overrides

Ten katalog zawiera lokalne runtime override dla `supla-cloud`, które są montowane przez:

- `private-mqtt/docker-compose.cloud-local.yml`
- `supla-docker/docker-compose.cloud-local.yml`

Cel:

- włączyć edycję limitów konta z poziomu GUI,
- trzymać potrzebne pliki runtime wewnątrz repo `ServerSupla`,
- nie uzależniać wdrożenia od osobnego checkoutu `supla-cloud`.

`cloud-local/supla-cloud/web/dist/` zawiera zbudowany frontend, a `cloud-local/supla-cloud/src/` i `app/config/` zawierają nadpisywane pliki backendu.
