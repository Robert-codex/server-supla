# SUPLA Private Server + Mosquitto

Ten katalog zawiera samodzielny przykład prywatnego wdrożenia SUPLA z własnym brokerem MQTT. To prostsza alternatywa dla pełnego forka `supla-docker/`, gdy chcesz mieć lokalny, czytelny stack do uruchomienia na jednym VPS.

## Co zawiera katalog

- `docker-compose.yml` - bazowy wariant bez TLS dla MQTT.
- `docker-compose.tls.yml` - wariant z MQTT over TLS na `8883`.
- `docker-compose.db-auth.yml` - wariant TLS z autoryzacją użytkowników MQTT przez bazę SUPLA.
- `.env.example` - bezpieczny szablon zmiennych środowiskowych.
- `config/supla-server/supla-tls.cfg` - jawna konfiguracja `supla-server` dla wariantów TLS.
- `config/mosquitto/` - konfiguracja zwykłego Mosquitto.
- `config/mosquitto-go-auth/` - konfiguracja brokera z HTTP auth/ACL dla wariantu DB auth.
- `auth-service/` - mały serwis Flask sprawdzający użytkowników MQTT w bazie SUPLA.

## Ważna różnica między wariantami

- `docker-compose.yml` nie montuje ręcznie `supla.cfg`. W tym wariancie obraz `supla/supla-server` korzysta z ustawień przekazanych przez `.env`.
- `docker-compose.tls.yml` i `docker-compose.db-auth.yml` montują `config/supla-server/supla-tls.cfg`, bo wymagają jawnego ustawienia połączenia MQTT przez TLS.

## Przygotowanie

```bash
cd /home/langnet/Projekty/ServerSupla/private-mqtt
cp .env.example .env
```

Uzupełnij co najmniej:

- `CLOUD_DOMAIN`
- `DB_PASSWORD`
- `SECRET`
- `MQTT_BROKER_PASSWORD`
- `MAILPIT_SMTP_PORT`
- `MAILPIT_WEB_PORT`

Jeżeli używasz TLS, ustaw też port `MQTT_TLS_PORT`.

## Certyfikaty

Ten przykład zakłada trzy zestawy plików certyfikatów:

- `ssl/cloud/server.crt` i `ssl/cloud/server.key` dla UI SUPLA Cloud,
- `ssl/server/cert.crt` i `ssl/server/private.key` dla `supla-server`,
- `ssl/mqtt/server.crt` i `ssl/mqtt/server.key` dla brokera MQTT w wariantach TLS.

Pliki runtime z certyfikatami są ignorowane przez Git i nie powinny trafiać do repo.

## Start

Wariant bazowy:

```bash
docker compose up -d
```

Wariant TLS:

```bash
docker compose -f docker-compose.tls.yml up -d
```

Wariant TLS z auth z bazy:

```bash
docker compose -f docker-compose.db-auth.yml up -d --build
```

Szybka kontrola:

```bash
docker compose ps
docker compose logs -f supla-db
docker compose logs -f supla-cloud
docker compose logs -f supla-server
docker compose logs -f mosquitto
```

## Włączenie MQTT dla użytkownika

Samo uruchomienie brokera nie wystarcza. `supla-server` publikuje MQTT tylko dla użytkowników z aktywnym `mqtt_broker_enabled`.

```bash
set -a
. ./.env
set +a
docker compose exec supla-db mariadb -usupla -p"$DB_PASSWORD" supla
```

```sql
UPDATE supla_user
SET mqtt_broker_enabled = 1
WHERE email = 'twoj_uzytkownik@example.com';
```

Dla wariantu `docker-compose.db-auth.yml` ustaw również hasło MQTT użytkownika:

```sql
UPDATE supla_user
SET mqtt_broker_enabled = 1,
    mqtt_broker_auth_password = SHA2('twoje_haslo_mqtt', 512)
WHERE email = 'twoj_uzytkownik@example.com';
```

## Logowanie do MQTT

W wariancie bazowym i TLS bez DB auth:

- login: `MQTT_BROKER_USERNAME`
- hasło: `MQTT_BROKER_PASSWORD`

W wariancie DB auth:

- login: `short_unique_id` użytkownika SUPLA,
- hasło: wartość, którą haszujesz do `mqtt_broker_auth_password`,
- konto techniczne `MQTT_BROKER_USERNAME` nadal jest potrzebne do połączenia `supla-server` z brokerem.

## Generowanie hasła MQTT

Dla wariantu `docker-compose.db-auth.yml` możesz wygenerować lub zresetować hasło użytkownika bez ręcznej edycji SQL:

```bash
docker compose -f docker-compose.db-auth.yml exec mqtt-auth \
  python manage_mqtt_password.py --email twoj_uzytkownik@example.com
```

Po wykonaniu komendy skrypt:

- ustawia `mqtt_broker_enabled = 1`,
- zapisuje hash w `mqtt_broker_auth_password`,
- wypisuje jawne hasło tylko raz na stdout.

Jeżeli chcesz ustawić własne hasło zamiast losowego:

```bash
docker compose -f docker-compose.db-auth.yml exec mqtt-auth \
  python manage_mqtt_password.py --email twoj_uzytkownik@example.com \
  --password 'TwojeSilneHaslo123'
```

Możesz też wskazać użytkownika przez `short_unique_id`:

```bash
docker compose -f docker-compose.db-auth.yml exec mqtt-auth \
  python manage_mqtt_password.py --suid TWOJ_SHORT_UNIQUE_ID
```

## Zakres ACL w wariancie DB auth

Dozwolony odczyt i subskrypcja:

- `supla/<SHORT_UNIQUE_ID>/#`
- `homeassistant/<component>/<SHORT_UNIQUE_ID>/#`

Dozwolony zapis:

- `supla/<SHORT_UNIQUE_ID>/refresh_request`
- `supla/<SHORT_UNIQUE_ID>/devices/<DEVICE_ID>/channels/<CHANNEL_ID>/set/<COMMAND>`
- `supla/<SHORT_UNIQUE_ID>/devices/<DEVICE_ID>/channels/<CHANNEL_ID>/execute_action`

## Uwagi praktyczne

- Wariant `docker-compose.db-auth.yml` używa obrazu `iegomez/mosquitto-go-auth:3.0.0`.
- `private-mqtt/var/` zawiera dane runtime i logi, więc nie powinien być publikowany.
- Jeśli chcesz bazować na oficjalnym stacku zamiast tego uproszczonego przykładu, użyj `../supla-docker/` i jego overlayów MQTT.
