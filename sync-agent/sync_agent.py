import argparse
import json
import logging
import mimetypes
import os
import sys
import time
import uuid
from pathlib import Path
from urllib import request, error


def load_env(path: Path) -> dict:
    values = {}
    if not path.exists():
        return values
    for line in path.read_text(encoding="utf-8").splitlines():
        line = line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, value = line.split("=", 1)
        values[key.strip()] = value.strip().strip('"')
    return values


def wait_until_readable(path: Path, attempts: int = 12, delay: float = 2.0) -> bool:
    last_size = -1
    stable_count = 0
    for _ in range(attempts):
        try:
            size = path.stat().st_size
            with path.open("rb") as handle:
                handle.read(1)
            if size == last_size:
                stable_count += 1
            else:
                stable_count = 0
            if stable_count >= 1:
                return True
            last_size = size
        except OSError:
            pass
        time.sleep(delay)
    return False


def multipart_body(field_name: str, file_path: Path) -> tuple[bytes, str]:
    boundary = "----drn-sync-" + uuid.uuid4().hex
    content_type = mimetypes.guess_type(file_path.name)[0] or "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
    parts = [
        f"--{boundary}\r\n".encode(),
        f'Content-Disposition: form-data; name="{field_name}"; filename="{file_path.name}"\r\n'.encode(),
        f"Content-Type: {content_type}\r\n\r\n".encode(),
        file_path.read_bytes(),
        b"\r\n",
        f"--{boundary}--\r\n".encode(),
    ]
    return b"".join(parts), boundary


def upload_excel(api_url: str, api_key: str, excel_path: Path) -> dict:
    body, boundary = multipart_body("excel", excel_path)
    req = request.Request(
        api_url,
        data=body,
        method="POST",
        headers={
            "Content-Type": f"multipart/form-data; boundary={boundary}",
            "Content-Length": str(len(body)),
            "X-Panel-Api-Key": api_key,
            "User-Agent": "DRN Excel Sync Agent/1.0",
        },
    )
    try:
        with request.urlopen(req, timeout=60) as response:
            raw = response.read().decode("utf-8")
            return json.loads(raw)
    except error.HTTPError as exc:
        raw = exc.read().decode("utf-8", errors="replace")
        try:
            return json.loads(raw)
        except json.JSONDecodeError:
            return {"ok": False, "error": raw or str(exc)}


def run_watch(excel_path: Path, api_url: str, api_key: str, interval: float) -> None:
    last_mtime = None
    logging.info("Watching %s", excel_path)
    while True:
        if not excel_path.exists():
            logging.warning("Excel file not found: %s", excel_path)
            time.sleep(interval)
            continue

        mtime = excel_path.stat().st_mtime
        if last_mtime is None:
            last_mtime = mtime
            logging.info("Initial upload")
            sync_once(excel_path, api_url, api_key)
        elif mtime != last_mtime:
            last_mtime = mtime
            logging.info("Change detected")
            sync_once(excel_path, api_url, api_key)

        time.sleep(interval)


def sync_once(excel_path: Path, api_url: str, api_key: str) -> None:
    if not wait_until_readable(excel_path):
        logging.error("Excel file is still locked or unstable: %s", excel_path)
        return
    result = upload_excel(api_url, api_key, excel_path)
    if result.get("ok"):
        payload = result.get("result", {})
        logging.info("Sync ok: imported=%s skipped=%s status=%s", payload.get("imported"), payload.get("skipped"), payload.get("status"))
    else:
        logging.error("Sync failed: %s", result.get("error") or result)


def main() -> int:
    parser = argparse.ArgumentParser(description="DRN Excel sync agent")
    parser.add_argument("--once", action="store_true", help="Run one upload and exit")
    parser.add_argument("--env", default=".env", help="Path to env file")
    args = parser.parse_args()

    env = load_env(Path(args.env))
    excel_path = Path(env.get("EXCEL_PATH", "")).expanduser()
    api_url = env.get("API_URL", "")
    api_key = env.get("API_KEY", "")
    interval = float(env.get("POLL_SECONDS", "5"))
    log_path = Path(env.get("LOG_PATH", "sync-agent.log"))

    logging.basicConfig(
        level=logging.INFO,
        format="%(asctime)s %(levelname)s %(message)s",
        handlers=[logging.FileHandler(log_path, encoding="utf-8"), logging.StreamHandler(sys.stdout)],
    )

    if not excel_path or not api_url or not api_key:
        logging.error("EXCEL_PATH, API_URL and API_KEY are required in .env")
        return 2

    if args.once:
        sync_once(excel_path, api_url, api_key)
    else:
        run_watch(excel_path, api_url, api_key, interval)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
