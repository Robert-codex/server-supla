# Install SUPLA Docker From GitHub

This guide describes a clean installation of SUPLA from the official GitHub repository on a Linux host.

## 1. Prepare the host

Install:

- Docker Engine 24+
- `docker compose` plugin
- Git

You also need these TCP ports available if you run in standalone mode:

- `80` for HTTP
- `443` for HTTPS
- `2015` for SUPLA TCP
- `2016` for SUPLA TLS

## 2. Clone the repository

```bash
git clone https://github.com/SUPLA/supla-docker.git
cd supla-docker
```

## 3. Generate the local configuration

Run:

```bash
./supla.sh
```

On the first run the script creates a local `.env` file from `.env.default` and generates random values for:

- `DB_PASSWORD`
- `SECRET`

These values stay only in your local `.env` file. They are not part of the repository and should not be committed.

## 4. Review `.env`

Open `.env` and check at least:

- `CLOUD_DOMAIN`
- `PORT_HTTP`
- `PORT_HTTPS`
- `DB_PASSWORD`
- `SECRET`
- `MAILER_DSN`
- `MAILER_FROM`

If you want to use a private MQTT broker later, also configure:

- `MQTT_BROKER_ENABLED`
- `MQTT_BROKER_HOST`
- `MQTT_BROKER_PORT`
- `MQTT_BROKER_TLS`
- `MQTT_BROKER_USERNAME`
- `MQTT_BROKER_PASSWORD`
- `MQTT_BROKER_CLIENT_ID`

The MQTT overlay setup is documented in [mqtt-private-broker.md](mqtt-private-broker.md).

## 5. Start the stack

Run:

```bash
./supla.sh start
```

This starts:

- `supla-cloud`
- `supla-db`
- `supla-server`

## 6. Verify that the containers are running

Run:

```bash
docker compose ps
```

If something does not start correctly, check:

```bash
docker logs --since=5m supla-cloud
docker logs --since=5m supla-server
docker logs --since=5m supla-db
```

## 7. Open SUPLA in the browser

Open:

```text
https://YOUR_DOMAIN_OR_IP/
```

After the first boot you can create the initial account in the web UI.

## 8. Create a confirmed user from CLI if needed

You can also create a user account from the command line:

```bash
./supla.sh create-confirmed-user
```

## 9. Optional: run behind a reverse proxy

If ports `80` or `443` are already in use, or if you want to terminate SSL in another proxy, use proxy mode as described in [README.md](../README.md#launching-in-proxy-mode).

## 10. Upgrade later

To update the stack:

```bash
git pull
./supla.sh upgrade
```
