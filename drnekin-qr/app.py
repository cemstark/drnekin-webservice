import io
import os
from pathlib import Path
import secrets
import json
import urllib.parse
import urllib.request

try:
    import qrcode
except ModuleNotFoundError:  # pragma: no cover
    qrcode = None  # type: ignore[assignment]
from flask import Flask, Response, redirect, render_template, request, url_for
from werkzeug.middleware.proxy_fix import ProxyFix

from config_store import load_config, save_config
from db import init_db
from db import (
    create_customer,
    create_visit,
    delete_customer_by_public_id,
    get_customer_by_public_id,
    list_customers,
    list_operations_for_visit,
    list_visits_for_customer,
)


RUN_ID = secrets.token_urlsafe(8)
_SAVED_ONCE = False
_LAST_SAVED_PATH: str | None = None
_LAST_SAVE_ERROR: str | None = None

app = Flask(__name__)
app.wsgi_app = ProxyFix(app.wsgi_app, x_for=1, x_proto=1, x_host=1, x_port=1, x_prefix=1)


@app.before_request
def _ensure_db():
    # Very cheap after first run; makes sure tables exist for every deployment.
    cfg = load_config()
    init_db(cfg)

def _app_mode(cfg: dict) -> str:
    return (os.getenv("APP_MODE") or cfg.get("app_mode") or "full").strip()


def _is_host_only(cfg: dict) -> bool:
    return _app_mode(cfg) == "host_only"


def _with_query(url: str, extra_params: dict) -> str:
    parsed = urllib.parse.urlparse(url)
    qs = dict(urllib.parse.parse_qsl(parsed.query, keep_blank_values=True))
    qs.update({k: str(v) for k, v in extra_params.items() if v is not None})
    new_query = urllib.parse.urlencode(qs, doseq=True)
    return urllib.parse.urlunparse(parsed._replace(query=new_query))


def _public_base_url() -> str:
    # request.url_root includes trailing slash
    return request.url_root.rstrip("/")


def _get_active_qr_token(cfg: dict) -> str:
    """
    Returns the locally-active QR token.
    - Persists in config.json
    - Does NOT change unless explicitly rotated (new QR generated)
    """
    token = str(cfg.get("active_qr_token") or "").strip()
    if token:
        return token
    token = secrets.token_urlsafe(18)
    cfg["active_qr_token"] = token
    save_config(cfg)
    return token


def _rotate_active_qr_token(cfg: dict) -> str:
    """
    Generates a brand new QR token (invalidates previous QR on host once synced).
    """
    token = secrets.token_urlsafe(18)
    cfg["active_qr_token"] = token
    # force re-sync to host
    cfg["last_sent_qr_token"] = ""
    save_config(cfg)
    return token


def _qr_payload_url(cfg: dict) -> str:
    # If rotation is enabled, QR should point to hosted gate endpoint (/r/<token>)
    if cfg.get("remote_rotate_enabled"):
        base = (cfg.get("public_base_url") or "").strip() or _public_base_url()
        token = _get_active_qr_token(cfg)
        return base.rstrip("/") + "/r/" + token

    mode = (cfg.get("qr_mode") or "info_page").strip()

    if mode == "target_url":
        target = (cfg.get("target_url") or "").strip()
        if not target:
            return _with_query(_public_base_url() + url_for("info"), {"rid": RUN_ID})
        if cfg.get("append_run_id_to_target_url"):
            return _with_query(target, {"rid": RUN_ID})
        return target

    # default: open this app's /info page (your "site")
    return _with_query(_public_base_url() + url_for("info"), {"rid": RUN_ID})


def _public_url_for_customer(cfg: dict, public_id: str, secret: str) -> str:
    # IMPORTANT: For customer QR codes generated on the hosted site, always prefer the
    # current request base URL. This prevents "Not Found" when an old public_base_url
    # is stored in config.json from a previous deploy/domain.
    base = _public_base_url()
    # /c/<id>?k=<secret>
    return _with_query(base.rstrip("/") + url_for("customer_public", public_id=public_id), {"k": secret})


