import json
import os
import secrets
from typing import Any, Dict


DEFAULT_CONFIG: Dict[str, Any] = {
    # full: local kullanım (QR üretimi + /info)
    # host_only: bulutta sadece /info + update API (QR üretimi kapalı)
    "app_mode": "full",
    "qr_mode": "info_page",  # "info_page" | "target_url"
    "target_url": "https://example.com",
    "append_run_id_to_target_url": False,
    "public_base_url": "",  # e.g. "https://your-app.onrender.com" (used for saving qr.png without request context)
    "qr_save_to_desktop": True,
    "qr_output_filename": "qr.png",
    # Local -> Remote sync (müşterilerin göreceği host)
    "remote_sync_enabled": False,
    "remote_base_url": "",  # e.g. "https://your-app.onrender.com"
    "remote_admin_token": "",  # Render'daki ADMIN_TOKEN ile aynı
    # QR rotation / redirect gate (invalidate old QR)
    "remote_rotate_enabled": False,
    "static_redirect_url": "https://statik-qr-website.onrender.com",
    # stored on host (Render) side
    "current_qr_token": "",
    # stored on local (PC) side: stays valid until you explicitly generate a new QR
    "active_qr_token": "",
    # local bookkeeping: last token we successfully pushed to host via /api/rotate
    "last_sent_qr_token": "",
    # Optional DB path override (env QR_DB_PATH has priority)
    "db_path": "",
    "info_title": "Bilgiler",
    "info_body": "Buraya bilgilerinizi yazın.",
    "admin_token": "",
}


def _config_path() -> str:
    env_path = (os.getenv("QR_CONFIG_PATH") or "").strip()
    if env_path:
        return env_path
    here = os.path.dirname(os.path.abspath(__file__))
    return os.path.join(here, "config.json")


def load_config() -> Dict[str, Any]:
    path = _config_path()
    env_admin_token = (os.getenv("ADMIN_TOKEN") or "").strip()
    if not os.path.exists(path):
        cfg = dict(DEFAULT_CONFIG)
        cfg["admin_token"] = env_admin_token or secrets.token_urlsafe(18)
        # If ADMIN_TOKEN is provided, we still write the config file so other fields persist.
        save_config(cfg)
        return cfg

    with open(path, "r", encoding="utf-8") as f:
        cfg = json.load(f)

    merged = dict(DEFAULT_CONFIG)
    merged.update(cfg if isinstance(cfg, dict) else {})

    # Allow overriding admin token via environment (recommended for cloud deploys).
    if env_admin_token:
        merged["admin_token"] = env_admin_token
    elif not merged.get("admin_token"):
        merged["admin_token"] = secrets.token_urlsafe(18)
        save_config(merged)

    return merged


def save_config(cfg: Dict[str, Any]) -> None:
    path = _config_path()
    parent = os.path.dirname(path)
    if parent:
        os.makedirs(parent, exist_ok=True)
    with open(path, "w", encoding="utf-8") as f:
        json.dump(cfg, f, ensure_ascii=False, indent=2)
        f.write("\n")


