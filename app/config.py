import os
from pathlib import Path

BASE_DIR = Path(__file__).resolve().parent.parent

# Data directory for database and storage
DATA_DIR = Path(os.getenv("DATA_DIR", str(BASE_DIR / "data")))
DATA_DIR.mkdir(parents=True, exist_ok=True)

TMP_DIR = DATA_DIR / "uploads" / "tmp"
CACHE_DIR = DATA_DIR / "uploads" / "cache"
TMP_DIR.mkdir(parents=True, exist_ok=True)
CACHE_DIR.mkdir(parents=True, exist_ok=True)

DB_PATH = DATA_DIR / "database.sqlite"

# Application Settings
APP_URL = os.getenv("APP_URL", "https://pic-up.ae0.ru").rstrip("/")
HOST = os.getenv("HOST", "0.0.0.0")
PORT = int(os.getenv("PORT", "8088"))

# Telegram Bot
TELEGRAM_BOT_TOKEN = os.getenv("TELEGRAM_BOT_TOKEN", "7396400534:AAE09x3-7JQpZKeMxPkIAVR1g9F_Hs3_S6c")
TELEGRAM_CHAT_ID = os.getenv("TELEGRAM_CHAT_ID", "-1003724858822")

# Upload Limits
MAX_FILES = int(os.getenv("MAX_FILES", "10"))
MAX_FILE_SIZE_MB = int(os.getenv("MAX_FILE_SIZE_MB", "50"))
MAX_FILE_SIZE = MAX_FILE_SIZE_MB * 1024 * 1024
EXPIRY_DAYS = int(os.getenv("EXPIRY_DAYS", "30"))

# Secret for session cookie
SESSION_COOKIE_NAME = "pic_up_session"