def _guess_desktop_dir() -> Path:
    home = Path.home()
    candidates = [
        home / "OneDrive" / "Masaüstü",
        home / "OneDrive" / "Desktop",
        home / "Desktop",
        home / "Masaüstü",
    ]
    for p in candidates:
        if p.exists() and p.is_dir():
            return p
    # If we can't find a desktop folder, fall back to an app-local output directory.
    return Path.cwd() / "output"


def _qr_payload_for_saved_png(cfg: dict) -> str:
    """
    QR payload for the *saved* PNG (no request context).
    - If public_base_url is set, we use it for info_page mode.
    - Otherwise we default to local http://127.0.0.1:8000.
    """
    # If rotation is enabled, QR should point to hosted gate endpoint (/r/<token>)
    if cfg.get("remote_rotate_enabled"):
        base = (cfg.get("public_base_url") or "").strip()
        if not base:
            raise RuntimeError("remote_rotate_enabled=true ama public_base_url boş. Render host URL'nizi yazın.")
        token = _get_active_qr_token(cfg)
        return base.rstrip("/") + "/r/" + token

    mode = (cfg.get("qr_mode") or "info_page").strip()
    if mode == "target_url":
        target = (cfg.get("target_url") or "").strip()
        if not target:
            # fall back to local info page
            base = (cfg.get("public_base_url") or "").strip() or "http://127.0.0.1:8000"
            return _with_query(base.rstrip("/") + "/info", {"rid": RUN_ID})
        if cfg.get("append_run_id_to_target_url"):
            return _with_query(target, {"rid": RUN_ID})
        return target

    base = (cfg.get("public_base_url") or "").strip() or "http://127.0.0.1:8000"
    return _with_query(base.rstrip("/") + "/info", {"rid": RUN_ID})


def save_qr_png_to_desktop(cfg: dict) -> Path:
    if qrcode is None:
        raise RuntimeError(
            "QR üretimi için paket eksik: 'qrcode'. "
            "Kurulum: pip install -r requirements.txt"
        )
    desktop = _guess_desktop_dir()
    filename = (cfg.get("qr_output_filename") or "qr.png").strip() or "qr.png"
    out_path = desktop / filename
    out_path.parent.mkdir(parents=True, exist_ok=True)

    payload = _qr_payload_for_saved_png(cfg)
    qr = qrcode.QRCode(  # type: ignore[union-attr]
        version=None,
        error_correction=qrcode.constants.ERROR_CORRECT_M,  # type: ignore[union-attr]
        box_size=10,
        border=4,
    )
    qr.add_data(payload)
    qr.make(fit=True)
    img = qr.make_image(fill_color="black", back_color="white")
    img.save(out_path, format="PNG")
    return out_path


def _maybe_save_once(cfg: dict) -> None:
    global _SAVED_ONCE, _LAST_SAVED_PATH, _LAST_SAVE_ERROR
    if _SAVED_ONCE:
        return
    if not cfg.get("qr_save_to_desktop", True):
        _SAVED_ONCE = True
        _LAST_SAVED_PATH = None
        _LAST_SAVE_ERROR = None
        return
    try:
        out = save_qr_png_to_desktop(cfg)
        _SAVED_ONCE = True
        _LAST_SAVED_PATH = str(out)
        _LAST_SAVE_ERROR = None
    except Exception as e:
        _SAVED_ONCE = True
        _LAST_SAVED_PATH = None
        _LAST_SAVE_ERROR = repr(e)


@app.get("/")
def index():
    cfg = load_config()
    if _is_host_only(cfg):
        return redirect(url_for("info"))
    _maybe_save_once(cfg)
    payload = _qr_payload_url(cfg)
    return render_template(
        "index.html",
        cfg=cfg,
        payload=payload,
        run_id=RUN_ID,
        saved_path=_LAST_SAVED_PATH,
        save_error=_LAST_SAVE_ERROR,
    )


@app.get("/info")
def info():
    cfg = load_config()
    return render_template(
        "info.html",
        title=cfg.get("info_title") or "Bilgiler",
        body=cfg.get("info_body") or "",
        target_url=(cfg.get("target_url") or "").strip(),
    )


