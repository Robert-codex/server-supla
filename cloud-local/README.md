# Local Cloud Overrides

Ten katalog zawiera lokalne runtime override dla `supla-cloud`, które są montowane przez:

- `private-mqtt/docker-compose.cloud-local.yml`
- `supla-docker/docker-compose.cloud-local.yml`

Cel:

- włączyć edycję limitów konta z poziomu GUI,
- pozwolić wyłączyć zależność od `autodiscover.supla.org` przez `SUPLA_AUTODISCOVER_URL=`,
- trzymać potrzebne pliki runtime wewnątrz repo `ServerSupla`,
- nie uzależniać wdrożenia od osobnego checkoutu `supla-cloud`.

`cloud-local/supla-cloud/web/dist/` zawiera zbudowany frontend, a `cloud-local/supla-cloud/src/` i `app/config/` zawierają nadpisywane pliki backendu.

Frontend source upstream nie jest trzymany w tym repo. Z tego powodu poprawki GUI w `web/dist/` są utrzymywane jako powtarzalny overlay binarny, a nie jako normalny build Vite/Vue.

Po zmianach w bazowym `dist/` odtwórz lokalny overlay skryptem:

```bash
./cloud-local/scripts/rebuild-frontend-overlay.sh
```

Skrypt:

- generuje docelowe assety `*-loginfix.js` dla ścieżki logowania,
- korzysta z zachowanej kopii upstreamowego entry bundle `index-DfO4cloU-limitsfix-upstream.js`,
- przepina entry bundle na wariant `loginfix`,
- usuwa tymczasowe pliki diagnostyczne `refsfix*`.
