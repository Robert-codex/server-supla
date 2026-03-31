import base64
import hashlib
import hmac
import json
import os
import re
import secrets
from contextlib import closing

import pymysql
from flask import Flask, jsonify, request


app = Flask(__name__)

HOMEASSISTANT_PREFIX_RE = re.compile(
    r"^homeassistant/[^/]+/(?P<suid>[^/]+)(?:/.*)?$"
)
PBKDF2_PREFIX = "PBKDF2"
PBKDF2_ALGORITHM = "sha256"
PBKDF2_ITERATIONS = 100000
PBKDF2_KEY_LENGTH = 32
PBKDF2_SALT_SIZE = 16


def json_response(ok: bool, error: str = "", status_code: int = 200):
    return jsonify({"ok": ok, "error": error}), status_code


def db_connect():
    return pymysql.connect(
        host=os.environ.get("SUPLA_DB_HOST", "supla-db"),
        port=int(os.environ.get("SUPLA_DB_PORT", "3306")),
        user=os.environ.get("SUPLA_DB_USER", "supla"),
        password=os.environ["SUPLA_DB_PASSWORD"],
        database=os.environ.get("SUPLA_DB_NAME", "supla"),
        charset="utf8mb4",
        cursorclass=pymysql.cursors.DictCursor,
        autocommit=True,
    )


def request_data():
    data = request.get_json(silent=True)
    if data:
        return data

    if request.form:
        return request.form

    raw_body = request.get_data(as_text=True).strip()
    if raw_body:
        try:
            parsed = json.loads(raw_body)
        except json.JSONDecodeError:
            parsed = None
        if isinstance(parsed, dict):
            return parsed

    return {}


def request_value(data, *keys) -> str:
    for key in keys:
        value = data.get(key)
        if value is not None:
            return str(value)
    return ""

def hash_password(password: str) -> str:
    salt = secrets.token_bytes(PBKDF2_SALT_SIZE)
    derived_key = hashlib.pbkdf2_hmac(
        PBKDF2_ALGORITHM,
        password.encode("utf-8"),
        salt,
        PBKDF2_ITERATIONS,
        dklen=PBKDF2_KEY_LENGTH,
    )
    salt_b64 = base64.b64encode(salt).decode("ascii")
    hash_b64 = base64.b64encode(derived_key).decode("ascii")
    return (
        f"{PBKDF2_PREFIX}${PBKDF2_ALGORITHM}${PBKDF2_ITERATIONS}$"
        f"{salt_b64}${hash_b64}"
    )


def verify_pbkdf2_password(password: str, stored_hash: str) -> bool:
    parts = stored_hash.split("$")
    if len(parts) != 5 or parts[0] != PBKDF2_PREFIX:
        return False

    _, algorithm, iterations_raw, salt_b64, expected_b64 = parts

    try:
        iterations = int(iterations_raw)
        salt = base64.b64decode(salt_b64)
        expected = base64.b64decode(expected_b64)
    except (ValueError, TypeError):
        return False

    try:
        calculated = hashlib.pbkdf2_hmac(
            algorithm,
            password.encode("utf-8"),
            salt,
            iterations,
            dklen=len(expected),
        )
    except ValueError:
        return False

    return hmac.compare_digest(calculated, expected)


def verify_password(password: str, stored_hash: str | None) -> bool:
    if not stored_hash:
        return False

    if stored_hash.startswith(f"{PBKDF2_PREFIX}$"):
        return verify_pbkdf2_password(password, stored_hash)

    legacy_hash = hashlib.sha512(password.encode("utf-8")).hexdigest()
    return hmac.compare_digest(legacy_hash, stored_hash.lower())


def service_account() -> tuple[str, str]:
    return (
        os.environ.get("MQTT_BROKER_USERNAME", "").strip(),
        os.environ.get("MQTT_BROKER_PASSWORD", ""),
    )


def is_service_account(username: str, password: str | None = None) -> bool:
    service_username, service_password = service_account()
    if not service_username or username != service_username:
        return False

    return password is None or password == service_password


def user_enabled(username: str) -> bool:
    if not username:
        return False

    with closing(db_connect()) as conn:
        with conn.cursor() as cur:
            cur.execute(
                """
                SELECT 1
                FROM supla_user
                WHERE short_unique_id = BINARY %s
                  AND mqtt_broker_enabled = 1
                LIMIT 1
                """,
                (username,),
            )
            return cur.fetchone() is not None


def user_authenticated(username: str, password: str) -> bool:
    if not username or not password:
        return False

    with closing(db_connect()) as conn:
        with conn.cursor() as cur:
            cur.execute(
                """
                SELECT su.mqtt_broker_auth_password AS auth_password
                FROM supla_user su
                WHERE su.mqtt_broker_enabled = 1
                  AND su.short_unique_id = BINARY %s
                  AND su.mqtt_broker_auth_password IS NOT NULL
                UNION ALL
                SELECT soca.mqtt_broker_auth_password AS auth_password
                FROM supla_oauth_client_authorizations soca
                JOIN supla_user su ON su.id = soca.user_id
                WHERE su.mqtt_broker_enabled = 1
                  AND su.short_unique_id = BINARY %s
                  AND soca.mqtt_broker_auth_password IS NOT NULL
                """,
                (username, username),
            )
            return any(
                verify_password(password, row["auth_password"])
                for row in cur.fetchall()
            )


def topic_matches_user(topic: str, username: str) -> bool:
    if topic.startswith(f"supla/{username}/"):
        return True

    match = HOMEASSISTANT_PREFIX_RE.match(topic)
    return match is not None and match.group("suid") == username


def write_allowed(topic: str, username: str) -> bool:
    escaped = re.escape(username)
    patterns = [
        rf"^supla/{escaped}/refresh_request$",
        rf"^supla/{escaped}/devices/\d+/channels/\d+/set/[^/]+$",
        rf"^supla/{escaped}/devices/\d+/channels/\d+/execute_action$",
    ]
    return any(re.match(pattern, topic) for pattern in patterns)


def acl_allowed(username: str, topic: str, access: int) -> bool:
    if is_service_account(username):
        return True

    if not user_enabled(username):
        return False

    if access == 1:
        return topic_matches_user(topic, username)

    if access == 2:
        return write_allowed(topic, username)

    if access == 3:
        return topic_matches_user(topic, username) and write_allowed(topic, username)

    if access == 4:
        return topic_matches_user(topic, username)

    return False


@app.get("/health")
def health():
    return json_response(True)


@app.post("/auth/user")
def auth_user():
    data = request_data()
    username = request_value(data, "username", "user", "token").strip()
    password = request_value(data, "password", "pass", "pwd")

    if is_service_account(username, password):
        return json_response(True)

    if user_authenticated(username, password):
        return json_response(True)

    return json_response(False, "unauthorized", 403)


@app.post("/auth/superuser")
def auth_superuser():
    data = request_data()
    username = request_value(data, "username", "user", "token").strip()
    password = request_value(data, "password", "pass", "pwd")

    if is_service_account(username, password):
        return json_response(True)

    return json_response(False, "superuser disabled", 403)


@app.post("/auth/acl")
def auth_acl():
    data = request_data()
    username = request_value(data, "username", "user", "token").strip()
    topic = request_value(data, "topic").strip()

    try:
        access = int(request_value(data, "acc", "access", "acl"))
    except (TypeError, ValueError):
        return json_response(False, "invalid access value", 400)

    if acl_allowed(username, topic, access):
        return json_response(True)

    return json_response(False, "forbidden", 403)
