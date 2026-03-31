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

Po zmianach w bazowym `dist/` albo po wymianie upstreamowych assetow logowania odtworz lokalny overlay skryptem:

```bash
./cloud-local/scripts/rebuild-frontend-overlay.sh
```

Po przebudowie zrestartuj `supla-cloud`, zeby kontener zlapal aktualny bind mount `web/dist/`:

```bash
docker compose \
  -f supla-docker/docker-compose.yml \
  -f supla-docker/docker-compose.standalone.yml \
  -f supla-docker/docker-compose.cloud-local.yml \
  up -d --force-recreate supla-cloud
```

Skrypt:

- korzysta z zachowanych snapshotow upstreamowych plikow:
  - `index-DfO4cloU-limitsfix-upstream.js`
  - `login-page-CfxWV-Jq-upstream.js`
  - `login-form-C8Lf4buW-upstream.js`
  - `resend-account-activation-link-DP8hDyU_-upstream.js`
- generuje docelowy lancuch assetow `*-edgefix.js` dla sciezki logowania,
- przepina `index.html`, entry bundle i kompatybilnosciowe wrappery na wariant `edgefix`,
- usuwa tymczasowe pliki diagnostyczne `refsfix*`.

Cel tego podejscia:

- ominac cache edge/CDN po starych nazwach assetow,
- zachowac powtarzalna odbudowe overlayu bez osobnego checkoutu upstreamowego frontendu,
- trzymac tylko minimalny zestaw snapshotow potrzebnych do regeneracji sciezki logowania.
