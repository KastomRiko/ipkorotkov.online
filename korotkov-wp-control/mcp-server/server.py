import os
import re
from pathlib import Path

from dotenv import load_dotenv
import httpx
from mcp.server.fastmcp import FastMCP

load_dotenv()

BASE_URL = os.environ.get("KWC_BASE_URL", "").rstrip("/")
TOKEN = os.environ.get("KWC_TOKEN", "")
WP_USERNAME = os.environ.get("KWC_WP_USERNAME", "")
WP_APP_PASSWORD = os.environ.get("KWC_WP_APP_PASSWORD", "")
DOWNLOAD_DIR = Path(os.environ.get("KWC_DOWNLOAD_DIR", str(Path.home() / "Korotkov-WP-Control")))
API_BASE = f"{BASE_URL}/wp-json/kwc/v1" if BASE_URL else ""

mcp = FastMCP("Korotkov WP Control")


def client() -> httpx.Client:
    if not API_BASE or not TOKEN:
        raise RuntimeError("Заполните KWC_BASE_URL и KWC_TOKEN в файле .env или переменных окружения.")
    headers = {"X-KWC-Token": TOKEN, "Accept": "application/json"}
    auth = (WP_USERNAME, WP_APP_PASSWORD) if WP_USERNAME and WP_APP_PASSWORD else None
    return httpx.Client(timeout=90, headers=headers, auth=auth, follow_redirects=False)


def checked_path(value: str) -> str:
    value = value.strip().replace("\\", "/")
    if not value or value.startswith("/") or re.search(r"(^|/)\.\.?(/|$)", value):
        raise ValueError("Путь должен быть относительным и не должен содержать ..")
    if value.split("/", 1)[0] not in {"plugins", "themes", "uploads", "mu-plugins"}:
        raise ValueError("Разрешены только plugins, themes, uploads и mu-plugins.")
    return value


def request_json(method: str, endpoint: str, **kwargs):
    with client() as c:
        response = c.request(method, f"{API_BASE}/{endpoint.lstrip('/')}", **kwargs)
        response.raise_for_status()
        return response.json()


@mcp.tool()
def site_status() -> dict:
    """Проверить связь с сайтом, версию плагина и доступные операции."""
    return request_json("GET", "status")


@mcp.tool()
def list_site_files(path: str = "plugins") -> dict:
    """Показать содержимое разрешённого каталога WordPress."""
    return request_json("GET", "files", params={"path": checked_path(path)})


@mcp.tool()
def read_site_file(path: str) -> dict:
    """Прочитать текстовый файл WordPress (лимит 256 КБ; секретные файлы запрещены)."""
    return request_json("GET", "read", params={"path": checked_path(path)})


@mcp.tool()
def create_site_zip(path: str) -> dict:
    """Создать ZIP каталога на сайте и скачать его в локальную папку Korotkov-WP-Control."""
    payload = request_json("POST", "archive", json={"path": checked_path(path)})
    download_url = payload.get("download_url")
    archive_id = payload.get("archive_id")
    if not download_url or not archive_id or not re.fullmatch(r"[a-f0-9]{32}", archive_id):
        raise RuntimeError("Сайт вернул некорректные данные архива.")
    DOWNLOAD_DIR.mkdir(parents=True, exist_ok=True)
    filename = re.sub(r"[^A-Za-z0-9._-]+", "_", Path(path).name or "wordpress") + ".zip"
    target = DOWNLOAD_DIR / f"{filename}-{archive_id[:8]}.zip"
    headers = {"X-KWC-Token": TOKEN}
    auth = (WP_USERNAME, WP_APP_PASSWORD) if WP_USERNAME and WP_APP_PASSWORD else None
    with httpx.stream("GET", download_url, headers=headers, auth=auth, timeout=180, follow_redirects=False) as response:
        response.raise_for_status()
        with target.open("wb") as output:
            for chunk in response.iter_bytes():
                output.write(chunk)
    return {"saved_to": str(target), "bytes": target.stat().st_size, "archive_id": archive_id}


if __name__ == "__main__":
    mcp.run(transport="stdio")
