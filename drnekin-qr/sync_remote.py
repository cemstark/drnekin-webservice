from config_store import load_config
from app import sync_info_to_remote, sync_rotate_to_remote


def main() -> None:
    cfg = load_config()
    sync_info_to_remote(cfg)
    sync_rotate_to_remote(cfg)
    print("OK: Bilgiler host'a gönderildi.")


if __name__ == "__main__":
    main()