@app.get("/r/<token>")
def rotate_redirect(token: str):
    """
    Gate endpoint:
    - If token matches current_qr_token -> redirect to static_redirect_url
    - Else -> 410 Gone (old QR invalid)
    """
    cfg = load_config()
    current = (cfg.get("current_qr_token") or "").strip()
    redirect_url = (cfg.get("static_redirect_url") or "").strip()
    if not current or not redirect_url:
        return (
            "QR henüz aktif edilmedi (Not configured).\n"
            "Bu host'a ilk token'ı göndermek için bilgisayarındaki uygulamayı 1 kez çalıştırıp\n"
            "remote_rotate_enabled=true iken /api/rotate çağrısını yaptırmalısın.\n",
            410,
            {"Content-Type": "text/plain; charset=utf-8"},
        )
    if token != current:
        return ("QR artık geçersiz (yeni QR üretildi).", 410)
    return redirect(redirect_url, code=302)


@app.get("/status")
def status():
    """
    Small, non-sensitive health/config status endpoint.
    Does NOT expose tokens.
    """
    cfg = load_config()
    return {
        "ok": True,
        "app_mode": _app_mode(cfg),
        "has_current_qr_token": bool((cfg.get("current_qr_token") or "").strip()),
        "has_static_redirect_url": bool((cfg.get("static_redirect_url") or "").strip()),
        "remote_rotate_enabled": bool(cfg.get("remote_rotate_enabled")),
    }


@app.get("/qr.png")
def qr_png():
    cfg = load_config()
    if _is_host_only(cfg):
        return ("Not Found", 404)
    if qrcode is None:
        return (
            "QR üretimi için 'qrcode' paketi kurulu değil.\n"
            "Kurulum:\n"
            "  pip install -r requirements.txt\n",
            500,
            {"Content-Type": "text/plain; charset=utf-8"},
        )
    payload = _qr_payload_url(cfg)

    qr = qrcode.QRCode(  # type: ignore[union-attr]
        version=None,
        error_correction=qrcode.constants.ERROR_CORRECT_M,  # type: ignore[union-attr]
        box_size=10,
        border=4,
    )
    qr.add_data(payload)
    qr.make(fit=True)
    img = qr.make_image(fill_color="black", back_color="white")

    buf = io.BytesIO()
    img.save(buf, format="PNG")
    buf.seek(0)
    return Response(buf.getvalue(), mimetype="image/png")


def _require_admin(cfg: dict) -> bool:
    token = (request.args.get("token") or "").strip()
    return bool(token) and token == (cfg.get("admin_token") or "")


def _require_bearer(cfg: dict) -> bool:
    auth = (request.headers.get("Authorization") or "").strip()
    if not auth.lower().startswith("bearer "):
        return False
    token = auth.split(" ", 1)[1].strip()
    return bool(token) and token == (cfg.get("admin_token") or "")


@app.post("/api/config")
def api_config_update():
    """
    Update visible info on the hosted instance.
    Auth: Authorization: Bearer <ADMIN_TOKEN>
    Body: JSON with allowed fields (info_title, info_body, qr_mode, target_url, append_run_id_to_target_url)
    """
    cfg = load_config()
    if not _require_bearer(cfg):
        return ({"ok": False, "error": "unauthorized"}, 401)

    data = request.get_json(silent=True) or {}
    if not isinstance(data, dict):
        return ({"ok": False, "error": "invalid_json"}, 400)

    allowed = {
        "info_title",
        "info_body",
        "qr_mode",
        "target_url",
        "append_run_id_to_target_url",
    }
    for k in list(data.keys()):
        if k not in allowed:
            data.pop(k, None)

    if "info_title" in data:
        cfg["info_title"] = str(data["info_title"])
    if "info_body" in data:
        cfg["info_body"] = str(data["info_body"])
    if "qr_mode" in data:
        cfg["qr_mode"] = str(data["qr_mode"])
    if "target_url" in data:
        cfg["target_url"] = str(data["target_url"]).strip()
    if "append_run_id_to_target_url" in data:
        cfg["append_run_id_to_target_url"] = bool(data["append_run_id_to_target_url"])

    save_config(cfg)
    return {"ok": True}


