from __future__ import annotations

import os
import sqlite3
import secrets
from dataclasses import dataclass
from datetime import datetime, date
from pathlib import Path
from typing import Any, Iterable


def _utc_now_iso() -> str:
    # ISO string, UTC (no tz suffix to keep it simple for SQLite)
    return datetime.utcnow().replace(microsecond=0).isoformat(sep=" ")


def _default_user_db_path() -> str:
    """
    Return a stable per-user SQLite path.
    This prevents data from appearing "deleted" when the app folder is moved/copied.
    """
    # Windows
    base = (os.getenv("LOCALAPPDATA") or os.getenv("APPDATA") or "").strip()
    if base:
        return str(Path(base) / "drnekin-qr" / "app.db")

    # Linux / macOS
    xdg = (os.getenv("XDG_DATA_HOME") or "").strip()
    if xdg:
        return str(Path(xdg) / "drnekin-qr" / "app.db")
    return str(Path.home() / ".local" / "share" / "drnekin-qr" / "app.db")


def _db_path(cfg: dict | None = None) -> str:
    # Priority:
    # 1) env QR_DB_PATH (for Render persistent disk: /var/data/app.db)
    # 2) config field db_path (optional)
    # 3) if app-local db exists, keep using it (back-compat)
    # 4) otherwise use stable per-user db path
    env = (os.getenv("QR_DB_PATH") or "").strip()
    if env:
        return env
    if cfg is not None:
        p = str(cfg.get("db_path") or "").strip()
        if p:
            return p
    here = Path(__file__).resolve().parent
    local = here / "app.db"
    if local.exists():
        return str(local)
    return _default_user_db_path()


def connect(cfg: dict | None = None) -> sqlite3.Connection:
    path = _db_path(cfg)
    parent = os.path.dirname(path)
    if parent:
        os.makedirs(parent, exist_ok=True)
    con = sqlite3.connect(path)
    con.row_factory = sqlite3.Row
    con.execute("PRAGMA foreign_keys = ON;")
    return con


def init_db(cfg: dict | None = None) -> None:
    with connect(cfg) as con:
        con.executescript(
            """
            CREATE TABLE IF NOT EXISTS customers (
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              public_id TEXT NOT NULL UNIQUE,
              secret TEXT NOT NULL,
              name TEXT NOT NULL DEFAULT '',
              phone TEXT NOT NULL DEFAULT '',
              plate TEXT NOT NULL DEFAULT '',
              created_at TEXT NOT NULL,
              updated_at TEXT NOT NULL
            );

            CREATE INDEX IF NOT EXISTS idx_customers_plate ON customers(plate);
            CREATE INDEX IF NOT EXISTS idx_customers_phone ON customers(phone);

            CREATE TABLE IF NOT EXISTS visits (
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              customer_id INTEGER NOT NULL,
              visit_date TEXT NOT NULL,
              km TEXT NOT NULL DEFAULT '',
              notes TEXT NOT NULL DEFAULT '',
              created_at TEXT NOT NULL,
              FOREIGN KEY(customer_id) REFERENCES customers(id) ON DELETE CASCADE
            );

            CREATE INDEX IF NOT EXISTS idx_visits_customer_id ON visits(customer_id);
            CREATE INDEX IF NOT EXISTS idx_visits_visit_date ON visits(visit_date);

            CREATE TABLE IF NOT EXISTS operations (
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              visit_id INTEGER NOT NULL,
              text TEXT NOT NULL,
              price TEXT NOT NULL DEFAULT '',
              created_at TEXT NOT NULL,
              FOREIGN KEY(visit_id) REFERENCES visits(id) ON DELETE CASCADE
            );

            CREATE INDEX IF NOT EXISTS idx_operations_visit_id ON operations(visit_id);
            """
        )


def _new_public_id() -> str:
    # Short, URL-safe identifier.
    # token_urlsafe(6) ~ 8 chars; good enough to avoid collisions with UNIQUE constraint retry.
    return secrets.token_urlsafe(6).rstrip("=")


def _new_secret() -> str:
    # Some QR readers / sharing apps may drop trailing '=' padding in URLs.
    # To avoid accidental token mismatch, store secrets without '=' padding.
    return secrets.token_urlsafe(24).rstrip("=")


@dataclass(frozen=True)
class Customer:
    id: int
    public_id: str
    secret: str
    name: str
    phone: str
    plate: str
    created_at: str
    updated_at: str


@dataclass(frozen=True)
class Visit:
    id: int
    customer_id: int
    visit_date: str
    km: str
    notes: str
    created_at: str


@dataclass(frozen=True)
class Operation:
    id: int
    visit_id: int
    text: str
    price: str
    created_at: str


