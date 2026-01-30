from __future__ import annotations

from config_store import load_config, save_config


def _normalize_base_url(url: str) -> str:
    url = (url or "").strip()
    if not url:
        return ""
    url = url.rstrip("/")
    if not (url.startswith("http://") or url.startswith("https://")):
        url = "https://" + url
    return url


def main() -> None:
    cfg = load_config()

    print("Render URL'nizi girin (ör: https://xxx.onrender.com)")
    base = _normalize_base_url(input("remote_base_url: ").strip())

    print("Render'daki ADMIN_TOKEN değerini girin (Render Environment'da yazdığınız)")
    token = input("remote_admin_token: ").strip()

    if not base:
        raise SystemExit("Hata: remote_base_url boş olamaz.")
    if not token:
        raise SystemExit("Hata: remote_admin_token boş olamaz.")

    cfg["public_base_url"] = base
    cfg["remote_base_url"] = base
    cfg["remote_admin_token"] = token
    cfg["remote_sync_enabled"] = True
    cfg["app_mode"] = "full"  # local app full mode

    save_config(cfg)
    print("OK: config.json güncellendi.")
    print("Sonraki adım: python sync_remote.py")


if __name__ == "__main__":
    main()

