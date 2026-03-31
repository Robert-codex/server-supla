# Instalacja Serwera SUPLA

Ten dokument zbiera w jednym miejscu praktyczną instrukcję instalacji prywatnego serwera SUPLA z tego repo. Zakłada host Linux z Dockerem i dwa wspierane warianty wdrożenia:

- `private-mqtt/` - prostszy, samodzielny stack z własnym brokerem MQTT,
- `supla-docker/` - fork oficjalnego stosu Docker z dodatkowymi overlayami MQTT.

## 1. Wybierz wariant

Wybierz `private-mqtt`, jeśli:

- chcesz najszybciej postawić prywatny serwer SUPLA na jednym VPS,
- chcesz od razu mieć własny broker MQTT w tym samym repo,
- zależy Ci na prostym i czytelnym compose.

Wybierz `supla-docker`, jeśli:

- chcesz zostać blisko oficjalnego stacku `SUPLA/supla-docker`,
- planujesz dłużej utrzymywać instalację i aktualizować ją podobnie jak upstream,
- chcesz korzystać z overlayów MQTT, ale nadal opierać się na standardowym układzie SUPLA Docker.

## 2. Wymagania hosta

Minimalnie przygotuj:

- Linux x86_64, najlepiej Debian 12 lub Ubuntu 24.04 LTS,
- Docker Engine 24+,
- plugin `docker compose`,
- Git,
- domenę lub publiczny adres IP,
- otwarte porty:
  - `80` i `443` dla SUPLA Cloud,
  - `2015` i `2016` dla `supla-server`,
  - `1883` lub `8883` tylko jeśli używasz własnego MQTT.

Przykład instalacji Docker na Debianie lub Ubuntu:

```bash
sudo apt update
sudo apt install -y ca-certificates curl gnupg git
sudo install -m 0755 -d /etc/apt/keyrings
curl -fsSL https://download.docker.com/linux/ubuntu/gpg | sudo gpg --dearmor -o /etc/apt/keyrings/docker.gpg
sudo chmod a+r /etc/apt/keyrings/docker.gpg
echo \
  "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] https://download.docker.com/linux/ubuntu \
  $(. /etc/os-release && echo \"$VERSION_CODENAME\") stable" | \
  sudo tee /etc/apt/sources.list.d/docker.list > /dev/null
sudo apt update
sudo apt install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
sudo usermod -aG docker "$USER"
newgrp docker
docker --version
docker compose version
```

## 3. Pobierz repo

```bash
git clone git@github.com:Robert-codex/server-supla.git
cd server-supla
```

## 4. Przygotuj DNS i certyfikaty

Przed startem zdecyduj:

- jaka domena ma obsługiwać panel SUPLA Cloud,
- czy MQTT ma działać bez TLS (`1883`) czy z TLS (`8883`),
- czy `supla-server` ma używać własnego certyfikatu na porcie `2016`.

W tym repo certyfikaty nie są trzymane w Git. Musisz dostarczyć je lokalnie.

Wariant `private-mqtt` oczekuje:

- `private-mqtt/ssl/cloud/server.crt`
- `private-mqtt/ssl/cloud/server.key`
- `private-mqtt/ssl/server/cert.crt`
- `private-mqtt/ssl/server/private.key`
- `private-mqtt/ssl/mqtt/server.crt`
- `private-mqtt/ssl/mqtt/server.key`

Wariant `supla-docker` oczekuje analogicznych plików w:

- `supla-docker/ssl/cloud/`
- `supla-docker/ssl/server/`
- `supla-docker/mqtt/ssl/mqtt/`

Jeżeli na początek chcesz tylko uruchomić stack testowo, możesz zacząć od certyfikatów self-signed i później podmienić je na właściwe.

## 5. Instalacja wariantu `private-mqtt`

### 5.1. Przygotuj konfigurację

```bash
cd private-mqtt
cp .env.example .env
```

Ustaw w `.env` co najmniej:

- `COMPOSE_PROJECT_NAME`
- `CLOUD_DOMAIN`
- `DB_PASSWORD`
- `SECRET`
- `MQTT_BROKER_USERNAME`
- `MQTT_BROKER_PASSWORD`
- `MAILPIT_SMTP_PORT`
- `MAILPIT_WEB_PORT`

Jeżeli używasz MQTT TLS, ustaw także:

- `MQTT_TLS_PORT`

### 5.2. Uruchom wybrany wariant

Bez TLS dla MQTT:

```bash
docker compose up -d
```

MQTT over TLS:

```bash
docker compose -f docker-compose.tls.yml up -d
```

MQTT TLS z logowaniem użytkowników SUPLA przez bazę:

```bash
docker compose -f docker-compose.db-auth.yml up -d --build
```

Jeżeli chcesz mieć edycję limitów konta z GUI, dołącz lokalny overlay:

```bash
docker compose \
  -f docker-compose.db-auth.yml \
  -f docker-compose.cloud-local.yml \
  --env-file .env \
  up -d --build
```

Wtedy ustaw też w `.env`:

```dotenv
ACCOUNT_LIMITS_SELF_UPDATE_ENABLED=true
```

Jeżeli instalacja ma nie korzystać z `autodiscover.supla.org`, ustaw dodatkowo:

```dotenv
SUPLA_AUTODISCOVER_URL=
```

### 5.3. Sprawdź start

```bash
docker compose ps
docker compose logs --since=5m supla-db
docker compose logs --since=5m supla-cloud
docker compose logs --since=5m supla-server
docker compose logs --since=5m mosquitto
```

