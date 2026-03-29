#!/bin/sh
set -eu

escape_sed() {
  printf '%s' "$1" | sed 's/[|&\\]/\\&/g'
}

require_env() {
  var_name="$1"
  eval "value=\${$var_name:-}"
  if [ -z "$value" ]; then
    echo "Missing required environment variable: $var_name" >&2
    exit 1
  fi
}

require_env MQTT_BROKER_USERNAME
require_env MQTT_BROKER_PASSWORD
require_env SUPLA_DB_PASSWORD

mkdir -p /mosquitto/auth
mkdir -p /mosquitto/data /mosquitto/log

password_hash="$(/mosquitto/pw -a sha256 -l 32 -p "$MQTT_BROKER_PASSWORD")"
printf '%s:%s\n' "$MQTT_BROKER_USERNAME" "$password_hash" > /mosquitto/auth/passwords
cat > /mosquitto/auth/acl <<EOF
user $MQTT_BROKER_USERNAME
topic readwrite #
EOF
chown -R mosquitto:mosquitto /mosquitto/auth /mosquitto/data /mosquitto/log
chmod 600 /mosquitto/auth/passwords /mosquitto/auth/acl

rendered_auth_conf="/tmp/go-auth.conf"
generated_mosquitto_conf="/tmp/mosquitto.conf"

sed \
  -e "s|__DB_HOST__|$(escape_sed "${SUPLA_DB_HOST:-supla-db}")|g" \
  -e "s|__DB_PORT__|$(escape_sed "${SUPLA_DB_PORT:-3306}")|g" \
  -e "s|__DB_NAME__|$(escape_sed "${SUPLA_DB_NAME:-supla}")|g" \
  -e "s|__DB_USER__|$(escape_sed "${SUPLA_DB_USER:-supla}")|g" \
  -e "s|__DB_PASSWORD__|$(escape_sed "$SUPLA_DB_PASSWORD")|g" \
  /usr/local/share/go-auth.conf.tpl > "$rendered_auth_conf"

cat /etc/mosquitto/mosquitto.conf "$rendered_auth_conf" > "$generated_mosquitto_conf"

exec mosquitto -c "$generated_mosquitto_conf"
