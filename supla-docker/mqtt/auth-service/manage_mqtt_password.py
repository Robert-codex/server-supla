import argparse
import secrets
import string
from contextlib import closing

import pymysql

from app import db_connect, hash_password


PASSWORD_ALPHABET = string.ascii_letters + string.digits


def parse_args():
    parser = argparse.ArgumentParser(
        description="Enable MQTT for a SUPLA user and set or generate a broker password."
    )
    target = parser.add_mutually_exclusive_group(required=True)
    target.add_argument("--email", help="SUPLA user email address")
    target.add_argument("--suid", help="SUPLA short_unique_id")
    parser.add_argument(
        "--password",
        help="Explicit MQTT password to store instead of generating one",
    )
    parser.add_argument(
        "--length",
        type=int,
        default=32,
        help="Generated password length when --password is not provided",
    )
    return parser.parse_args()


def generate_password(length: int) -> str:
    if length < 16:
        raise ValueError("Password length must be at least 16 characters")

    return "".join(secrets.choice(PASSWORD_ALPHABET) for _ in range(length))


def find_user(cursor, email: str | None, suid: str | None):
    if email:
        cursor.execute(
            """
            SELECT id, email, short_unique_id
            FROM supla_user
            WHERE email = %s
            LIMIT 1
            """,
            (email,),
        )
    else:
        cursor.execute(
            """
            SELECT id, email, short_unique_id
            FROM supla_user
            WHERE short_unique_id = BINARY %s
            LIMIT 1
            """,
            (suid,),
        )

    return cursor.fetchone()


def update_password(user_id: int, password: str):
    password_hash = hash_password(password)
    with closing(db_connect()) as conn:
        with conn.cursor() as cur:
            cur.execute(
                """
                UPDATE supla_user
                SET mqtt_broker_enabled = 1,
                    mqtt_broker_auth_password = %s
                WHERE id = %s
                """,
                (password_hash, user_id),
            )
            return cur.rowcount


def main():
    args = parse_args()
    password = args.password or generate_password(args.length)

    with closing(db_connect()) as conn:
        with conn.cursor() as cur:
            user = find_user(cur, args.email, args.suid)

    if user is None:
        target = args.email or args.suid
        raise SystemExit(f"User not found: {target}")

    updated = update_password(user["id"], password)
    if updated != 1:
        raise SystemExit("MQTT password update failed")

    print("MQTT password updated")
    print(f"email={user['email']}")
    print(f"short_unique_id={user['short_unique_id']}")
    print(f"mqtt_broker_enabled=1")
    print(f"mqtt_password={password}")


if __name__ == "__main__":
    try:
        main()
    except pymysql.MySQLError as exc:
        raise SystemExit(f"Database error: {exc}") from exc
