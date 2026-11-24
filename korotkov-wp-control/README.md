# Korotkov WP Control

Версия: 0.1.0

Система состоит из WordPress-плагина и локального MCP-моста. Плагин предоставляет закрытый REST API, а MCP-мост подключает Cursor / VS Code и другие MCP-клиенты к сайту. ZIP создаётся на сайте и скачивается на компьютер, где запущен MCP-мост.

## Установка WordPress-плагина

1. Упакуйте каталог `korotkov-wp-control` в ZIP так, чтобы PHP-файл находился внутри одноимённого каталога. Папку `mcp-server` в ZIP плагина включать не обязательно.
2. Установите ZIP через WordPress → Плагины → Добавить плагин → Загрузить плагин.
3. Сгенерируйте отдельный длинный токен: `php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"`
4. Добавьте в `wp-config.php` перед строкой «That's all, stop editing»: `define('KWC_API_TOKEN', 'ВАШ_СЛУЧАЙНЫЙ_ТОКЕН');`
5. Активируйте плагин. Без токена длиной не менее 40 символов API отвечает 503.

## Авторизация

Все запросы включают `X-KWC-Token` и WordPress REST authentication через отдельный Application Password пользователя с правом `manage_options`. Только HTTPS. Не используйте основной пароль администратора. Не передавайте токен в URL и не добавляйте `.env` в Git.

## Локальный MCP-мост

Требуется Python 3.10+.

1. Скопируйте папку `mcp-server` на компьютер.
2. В PowerShell выполните:
   ```powershell
   py -m venv .venv
   .\.venv\Scripts\Activate.ps1
   pip install -r requirements.txt
   ```
3. Скопируйте `.env.example` в `.env` и заполните адрес сайта, токен, имя WP-пользователя и Application Password.
4. Добавьте MCP-сервер в конфигурацию Cursor (пути замените на свои):
   ```json
   {
     "mcpServers": {
       "korotkov-wp-control": {
         "command": "C:\\PATH\\TO\\python.exe",
         "args": ["C:\\PATH\\TO\\mcp-server\\server.py"],
         "env": {
           "KWC_BASE_URL": "https://ipkorotkov.online",
           "KWC_TOKEN": "ВАШ_ТОКЕН",
           "KWC_WP_USERNAME": "WP_АДМИНИСТРАТОР",
           "KWC_WP_APP_PASSWORD": "APPLICATION_PASSWORD",
           "KWC_DOWNLOAD_DIR": "C:\\Users\\USER\\Korotkov-WP-Control"
         }
       }
     }
   }
   ```
5. Перезапустите MCP-клиент. Доступны инструменты `site_status`, `list_site_files`, `read_site_file`, `create_site_zip`.

Мост работает через STDIO: отдельный публичный MCP-хостинг не нужен. Он рассчитан на MCP-клиенты с поддержкой локальных серверов. Подключение пользовательского MCP непосредственно к ChatGPT зависит от тарифа и доступности функции; на Free нельзя рассчитывать на запись через custom MCP.

## API

Базовый адрес: `https://ВАШ-ДОМЕН/wp-json/kwc/v1`

- `GET /status` — состояние API.
- `GET /files?path=plugins` — список файлов и каталогов (до 500 элементов).
- `GET /read?path=plugins/имя-плагина/имя-файла.php` — чтение UTF-8 файла до 256 КБ.
- `POST /archive` с JSON `{"path":"plugins/имя-плагина"}` — создать ZIP.
- `GET /download/{archive_id}` — скачать созданный ZIP; повторное скачивание невозможно.

Разрешённые корни: `plugins`, `themes`, `uploads`, `mu-plugins`.

## Ограничения безопасности версии 0.1.0

- Нет произвольного выполнения PHP или shell-команд.
- Нет записи/удаления файлов и изменения настроек.
- Запрещён выход за разрешённые каталоги и переход по символьным ссылкам.
- Не выдаются `wp-config.php`, `.env`, SQL-дампы, приватные ключи и скрытые файлы.
- ZIP исключает секретные файлы, кеши, vendor, node_modules и .git; максимум 5000 файлов / 256 МБ.
- ZIP временно хранится в `uploads/kwc-private`, защищённом правилами веб-сервера, и удаляется после скачивания или истечения часа.

## Следующие этапы

1. Запись файлов с резервной копией и атомарной заменой.
2. Установка и обновление плагинов из проверенных ZIP.
3. Управление страницами и записями.
4. Интеграция с Korotkov SEO Factory.
5. Единый профиль подключения для ipkorotkov.online и ipkorotkov.ru.
