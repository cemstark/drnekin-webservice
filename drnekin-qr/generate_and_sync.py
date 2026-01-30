"""
One-shot script:
- sends /info text to the hosted service (optional, if enabled)
- rotates hosted QR gate token (optional, if enabled)
- generates and saves a fresh qr.png to Desktop (optional, if enabled)

This is meant to be launched by double-click (via QR-URET.bat / QR-URET.vbs),
so it does NOT start the Flask server.
"""

from __future__ import annotations

import sys

from config_store import load_config

try:
    # Reuse existing logic (rotation token + save-to-desktop).
    from app import (  # type: ignore
        _rotate_active_qr_token,
        _qr_payload_for_saved_png,
        save_qr_png_to_desktop,
        sync_info_to_remote,
        sync_rotate_to_remote,
    )
except ModuleNotFoundError as e:  # pragma: no cover
    # Usually missing Flask/qrcode deps if requirements weren't installed.
    print("Gerekli paketler eksik gibi görünüyor.")
    print("Hata:", repr(e))
    print("\nÇözüm: önce KURULUM.bat dosyasını 1 kez çalıştırın.")
    sys.exit(1)


def main() -> int:
    cfg = load_config()

    # Explicitly create a NEW QR (this is the "generate" action).
    _rotate_active_qr_token(cfg)

    # Always print what the new QR will contain (helps debugging).
    try:
        payload = _qr_payload_for_saved_png(cfg)
        print("Yeni QR içeriği:", payload)
    except Exception as e:
        print("QR içeriği hesaplanamadı:", repr(e))

    # Push text to host (customers see it)
    try:
        sync_info_to_remote(cfg)
        if cfg.get("remote_sync_enabled"):
            print("Remote sync: OK")
    except Exception as e:
        if cfg.get("remote_sync_enabled"):
            print("Remote sync: FAILED:", repr(e))

    # Rotate host gate token (old QR becomes invalid)
    try:
        sync_rotate_to_remote(cfg)
        if cfg.get("remote_rotate_enabled"):
            print("Remote rotate: OK")
            print("Host'ta aktif token ayarlandı. (Token gizli)")
    except Exception as e:
        if cfg.get("remote_rotate_enabled"):
            print("Remote rotate: FAILED:", repr(e))

    # Generate QR png (local)
    try:
        out = save_qr_png_to_desktop(cfg)
        print("QR PNG kaydedildi:", out)
    except Exception as e:
        print("QR PNG üretilemedi:", repr(e))
        return 2

    return 0


if __name__ == "__main__":
    raise SystemExit(main())