def create_customer(cfg: dict, *, name: str, phone: str, plate: str) -> Customer:
    init_db(cfg)
    now = _utc_now_iso()
    with connect(cfg) as con:
        # Retry in the extremely unlikely case of public_id collision.
        for _ in range(5):
            public_id = _new_public_id()
            secret = _new_secret()
            try:
                con.execute(
                    """
                    INSERT INTO customers(public_id, secret, name, phone, plate, created_at, updated_at)
                    VALUES(?, ?, ?, ?, ?, ?, ?)
                    """,
                    (public_id, secret, name.strip(), phone.strip(), plate.strip(), now, now),
                )
                row = con.execute("SELECT * FROM customers WHERE public_id = ?", (public_id,)).fetchone()
                assert row is not None
                return _row_to_customer(row)
            except sqlite3.IntegrityError:
                continue
        raise RuntimeError("Müşteri public_id üretilemedi (çakışma). Tekrar deneyin.")


def list_customers(cfg: dict, *, q: str = "") -> list[Customer]:
    init_db(cfg)
    q = (q or "").strip()
    with connect(cfg) as con:
        if q:
            like = f"%{q}%"
            rows = con.execute(
                """
                SELECT * FROM customers
                WHERE plate LIKE ? OR phone LIKE ? OR name LIKE ?
                ORDER BY updated_at DESC
                LIMIT 200
                """,
                (like, like, like),
            ).fetchall()
        else:
            rows = con.execute(
                "SELECT * FROM customers ORDER BY updated_at DESC LIMIT 200"
            ).fetchall()
    return [_row_to_customer(r) for r in rows]


def get_customer_by_public_id(cfg: dict, public_id: str) -> Customer | None:
    init_db(cfg)
    public_id = (public_id or "").strip()
    if not public_id:
        return None
    with connect(cfg) as con:
        row = con.execute("SELECT * FROM customers WHERE public_id = ?", (public_id,)).fetchone()
    return _row_to_customer(row) if row else None


def delete_customer_by_public_id(cfg: dict, public_id: str) -> bool:
    """
    Deletes customer and cascades visits/operations.
    Returns True if a row was deleted.
    """
    init_db(cfg)
    public_id = (public_id or "").strip()
    if not public_id:
        return False
    with connect(cfg) as con:
        cur = con.execute("DELETE FROM customers WHERE public_id = ?", (public_id,))
        return bool(cur.rowcount and cur.rowcount > 0)


def touch_customer_updated_at(cfg: dict, customer_id: int) -> None:
    now = _utc_now_iso()
    with connect(cfg) as con:
        con.execute("UPDATE customers SET updated_at = ? WHERE id = ?", (now, customer_id))


def create_visit(
    cfg: dict,
    *,
    customer_id: int,
    visit_date: str,
    km: str,
    notes: str,
    operations: Iterable[tuple[str, str]] = (),
) -> int:
    init_db(cfg)
    now = _utc_now_iso()
    visit_date = (visit_date or "").strip() or str(date.today())
    km = (km or "").strip()
    notes = (notes or "").strip()

    with connect(cfg) as con:
        cur = con.execute(
            """
            INSERT INTO visits(customer_id, visit_date, km, notes, created_at)
            VALUES(?, ?, ?, ?, ?)
            """,
            (customer_id, visit_date, km, notes, now),
        )
        visit_id = int(cur.lastrowid)

        for text, price in operations:
            t = (text or "").strip()
            p = (price or "").strip()
            if not t:
                continue
            con.execute(
                """
                INSERT INTO operations(visit_id, text, price, created_at)
                VALUES(?, ?, ?, ?)
                """,
                (visit_id, t, p, now),
            )

    touch_customer_updated_at(cfg, customer_id)
    return visit_id


def list_visits_for_customer(cfg: dict, customer_id: int) -> list[Visit]:
    init_db(cfg)
    with connect(cfg) as con:
        rows = con.execute(
            """
            SELECT * FROM visits
            WHERE customer_id = ?
            ORDER BY visit_date DESC, id DESC
            """,
            (customer_id,),
        ).fetchall()
    return [_row_to_visit(r) for r in rows]


def list_operations_for_visit(cfg: dict, visit_id: int) -> list[Operation]:
    init_db(cfg)
    with connect(cfg) as con:
        rows = con.execute(
            """
            SELECT * FROM operations
            WHERE visit_id = ?
            ORDER BY id ASC
            """,
            (visit_id,),
        ).fetchall()
    return [_row_to_operation(r) for r in rows]


def _row_to_customer(row: sqlite3.Row) -> Customer:
    return Customer(
        id=int(row["id"]),
        public_id=str(row["public_id"]),
        secret=str(row["secret"]),
        name=str(row["name"] or ""),
        phone=str(row["phone"] or ""),
        plate=str(row["plate"] or ""),
        created_at=str(row["created_at"]),
        updated_at=str(row["updated_at"]),
    )


def _row_to_visit(row: sqlite3.Row) -> Visit:
    return Visit(
        id=int(row["id"]),
        customer_id=int(row["customer_id"]),
        visit_date=str(row["visit_date"]),
        km=str(row["km"] or ""),
        notes=str(row["notes"] or ""),
        created_at=str(row["created_at"]),
    )


def _row_to_operation(row: sqlite3.Row) -> Operation:
    return Operation(
        id=int(row["id"]),
        visit_id=int(row["visit_id"]),
        text=str(row["text"] or ""),
        price=str(row["price"] or ""),
        created_at=str(row["created_at"]),
    )

