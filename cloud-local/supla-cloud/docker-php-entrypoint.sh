#!/bin/sh
set -e

apache_server_name="${APACHE_SERVER_NAME:-${CLOUD_DOMAIN:-localhost}}"
db_host="${DB_HOST:-supla-db}"
db_port="${DB_PORT:-3306}"
db_name="${DB_NAME:-supla}"
db_user="${DB_USER:-supla}"
db_password="${DB_PASSWORD:-DEFAULT_PASSWORD_IS_BAD_IDEA}"

generate_local_certificate() {
  rm -f /etc/apache2/ssl/server.crt /etc/apache2/ssl/server.key
  openssl req \
    -x509 \
    -nodes \
    -days 365 \
    -newkey rsa:2048 \
    -keyout /etc/apache2/ssl/server.key \
    -out /etc/apache2/ssl/server.crt \
    -subj "/C=PL/ST=SUPLA/L=SUPLA/O=SUPLA/CN=${apache_server_name}" \
    -addext "basicConstraints=critical,CA:FALSE" \
    -addext "keyUsage=critical,digitalSignature,keyEncipherment" \
    -addext "extendedKeyUsage=serverAuth" \
    -addext "subjectAltName=DNS:${apache_server_name},DNS:localhost,IP:127.0.0.1" \
    >/dev/null 2>&1
}

if [ ! -f /etc/apache2/ssl/server.crt ] || ! openssl x509 -in /etc/apache2/ssl/server.crt -noout -text | grep -q "CA:FALSE"; then
  generate_local_certificate
fi

cat > /etc/apache2/conf-available/servername.conf <<EOF
ServerName ${apache_server_name}
EOF
a2enconf servername >/dev/null

if ! grep -q "^user *= *root$" /etc/supervisor/conf.d/supervisord.conf; then
  sed -i '/^\[supervisord\]$/a user = root' /etc/supervisor/conf.d/supervisord.conf
fi

wait_for_database() {
  php -r '
    $dsn = sprintf("mysql:host=%s;port=%s;dbname=%s", getenv("WAIT_DB_HOST"), getenv("WAIT_DB_PORT"), getenv("WAIT_DB_NAME"));
    try {
        new PDO($dsn, getenv("WAIT_DB_USER"), getenv("WAIT_DB_PASSWORD"), [PDO::ATTR_TIMEOUT => 3]);
        exit(0);
    } catch (Throwable $e) {
        fwrite(STDERR, $e->getMessage() . PHP_EOL);
        exit(1);
    }
  '
}

if [ "${MAILER_HOST}" != "" ]; then
  echo "[WARN] You are using deprecated e-mail configuration. Please use MAILER_DSN environment variable to configure it."
fi

echo "
parameters:
  database_driver: pdo_mysql
  database_host: ${DB_HOST:-supla-db}
  database_port: ${DB_PORT:-null}
  database_name: ${DB_NAME:-supla}
  database_user: ${DB_USER:-supla}
  database_password: ${DB_PASSWORD:-DEFAULT_PASSWORD_IS_BAD_IDEA}
  mailer_dsn: ${MAILER_DSN:-null://null}
  mailer_from: ${MAILER_FROM:-~}
  admin_email: ${ADMIN_EMAIL:-~}
  supla_server: ${CLOUD_DOMAIN:-cloud.supla.org}
  supla_require_regulations_acceptance: ${REQUIRE_REGULATIONS_ACCEPTANCE:-false}
  supla_require_cookie_policy_acceptance: ${REQUIRE_COOKIE_POLICY_ACCEPTANCE:-false}
  brute_force_auth_prevention_enabled: ${BRUTE_FORCE_AUTH_PREVENTION_ENABLED:-true}
  recaptcha_enabled: ${RECAPTCHA_ENABLED:-false}
  recaptcha_site_key: ${RECAPTCHA_PUBLIC_KEY:-~}
  recaptcha_secret: ${RECAPTCHA_PRIVATE_KEY:-~}
  locale: en
  secret: ${SECRET:-DEFAULT_SECRET_IS_BAD_IDEA}
  cors_allow_origin_regex:
    - supla2.+
    - localhost.+
" > app/config/parameters.yml

sed -E -i "s@supla_url: '(.+)'@supla_url: '${SUPLA_URL:-\1}'@g" app/config/config.yml

echo "
supla:
  autodiscover_url: '${SUPLA_AUTODISCOVER_URL-https://autodiscover.supla.org}'
  accounts_registration_enabled: ${ACCOUNTS_REGISTRATION_ENABLED:-true}
  account_limits:
    self_update_enabled: ${ACCOUNT_LIMITS_SELF_UPDATE_ENABLED:-false}
  measurement_logs_retention:
    em_voltage_aberrations: ${MEASUREMENT_LOGS_RETENTION_EM_VOLTAGE_ABERRATIONS:-1000}
    em_voltage: ${MEASUREMENT_LOGS_RETENTION_EM_VOLTAGE:-1000}
    em_current: ${MEASUREMENT_LOGS_RETENTION_EM_CURRENT:-1000}
    em_power_active: ${MEASUREMENT_LOGS_RETENTION_EM_POWER_ACTIVE:-1000}
  mqtt_broker:
    enabled: ${MQTT_BROKER_ENABLED:-false}
    host: ${MQTT_BROKER_HOST:-~}
    integrated_auth: ${MQTT_BROKER_INTEGRATED_AUTH:-false}
    protocol: ${MQTT_BROKER_PROTOCOL:-mqtt}
    port: ${MQTT_BROKER_PORT:-8883}
    tls: ${MQTT_BROKER_TLS:-true}
    username: '${MQTT_BROKER_USERNAME:-}'
    password: '${MQTT_BROKER_PASSWORD:-}'
parameters:
  supla_protocol: ${SUPLA_PROTOCOL:-https}
" > app/config/config_docker.yml

if [ -f var/local/config_local.yml ]; then
  cp var/local/config_local.yml app/config/config_local.yml
  chown www-data:www-data app/config/config_local.yml
fi

if [ ${SUPLA_PROTOCOL:-https} = "https" ]; then
  if ! grep -q "%{HTTPS} off" web/.htaccess; then
    {
      echo 'RewriteCond %{HTTPS} off'
      echo 'RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]'
      cat web/.htaccess
    } > web/.htaccess-tmp
    rm web/.htaccess
    mv web/.htaccess-tmp web/.htaccess
  fi
fi

export WAIT_DB_HOST="${db_host}"
export WAIT_DB_PORT="${db_port}"
export WAIT_DB_NAME="${db_name}"
export WAIT_DB_USER="${db_user}"
export WAIT_DB_PASSWORD="${db_password}"
for i in $(seq 1 30); do
  if wait_for_database >/tmp/supla-db-wait.log 2>&1; then
    break
  fi
  if [ "$i" -eq 30 ]; then
    cat /tmp/supla-db-wait.log >&2 || true
    echo "[ERROR] Database did not become ready in time." >&2
    exit 1
  fi
  sleep 2
done

rm -fr var/cache/*
php bin/console supla:initialize
php bin/console cache:warmup
chown -hR www-data:www-data var
php bin/console supla:create-confirmed-user $FIRST_USER_EMAIL $FIRST_USER_PASSWORD --no-interaction --if-not-exists

if [ "${1#-}" != "$1" ]; then
  set -- apache2-foreground "$@"
fi

exec "$@"
