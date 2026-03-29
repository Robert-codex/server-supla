import os
import re
from contextlib import closing

import pymysql
from flask import Flask, jsonify, request


app = Flask(__name__)

SUIPLA_PREFIX_RE = re.compile(r"^supla/(?P<suid>[^/]+)/")
HOMEASSISTANT_PREFIX_RE = re.compile(
    r"^homeassistant/[^/]+/(?P<suid>[^/]+)(?:/.*)?$"
)


def json_response(ok: bool, error: str = "", status_code: int = 200):
    return jsonify({"Ok": ok, "Error": error}), status_code


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
    return request.get_json(silent=True) or request.form or {}


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
                SELECT 1
                FROM supla_user su
                LEFT JOIN supla_oauth_client_authorizations soca
                  ON su.id = soca.user_id
                WHERE su.mqtt_broker_enabled = 1
                  AND su.short_unique_id = BINARY %s
                  AND (
                    su.mqtt_broker_auth_password = SHA2(%s, 512)
                    OR soca.mqtt_broker_auth_password = SHA2(%s, 512)
                  )
                LIMIT 1
                """,
                (username, password, password),
            )
            return cur.fetchone() is not None


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
    username = str(data.get("username", "")).strip()
    password = str(data.get("password", ""))

    if is_service_account(username, password):
        return json_response(True)

    if user_authenticated(username, password):
        return json_response(True)

    return json_response(False, "unauthorized", 403)


@app.post("/auth/superuser")
def auth_superuser():
    data = request_data()
    username = str(data.get("username", "")).strip()
    password = str(data.get("password", ""))

    if is_service_account(username, password):
        return json_response(True)

    return json_response(False, "superuser disabled", 403)


@app.post("/auth/acl")
def auth_acl():
    data = request_data()
    username = str(data.get("username", "")).strip()
    topic = str(data.get("topic", "")).strip()

    try:
        access = int(data.get("acc", 0))
    except (TypeError, ValueError):
        return json_response(False, "invalid access value", 400)

    if acl_allowed(username, topic, access):
        return json_response(True)

    return json_response(False, "forbidden", 403)