@app.post("/api/rotate")
def api_rotate_update():
    """
    Update current QR token + redirect URL on hosted instance.
    Auth: Authorization: Bearer <ADMIN_TOKEN>
    Body JSON: {\"current_qr_token\": \"...\", \"static_redirect_url\": \"https://...\"}
    """
    cfg = load_config()
    if not _require_bearer(cfg):
        return ({"ok": False, "error": "unauthorized"}, 401)

    data = request.get_json(silent=True) or {}
    if not isinstance(data, dict):
        return ({"ok": False, "error": "invalid_json"}, 400)

    token = str(data.get("current_qr_token") or "").strip()
    url = str(data.get("static_redirect_url") or "").strip()
    if not token or not url:
        return ({"ok": False, "error": "missing_fields"}, 400)

    cfg["current_qr_token"] = token
    cfg["static_redirect_url"] = url
    save_config(cfg)
    return {"ok": True}


def sync_info_to_remote(cfg: dict) -> None:
    if not cfg.get("remote_sync_enabled"):
        return
    base = (cfg.get("remote_base_url") or "").strip().rstrip("/")
    token = (cfg.get("remote_admin_token") or "").strip()
    if not base or not token:
        raise RuntimeError("remote_base_url veya remote_admin_token eksik.")

    url = base + "/api/config"
    payload = {
        "info_title": cfg.get("info_title") or "",
        "info_body": cfg.get("info_body") or "",
    }
    body = json.dumps(payload, ensure_ascii=False).encode("utf-8")
    req = urllib.request.Request(
        url,
        data=body,
        headers={
            "Content-Type": "application/json; charset=utf-8",
            "Authorization": f"Bearer {token}",
        },
        method="POST",
    )
    with urllib.request.urlopen(req, timeout=15) as resp:
        _ = resp.read()


def sync_rotate_to_remote(cfg: dict) -> None:
    if not cfg.get("remote_rotate_enabled"):
        return
    base = (cfg.get("remote_base_url") or "").strip().rstrip("/")
    token = (cfg.get("remote_admin_token") or "").strip()
    if not base or not token:
        raise RuntimeError("remote_base_url veya remote_admin_token eksik.")

    static_url = (cfg.get("static_redirect_url") or "").strip()
    if not static_url:
        raise RuntimeError("static_redirect_url eksik.")

    active = _get_active_qr_token(cfg)

    url = base + "/api/rotate"
    payload = {
        "current_qr_token": active,
        "static_redirect_url": static_url,
    }
    body = json.dumps(payload, ensure_ascii=False).encode("utf-8")
    req = urllib.request.Request(
        url,
        data=body,
        headers={
            "Content-Type": "application/json; charset=utf-8",
            "Authorization": f"Bearer {token}",
        },
        method="POST",
    )
    with urllib.request.urlopen(req, timeout=15) as resp:
        _ = resp.read()
    cfg["last_sent_qr_token"] = active
    save_config(cfg)


@app.post("/admin/new_qr")
def admin_new_qr_post():
    cfg = load_config()
    if not _require_admin(cfg):
        return ("Yetkisiz.", 401)
    if _is_host_only(cfg):
        return ("Not Found", 404)

    # Explicitly rotate token (this is the only time we invalidate previous QR)
    _ = _rotate_active_qr_token(cfg)

    # Try to sync new token to host (if enabled)
    try:
        sync_rotate_to_remote(cfg)
    except Exception:
        # Keep going: local QR can still be saved, sync can be retried later.
        pass

    # Re-generate saved png (if enabled)
    global _SAVED_ONCE, _LAST_SAVED_PATH, _LAST_SAVE_ERROR
    _SAVED_ONCE = False
    _LAST_SAVED_PATH = None
    _LAST_SAVE_ERROR = None
    _maybe_save_once(cfg)

    return redirect(url_for("admin_get", token=cfg.get("admin_token")))


@app.get("/admin")
def admin_root():
    cfg = load_config()
    if not _require_admin(cfg):
        return (
            "Yetkisiz. /admin?token=... şeklinde admin_token ile girin. "
            "Token, config.json içinde: admin_token",
            401,
        )
    return redirect(url_for("admin_customers", token=cfg.get("admin_token")))


