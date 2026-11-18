# Pic-up v2.0 🚀

Универсальный файлообменник с бэкендом хранения в Telegram и открытым публичным REST API без ключей.

Поддерживает **любые типы файлов**: изображения, видео (со стримингом и перемоткой), музыку, архивы, документы, программы.

---

## ✨ Основные возможности

- 📦 **Любые форматы файлов**: Фото, MP4, MKV, MP3, ZIP, PDF, DOCX, APK, EXE и др.
- ⚡ **Публичный REST API без ключей**: Моментальная загрузка через `curl`, Python, JS или PHP без авторизации.
- 📖 **Интерактивная документация API**: Встроенный Swagger UI (`/docs`), ReDoc (`/redoc`) и страница `/api/docs`.
- 💬 **Telegram как хранилище**: Файлы автоматически загружаются в закрытый Telegram-канал, экономя место на сервере.
- 🎬 **Потоковое видео и аудио**: Поддержка HTTP 206 Partial Content (перемотка в плеере работает плавно на ПК и мобильных).
- 📱 **QR-коды**: Быстрая передача ссылок на смартфоны.
- 🗜️ **Скачивание архивом (ZIP)**: Возможность скачать всю подборку одним кликом.
- 🐳 **Полная контейнеризация**: Проект упакован в Docker + Docker Compose.
- 🛠️ **Автоматический инсталлятор `install.sh`**: Развертывание в одну команду на чистой Ubuntu.

---

## 🚀 Быстрый старт на чистом сервере Ubuntu

Для развертывания проекта на чистой Ubuntu выполните всего 2 команды под `root`:

```bash
git clone https://github.com/GrinRonm/Pic-up /var/www/pic-up.ae0.ru
cd /var/www/pic-up.ae0.ru && bash install.sh
```

Скрипт `install.sh` автоматически:
1. Проверит и установит Docker и Docker Compose.
2. Проверит и установит Nginx и Certbot.
3. Настроит reverse-proxy и выпустит SSL-сертификат Let's Encrypt.
4. Создаст окружение `.env` и поднимет Docker-контейнер.
5. Проверит работоспособность (healthcheck).

---

## 📡 Публичный API (Без ключей)

### 1. Загрузка файла через cURL

```bash
# Загрузка одного файла
curl -X POST "https://pic-up.ae0.ru/api/upload" \
  -F "file=@my_file.pdf"

# Загрузка нескольких файлов
curl -X POST "https://pic-up.ae0.ru/api/upload" \
  -F "files=@photo.jpg" \
  -F "files=@video.mp4"
```

**Пример ответа (JSON):**
```json
{
  "success": true,
  "batch_code": "8e3c158145262601",
  "view_url": "https://pic-up.ae0.ru/view/?code=8e3c158145262601",
  "qr_url": "https://pic-up.ae0.ru/qr/?code=8e3c158145262601",
  "download_all_url": "https://pic-up.ae0.ru/download/?code=8e3c158145262601",
  "files": [
    {
      "original_name": "video.mp4",
      "filename": "7499c60fadf544ec.mp4",
      "file_type": "video",
      "mime_type": "video/mp4",
      "size_bytes": 15420100,
      "size_formatted": "14.7 MB",
      "url": "https://pic-up.ae0.ru/i/7499c60fadf544ec.mp4",
      "download_url": "https://pic-up.ae0.ru/download/file/7499c60fadf544ec.mp4"
    }
  ]
}
```

### 2. Загрузка на Python

```python
import requests

url = "https://pic-up.ae0.ru/api/upload"
with open("my_video.mp4", "rb") as f:
    files = {"files": ("my_video.mp4", f, "video/mp4")}
    r = requests.post(url, files=files)
    data = r.json()
    print("Ссылка на просмотр:", data["view_url"])
```

### 3. Загрузка на JavaScript

```javascript
const formData = new FormData();
formData.append('files', fileInput.files[0]);

fetch('https://pic-up.ae0.ru/api/upload', {
  method: 'POST',
  body: formData
})
.then(res => res.json())
.then(data => console.log('Подборка:', data.view_url));
```

---

## ⚙️ Переменные окружения (.env)

| Переменная | По умолчанию | Описание |
|---|---|---|
| `APP_URL` | `https://pic-up.ae0.ru` | Публичный адрес сайта |
| `PORT` | `8088` | Внутренний порт Docker |
| `TELEGRAM_BOT_TOKEN` | `...` | Токен бота Telegram |
| `TELEGRAM_CHAT_ID` | `...` | ID канала для хранения файлов |
| `MAX_FILES` | `10` | Макс. файлов за один раз |
| `MAX_FILE_SIZE_MB` | `50` | Макс. размер одного файла (МБ) |
| `EXPIRY_DAYS` | `30` | Срок хранения (дней) |

---

## 🛠️ Управление Docker

```bash
# Просмотр логов
docker compose logs -f

# Перезапуск сервиса
docker compose restart

# Остановка
docker compose down

# Обновление и пересборка
docker compose up -d --build
```