Panel powinien być dostępny pod:

```text
https://TWOJA_DOMENA/
```

### 5.4. Włącz konto użytkownika

Najpierw utwórz lub potwierdź konto w SUPLA Cloud. Potem, jeśli korzystasz z MQTT, aktywuj broker dla użytkownika:

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

Jeżeli używasz wariantu DB auth, ustaw hasło MQTT przez helper:

```bash
docker compose -f docker-compose.db-auth.yml exec mqtt-auth \
  python manage_mqtt_password.py --email twoj_uzytkownik@example.com
```

## 6. Instalacja wariantu `supla-docker`

### 6.1. Wygeneruj lokalne `.env`

```bash
cd ../supla-docker
./supla.sh
```

Przy pierwszym uruchomieniu skrypt utworzy `.env` z `.env.default` i wygeneruje:

- `DB_PASSWORD`
- `SECRET`

### 6.2. Uzupełnij `.env`

Sprawdź co najmniej:

- `COMPOSE_PROJECT_NAME`
- `CLOUD_DOMAIN`
- `PORT_HTTP`
- `PORT_HTTPS`
- `DB_PASSWORD`
- `SECRET`
- `MAILER_DSN`
- `MAILER_FROM`

Jeżeli chcesz używać prywatnego MQTT, ustaw też:

- `MQTT_BROKER_ENABLED`
- `MQTT_BROKER_HOST`
- `MQTT_BROKER_PORT`
- `MQTT_BROKER_TLS`
- `MQTT_BROKER_USERNAME`
- `MQTT_BROKER_PASSWORD`
- `MQTT_BROKER_CLIENT_ID`

Jeżeli chcesz włączyć edycję limitów konta w GUI:

```dotenv
ACCOUNT_LIMITS_SELF_UPDATE_ENABLED=true
COMPOSE_FILE=docker-compose.yml:docker-compose.standalone.yml:docker-compose.mqtt-db-auth.yml:docker-compose.cloud-local.yml
```

Jeżeli nie używasz lokalnego overlay `cloud-local`, nie dopisuj `docker-compose.cloud-local.yml`.

Jeżeli chcesz wyłączyć runtime zależność od `autodiscover.supla.org`, ustaw też:

```dotenv
SUPLA_AUTODISCOVER_URL=
```

### 6.3. Uruchom stack

Podstawowy wariant:

```bash
./supla.sh start
```

Ręcznie, bez wrappera:

```bash
docker compose \
  -f docker-compose.yml \
  -f docker-compose.standalone.yml \
  --env-file .env \
  up -d
```

Wariant z MQTT DB auth i lokalnym cloud overlay:

```bash
docker compose \
  -f docker-compose.yml \
  -f docker-compose.standalone.yml \
  -f docker-compose.mqtt-db-auth.yml \
  -f docker-compose.cloud-local.yml \
  --env-file .env \
  up -d --build
```

### 6.4. Sprawdź start

```bash
docker compose ps
docker compose logs --since=5m supla-cloud
docker compose logs --since=5m supla-server
docker compose logs --since=5m supla-db
docker compose logs --since=5m mosquitto
```

### 6.5. Utwórz potwierdzone konto z CLI

```bash
./supla.sh create-confirmed-user
```

Jeżeli chcesz wywołać konsolę Symfony ręcznie:

```bash
docker compose exec -u www-data supla-cloud php bin/console
```

## 7. Weryfikacja działania po instalacji

Po starcie sprawdź:

- czy `https://TWOJA_DOMENA/` otwiera panel SUPLA Cloud,
- czy kontenery `supla-cloud`, `supla-server` i `supla-db` są w stanie `Up`,
- czy `supla-server` nasłuchuje na `2015` i `2016`,
- czy w logach nie ma restart loop lub błędów połączenia z bazą,
- czy użytkownik może zalogować się do UI.

Jeżeli używasz własnego MQTT, sprawdź dodatkowo:

- czy broker nasłuchuje na `1883` lub `8883`,
- czy `supla-server` łączy się do brokera bez błędów TLS,
- czy użytkownik z aktywnym `mqtt_broker_enabled` może się zalogować do MQTT.

## 8. Aktualizacja i backup

W `supla-docker`:

```bash
cd supla-docker
./supla.sh backup
./supla.sh upgrade
```

W `private-mqtt` wykonuj backup przynajmniej:

- katalogu `private-mqtt/var/`,
- lokalnych plików `.env`,
- certyfikatów z `ssl/`.

## 9. Najczęstsze problemy

- `service "supla-cloud" is not running`
  - uruchom najpierw cały stack `docker compose up -d`
- `port is already allocated`
  - port `80`, `443`, `2015`, `2016`, `1883` lub `8883` jest zajęty na hoście
- MQTT działa, ale użytkownik nie może się zalogować
  - sprawdź `mqtt_broker_enabled`, login `short_unique_id` i hasło ustawione przez `manage_mqtt_password.py`
- GUI działa, ale nie da się edytować limitów
  - sprawdź, czy dołączony jest `docker-compose.cloud-local.yml` i czy `ACCOUNT_LIMITS_SELF_UPDATE_ENABLED=true`

## 10. Co czytać dalej

- `private-mqtt/README.md`
- `supla-docker/README.md`
- `supla-docker/docs/installation-from-github.md`
- `supla-docker/docs/mqtt-private-broker.md`
- `cloud-local/README.md`