@app.get("/admin/config")
def admin_get():
    cfg = load_config()
    if not _require_admin(cfg):
        return (
            "Yetkisiz. /admin?token=... şeklinde admin_token ile girin. "
            "Token, config.json içinde: admin_token",
            401,
        )

    return render_template("admin.html", cfg=cfg, token=cfg.get("admin_token"))


@app.post("/admin/config")
def admin_post():
    cfg = load_config()
    if not _require_admin(cfg):
        return ("Yetkisiz.", 401)

    cfg["qr_mode"] = request.form.get("qr_mode", cfg.get("qr_mode", "info_page"))
    cfg["target_url"] = request.form.get("target_url", cfg.get("target_url", "")).strip()
    cfg["append_run_id_to_target_url"] = bool(request.form.get("append_run_id_to_target_url"))
    cfg["info_title"] = request.form.get("info_title", cfg.get("info_title", "Bilgiler"))
    cfg["info_body"] = request.form.get("info_body", cfg.get("info_body", ""))

    save_config(cfg)
    return redirect(url_for("admin_get", token=cfg.get("admin_token")))


@app.get("/admin/customers")
def admin_customers():
    cfg = load_config()
    if not _require_admin(cfg):
        return ("Yetkisiz.", 401)
    q = (request.args.get("q") or "").strip()
    customers = list_customers(cfg, q=q)
    return render_template("customers.html", token=cfg.get("admin_token"), q=q, customers=customers)


@app.post("/admin/customers/new")
def admin_customers_new():
    cfg = load_config()
    if not _require_admin(cfg):
        return ("Yetkisiz.", 401)
    if _is_host_only(cfg):
        return ("Not Found", 404)
    name = str(request.form.get("name") or "").strip()
    phone = str(request.form.get("phone") or "").strip()
    plate = str(request.form.get("plate") or "").strip()
    if not name:
        return ("İsim zorunlu.", 400)
    c = create_customer(cfg, name=name, phone=phone, plate=plate)
    return redirect(url_for("admin_customer_detail", public_id=c.public_id, token=cfg.get("admin_token")))


@app.get("/admin/customers/<public_id>")
def admin_customer_detail(public_id: str):
    cfg = load_config()
    if not _require_admin(cfg):
        return ("Yetkisiz.", 401)
    customer = get_customer_by_public_id(cfg, public_id)
    if not customer:
        return ("Bulunamadı.", 404)
    visits = list_visits_for_customer(cfg, customer.id)
    operations_by_visit: dict[int, list] = {}
    for v in visits:
        operations_by_visit[v.id] = list_operations_for_visit(cfg, v.id)
    public_url = _public_url_for_customer(cfg, customer.public_id, customer.secret)
    return render_template(
        "customer_detail.html",
        token=cfg.get("admin_token"),
        customer=customer,
        visits=visits,
        operations_by_visit=operations_by_visit,
        public_url=public_url,
        cache_bust=RUN_ID,
    )


@app.post("/admin/customers/<public_id>/delete")
def admin_customer_delete(public_id: str):
    cfg = load_config()
    if not _require_admin(cfg):
        return ("Yetkisiz.", 401)
    if _is_host_only(cfg):
        return ("Not Found", 404)
    ok = delete_customer_by_public_id(cfg, public_id)
    if not ok:
        return ("Bulunamadı.", 404)
    return redirect(url_for("admin_customers", token=cfg.get("admin_token")))


@app.get("/admin/customers/<public_id>/qr.png")
def admin_customer_qr_png(public_id: str):
    cfg = load_config()
    if not _require_admin(cfg):
        return ("Yetkisiz.", 401)
    if qrcode is None:
        return ("QR üretimi için 'qrcode' paketi kurulu değil.", 500)
    customer = get_customer_by_public_id(cfg, public_id)
    if not customer:
        return ("Bulunamadı.", 404)
    payload = _public_url_for_customer(cfg, customer.public_id, customer.secret)

    qr = qrcode.QRCode(  # type: ignore[union-attr]
        version=None,
        error_correction=qrcode.constants.ERROR_CORRECT_M,  # type: ignore[union-attr]
        box_size=10,
        border=4,
    )
    qr.add_data(payload)
    qr.make(fit=True)
    img = qr.make_image(fill_color="black", back_color="white")

    buf = io.BytesIO()
    img.save(buf, format="PNG")
    buf.seek(0)
    return Response(buf.getvalue(), mimetype="image/png")


