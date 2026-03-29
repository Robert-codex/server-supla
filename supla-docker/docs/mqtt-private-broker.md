# Private MQTT Broker For SUPLA Docker

Ten dodatek rozszerza oficjalne `supla-docker` o własny broker MQTT.

Masz trzy warianty:

- `docker-compose.mqtt.yml` - prosty broker Mosquitto na `1883`
- `docker-compose.mqtt-tls.yml` - wariant produkcyjny z TLS na `8883`
- `docker-compose.mqtt-db-auth.yml` - wariant produkcyjny z TLS na `8883` i auth opartym o bazę SUPLA

## 1. Uzupełnij `.env`

W `supla-docker/.env` ustaw co najmniej:

```dotenv
MQTT_BROKER_ENABLED=true
MQTT_BROKER_HOST=mosquitto
MQTT_BROKER_PORT=1883
MQTT_BROKER_TLS=false
MQTT_BROKER_USERNAME=supla
MQTT_BROKER_PASSWORD=CHANGE_ME_MQTT_PASSWORD
MQTT_BROKER_CLIENT_ID=supla-server

MQTT_PORT=1883
MQTT_TLS_PORT=8883
```

Dla wariantu TLS:

```dotenv
MQTT_BROKER_PORT=8883
MQTT_BROKER_TLS=true
```

## 2. Certyfikaty dla TLS

W wariantach TLS umieść certyfikat i klucz w:

- `mqtt/ssl/mqtt/server.crt`
- `mqtt/ssl/mqtt/server.key`

Jeżeli używasz Let's Encrypt, najczęściej wystarczy skopiować:

- `fullchain.pem` jako `server.crt`
- `privkey.pem` jako `server.key`

Oficjalny obraz `supla/supla-server` generuje konfigurację MQTT z `MQTT_BROKER_*` oraz ustawień bazy danych z `.env`, więc nie trzeba ręcznie edytować `supla.cfg`.

## 3. Start z overlayem

Poniżej najprostsze komendy.

Prosty broker:

```bash
docker compose -f docker-compose.yml -f docker-compose.standalone.yml -f docker-compose.mqtt.yml up -d
```

Broker z TLS:

```bash
docker compose -f docker-compose.yml -f docker-compose.standalone.yml -f docker-compose.mqtt-tls.yml up -d
```

Broker z TLS i auth z bazy SUPLA:

```bash
docker compose -f docker-compose.yml -f docker-compose.standalone.yml -f docker-compose.mqtt-db-auth.yml up -d --build
```

Jeżeli używasz proxy mode, zamień `docker-compose.standalone.yml` na `docker-compose.proxy.yml`.

## 4. Włączenie MQTT dla użytkownika

To jest wymagane w każdym wariancie.

```bash
set -a
. ./.env
set +a
docker compose exec supla-db mariadb -usupla -p"$DB_PASSWORD" supla
```

```sql
UPDATE supla_user
SET mqtt_broker_enabled = 1,
    mqtt_broker_auth_password = SHA2('twoje_haslo_mqtt', 512)
WHERE email = 'twoj_uzytkownik@example.com';
```

## 5. Jak logować się do MQTT

W wariancie prostym i TLS bez DB auth:

- login: `MQTT_BROKER_USERNAME`
- hasło: `MQTT_BROKER_PASSWORD`

W wariancie `docker-compose.mqtt-db-auth.yml`:

- login: `short_unique_id` użytkownika SUPLA
- hasło: to samo, które wpiszesz do `mqtt_broker_auth_password` przez `SHA2(..., 512)`
- `MQTT_BROKER_USERNAME` i `MQTT_BROKER_PASSWORD` nadal są używane przez `supla-server` jako techniczne konto brokera

## 6. Generowanie hasła MQTT

W wariancie `docker-compose.mqtt-db-auth.yml` możesz wygenerować lub zresetować hasło użytkownika z poziomu kontenera `mqtt-auth`:

```bash
docker compose -f docker-compose.yml -f docker-compose.standalone.yml -f docker-compose.mqtt-db-auth.yml exec mqtt-auth \
  python manage_mqtt_password.py --email twoj_uzytkownik@example.com
```

Skrypt:

- ustawia `mqtt_broker_enabled = 1`,
- zapisuje `SHA2(haslo, 512)` do `mqtt_broker_auth_password`,
- wypisuje wygenerowane hasło tylko raz.

Możesz też podać własne hasło:

```bash
docker compose -f docker-compose.yml -f docker-compose.standalone.yml -f docker-compose.mqtt-db-auth.yml exec mqtt-auth \
  python manage_mqtt_password.py --email twoj_uzytkownik@example.com \
  --password 'TwojeSilneHaslo123'
```

## 7. Zakres ACL w wariancie DB auth

Dozwolony odczyt i subskrypcja:

- `supla/<SHORT_UNIQUE_ID>/#`
- `homeassistant/<component>/<SHORT_UNIQUE_ID>/#`

Dozwolony zapis:

- `supla/<SHORT_UNIQUE_ID>/refresh_request`
- `supla/<SHORT_UNIQUE_ID>/devices/<DEVICE_ID>/channels/<CHANNEL_ID>/set/<COMMAND>`
- `supla/<SHORT_UNIQUE_ID>/devices/<DEVICE_ID>/channels/<CHANNEL_ID>/execute_action`

## 8. Uwagi techniczne

- Wariant `docker-compose.mqtt-db-auth.yml` używa `iegomez/mosquitto-go-auth:3.0.0`.
- Repo `mosquitto-go-auth` zostało zarchiwizowane 8 czerwca 2025, więc warto pinować wersję obrazu i testować upgrade osobno.
- Auth z bazy SUPLA jest realizowany przez mały serwis HTTP w `mqtt/auth-service/`, a nie przez wspólne hasło brokera.
- Ten sam serwis dopuszcza też techniczne konto `MQTT_BROKER_USERNAME`, żeby `supla-server` mógł publikować i subskrybować wszystkie topiki.
