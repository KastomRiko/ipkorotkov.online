import os
import re
from pathlib import Path
from urllib.parse import urlsplit

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
    base = urlsplit(BASE_URL)
    if base.scheme != "https" or not base.netloc or base.username or base.password or base.query or base.fragment:
        raise RuntimeError("KWC_BASE_URL должен быть HTTPS-адресом сайта без логина, пароля, query и fragment.")
    headers = {"X-KWC-Token": TOKEN, "Accept": "application/json"}
    if not WP_USERNAME or not WP_APP_PASSWORD:
        raise RuntimeError("Укажите KWC_WP_USERNAME и KWC_WP_APP_PASSWORD: требуется отдельный Application Password администратора WordPress.")
    auth = (WP_USERNAME, WP_APP_PASSWORD)
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


def wp_request_json(method: str, endpoint: str, **kwargs):
    """Use WordPress core REST API for pages, posts and plugin management."""
    with client() as c:
        response = c.request(method, f"{BASE_URL}/wp-json/wp/v2/{endpoint.lstrip('/')}", **kwargs)
        response.raise_for_status()
        if response.status_code == 204 or not response.content:
            return {"success": True}
        return response.json()


def checked_kind(kind: str) -> str:
    if kind not in {"pages", "posts"}:
        raise ValueError("Разрешены только pages и posts.")
    return kind


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
def write_site_file(path: str, content: str, expected_sha256: str = "") -> dict:
    """Изменить существующий UTF-8 файл сайта (до 1 МБ). Перед записью сервер создаёт резервную копию; expected_sha256 защищает от перезаписи чужих изменений."""
    payload = {"path": checked_path(path), "content": content}
    if expected_sha256:
        if not re.fullmatch(r"[a-f0-9]{64}", expected_sha256):
            raise ValueError("expected_sha256 должен содержать 64 символа hex.")
        payload["expected_sha256"] = expected_sha256
    return request_json("POST", "write", json=payload)


@mcp.tool()
def restore_site_file(backup_id: str) -> dict:
    """Восстановить файл из резервной копии, созданной write_site_file. Копия хранится до 24 часов."""
    if not re.fullmatch(r"[a-f0-9]{32}", backup_id):
        raise ValueError("Некорректный backup_id.")
    return request_json("POST", "restore", json={"backup_id": backup_id})


@mcp.tool()
def list_site_content(kind: str = "pages", status: str = "any", per_page: int = 50) -> list:
    """Получить страницы или записи WordPress. kind: pages/posts; status: any/publish/draft/private/pending."""
    kind = checked_kind(kind)
    if status not in {"any", "publish", "draft", "private", "pending"}:
        raise ValueError("Недопустимый статус.")
    if not 1 <= per_page <= 100:
        raise ValueError("per_page должен быть от 1 до 100.")
    return wp_request_json("GET", kind, params={"status": status, "per_page": per_page, "_fields": "id,date,modified,slug,status,link,title,content,author"})


@mcp.tool()
def create_site_content(kind: str, title: str, content: str, status: str = "draft") -> dict:
    """Создать страницу или запись WordPress. По умолчанию создаётся черновик; публикация возможна только при status='publish'."""
    kind = checked_kind(kind)
    if status not in {"draft", "publish", "private", "pending"}:
        raise ValueError("Недопустимый статус.")
    if not title.strip():
        raise ValueError("Заголовок не может быть пустым.")
    return wp_request_json("POST", kind, json={"title": title, "content": content, "status": status})


@mcp.tool()
def update_site_content(kind: str, content_id: int, title: str = "", content: str = "", status: str = "") -> dict:
    """Изменить существующую страницу или запись. Передавайте только изменяемые поля; пустые поля не затрагиваются."""
    kind = checked_kind(kind)
    if content_id < 1:
        raise ValueError("Некорректный ID.")
    payload = {}
    if title:
        payload["title"] = title
    if content:
        payload["content"] = content
    if status:
        if status not in {"draft", "publish", "private", "pending"}:
            raise ValueError("Недопустимый статус.")
        payload["status"] = status
    if not payload:
        raise ValueError("Не указаны поля для изменения.")
    return wp_request_json("POST", f"{kind}/{content_id}", json=payload)


@mcp.tool()
def list_site_plugins() -> list:
    """Получить список установленных плагинов и их статусы через WordPress REST API."""
    return wp_request_json("GET", "plugins")


@mcp.tool()
def set_site_plugin_status(plugin: str, status: str) -> dict:
    """Активировать или деактивировать установленный плагин. Перед изменением проверьте точный plugin basename из list_site_plugins."""
    if status not in {"active", "inactive"}:
        raise ValueError("Статус должен быть active или inactive.")
    if not plugin or len(plugin) > 200 or plugin.startswith("/") or ".." in plugin or "\\\\" in plugin:
        raise ValueError("Некорректный plugin basename.")
    from urllib.parse import quote
    return wp_request_json("POST", "plugins/" + quote(plugin, safe=""), json={"status": status})


@mcp.tool()
def create_site_zip(path: str) -> dict:
    """Создать ZIP каталога на сайте и скачать его в локальную папку Korotkov-WP-Control."""
    payload = request_json("POST", "archive", json={"path": checked_path(path)})
    download_url = payload.get("download_url")
    archive_id = payload.get("archive_id")
    if not download_url or not archive_id or not re.fullmatch(r"[a-f0-9]{32}", archive_id):
        raise RuntimeError("Сайт вернул некорректные данные архива.")
    expected = urlsplit(f"{API_BASE}/download/{archive_id}")
    actual = urlsplit(download_url)
    if actual.scheme != "https" or actual.netloc != expected.netloc or actual.path != expected.path or actual.query or actual.fragment:
        raise RuntimeError("Сайт вернул небезопасный адрес скачивания; запрос отменён.")
    DOWNLOAD_DIR.mkdir(parents=True, exist_ok=True)
    filename = re.sub(r"[^A-Za-z0-9._-]+", "_", Path(path).name or "wordpress") + ".zip"
    target = DOWNLOAD_DIR / f"{filename}-{archive_id[:8]}.zip"
    headers = {"X-KWC-Token": TOKEN}
    auth = (WP_USERNAME, WP_APP_PASSWORD)
    partial = target.with_suffix(target.suffix + ".part")
    try:
        with httpx.stream("GET", download_url, headers=headers, auth=auth, timeout=180, follow_redirects=False) as response:
            response.raise_for_status()
            with partial.open("wb") as output:
                for chunk in response.iter_bytes():
                    output.write(chunk)
        if not partial.is_file() or partial.stat().st_size == 0:
            raise RuntimeError("Скачанный архив пуст.")
        os.replace(partial, target)
    finally:
        if partial.exists():
            partial.unlink()
    return {"saved_to": str(target), "bytes": target.stat().st_size, "archive_id": archive_id}


if __name__ == "__main__":
    mcp.run(transport="stdio")