@app.get("/admin/customers/<public_id>/visits/new")
def admin_visit_new_get(public_id: str):
    cfg = load_config()
    if not _require_admin(cfg):
        return ("Yetkisiz.", 401)
    customer = get_customer_by_public_id(cfg, public_id)
    if not customer:
        return ("Bulunamadı.", 404)
    from datetime import date as _date
    return render_template(
        "visit_form.html",
        token=cfg.get("admin_token"),
        customer=customer,
        default_date=str(_date.today()),
    )


@app.post("/admin/customers/<public_id>/visits/new")
def admin_visit_new_post(public_id: str):
    cfg = load_config()
    if not _require_admin(cfg):
        return ("Yetkisiz.", 401)
    if _is_host_only(cfg):
        return ("Not Found", 404)
    customer = get_customer_by_public_id(cfg, public_id)
    if not customer:
        return ("Bulunamadı.", 404)

    visit_date = str(request.form.get("visit_date") or "").strip()
    km = str(request.form.get("km") or "").strip()
    notes = str(request.form.get("notes") or "").strip()
    operations_text = str(request.form.get("operations_text") or "")

    ops: list[tuple[str, str]] = []
    for raw in operations_text.splitlines():
        line = raw.strip()
        if not line:
            continue
        if "|" in line:
            left, right = line.split("|", 1)
            ops.append((left.strip(), right.strip()))
        else:
            ops.append((line, ""))

    create_visit(
        cfg,
        customer_id=customer.id,
        visit_date=visit_date,
        km=km,
        notes=notes,
        operations=ops,
    )
    return redirect(url_for("admin_customer_detail", public_id=customer.public_id, token=cfg.get("admin_token")))


@app.get("/c/<public_id>")
def customer_public(public_id: str):
    cfg = load_config()
    customer = get_customer_by_public_id(cfg, public_id)
    if not customer:
        return ("Not Found", 404)
    k = str(request.args.get("k") or "")
    if not k or not secrets.compare_digest(k, customer.secret):
        return ("Not Found", 404)
    visits = list_visits_for_customer(cfg, customer.id)
    operations_by_visit: dict[int, list] = {}
    for v in visits:
        operations_by_visit[v.id] = list_operations_for_visit(cfg, v.id)
    title = f"{customer.name} - Bilgiler"
    return render_template(
        "customer_public.html",
        title=title,
        customer=customer,
        visits=visits,
        operations_by_visit=operations_by_visit,
    )


if __name__ == "__main__":
    cfg = load_config()
    print("RUN_ID:", RUN_ID)
    # Ensure we have a persistent token for the currently-active QR.
    _ = _get_active_qr_token(cfg)
    # If desired, push the text to the hosted site so customers see it.
    try:
        sync_info_to_remote(cfg)
        if cfg.get("remote_sync_enabled"):
            print("Remote sync: OK")
    except Exception as e:
        if cfg.get("remote_sync_enabled"):
            print("Remote sync: FAILED:", repr(e))

    # Sync current token to hosted gate (does not rotate unless token changed).
    try:
        sync_rotate_to_remote(cfg)
        if cfg.get("remote_rotate_enabled"):
            print("Remote rotate: OK")
    except Exception as e:
        if cfg.get("remote_rotate_enabled"):
            print("Remote rotate: FAILED:", repr(e))

    _maybe_save_once(cfg)
    if _LAST_SAVED_PATH:
        print("QR PNG kaydedildi:", _LAST_SAVED_PATH)
        print("QR içeriği:", _qr_payload_for_saved_png(cfg))
    elif _LAST_SAVE_ERROR:
        print("QR PNG kaydedilemedi:", _LAST_SAVE_ERROR)
    print("Admin sayfası:")
    print(f"  http://127.0.0.1:8000/admin?token={cfg.get('admin_token')}")
    debug = os.getenv("FLASK_DEBUG", "").strip() == "1"
    app.run(host="127.0.0.1", port=8000, debug=debug)


