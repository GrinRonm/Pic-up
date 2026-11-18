#!/usr/bin/env bash
# ==============================================================================
# Pic-up v2.0 - Universal File Sharing & Telegram Storage
# Automatic Installation Script for Clean Ubuntu / Debian
# ==============================================================================

set -e

# Colors for terminal output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
NC='\033[0m' # No Color

echo -e "${CYAN}"
echo "=========================================================="
echo "          🚀 Pic-up v2.0 Deployment Installer            "
echo "   Универсальный файлообменник с бэкендом в Telegram      "
echo "=========================================================="
echo -e "${NC}"

# 1. Check Root Privileges
if [ "$EUID" -ne 0 ]; then
    echo -e "${RED}[✕] Ошибка: Этот скрипт должен быть запущен с правами root (sudo).${NC}"
    exit 1
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR"

echo -e "${BLUE}[1/6] Проверка операционной системы и зависимостей...${NC}"

# Detect OS
if [ -f /etc/os-release ]; then
    . /etc/os-release
    OS_NAME=$ID
    OS_VERSION=$VERSION_ID
    echo -e "  Обнаружена ОС: ${GREEN}${NAME} ${VERSION}${NC}"
else
    echo -e "${YELLOW}  Предупреждение: Не удалось точно определить версию ОС, продолжаем...${NC}"
fi

# Update package lists
export DEBIAN_FRONTEND=noninteractive
apt-get update -y -q

# 2. Check and Install Docker & Docker Compose
echo -e "${BLUE}[2/6] Проверка Docker и Docker Compose...${NC}"
if ! command -v docker &> /dev/null || ! docker compose version &> /dev/null; then
    echo -e "  ${YELLOW}Docker не найден. Устанавливаем официальный Docker...${NC}"
    apt-get install -y -q ca-certificates curl gnupg lsb-release
    
    install -m 0755 -d /etc/apt/keyrings
    if [ ! -f /etc/apt/keyrings/docker.gpg ]; then
        curl -fsSL https://download.docker.com/linux/ubuntu/gpg | gpg --dearmor -o /etc/apt/keyrings/docker.gpg
        chmod a+r /etc/apt/keyrings/docker.gpg
    fi

    echo \
      "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] https://download.docker.com/linux/ubuntu \
      $(lsb_release -cs) stable" | tee /etc/apt/sources.list.d/docker.list > /dev/null

    apt-get update -y -q
    apt-get install -y -q docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin

    systemctl enable docker
    systemctl start docker
    echo -e "  ${GREEN}[✓] Docker успешно установлен!${NC}"
else
    echo -e "  ${GREEN}[✓] Docker и Docker Compose уже установлены: $(docker --version)${NC}"
fi

# 3. Check and Install Nginx & Certbot
echo -e "${BLUE}[3/6] Проверка Nginx и Certbot...${NC}"
if ! command -v nginx &> /dev/null; then
    echo -e "  ${YELLOW}Nginx не найден. Устанавливаем Nginx...${NC}"
    apt-get install -y -q nginx
    systemctl enable nginx
    systemctl start nginx
    echo -e "  ${GREEN}[✓] Nginx установлен!${NC}"
else
    echo -e "  ${GREEN}[✓] Nginx уже установлен.${NC}"
fi

if ! command -v certbot &> /dev/null; then
    echo -e "  ${YELLOW}Certbot не найден. Устанавливаем Certbot...${NC}"
    apt-get install -y -q certbot python3-certbot-nginx
    echo -e "  ${GREEN}[✓] Certbot установлен!${NC}"
else
    echo -e "  ${GREEN}[✓] Certbot уже установлен.${NC}"
fi

# 4. Configuration (.env)
echo -e "${BLUE}[4/6] Настройка конфигурации проекта...${NC}"

# Default parameters
DEF_DOMAIN="pic-up.ae0.ru"
DEF_PORT="8088"
DEF_TOKEN="7396400534:AAE09x3-7JQpZKeMxPkIAVR1g9F_Hs3_S6c"
DEF_CHAT="-1003724858822"
DEF_MAX_MB="50"
DEF_EXPIRY="30"

if [ -f "$SCRIPT_DIR/.env" ]; then
    echo -e "  Используется существующий файл .env"
    DOMAIN=$(grep -E '^APP_URL=' .env | cut -d '=' -f2 | sed 's#https://##' | sed 's#http://##' | tr -d ' ' || echo "$DEF_DOMAIN")
    PORT=$(grep -E '^PORT=' .env | cut -d '=' -f2 | tr -d ' ' || echo "$DEF_PORT")
else
    echo -e "  Создание конфигурационного файла .env"
    DOMAIN="$DEF_DOMAIN"
    PORT="$DEF_PORT"
    
    cat <<EOF > "$SCRIPT_DIR/.env"
APP_URL=https://${DOMAIN}
HOST=0.0.0.0
PORT=${PORT}
TELEGRAM_BOT_TOKEN=${DEF_TOKEN}
TELEGRAM_CHAT_ID=${DEF_CHAT}
MAX_FILES=10
MAX_FILE_SIZE_MB=${DEF_MAX_MB}
EXPIRY_DAYS=${DEF_EXPIRY}
EOF
fi

# 5. Configure Nginx Reverse Proxy & SSL
echo -e "${BLUE}[5/6] Настройка Nginx reverse proxy для ${DOMAIN}...${NC}"

NGINX_CONF="/etc/nginx/sites-available/${DOMAIN}"

cat <<EOF > "$NGINX_CONF"
server {
    listen 80;
    listen [::]:80;
    server_name ${DOMAIN};

    client_max_body_size 100M;

    location / {
        proxy_pass http://127.0.0.1:${PORT};
        proxy_http_version 1.1;
        proxy_set_header Upgrade \$http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;

        # Video / Audio Range streaming & buffering
        proxy_buffering off;
        proxy_read_timeout 300s;
        proxy_send_timeout 300s;
    }
}
EOF

ln -sf "$NGINX_CONF" "/etc/nginx/sites-enabled/${DOMAIN}"
nginx -t && systemctl reload nginx

# Check/Issue SSL Certificate
if [ ! -d "/etc/letsencrypt/live/${DOMAIN}" ]; then
    echo -e "  ${YELLOW}Получение SSL сертификата Let's Encrypt для ${DOMAIN}...${NC}"
    # Verify domain resolves to this machine before certbot
    SERVER_IP=$(curl -s https://api.ipify.org || hostname -I | awk '{print $1}')
    echo -e "  IP сервера: ${SERVER_IP}"
    
    certbot --nginx -d "${DOMAIN}" --non-interactive --agree-tos --register-unsafely-without-email || {
        echo -e "${YELLOW}  [!] Не удалось выпустить SSL автоматически. Проверьте DNS запись А для ${DOMAIN}.${NC}"
    }
else
    echo -e "  ${GREEN}[✓] SSL сертификат для ${DOMAIN} уже существует, применяем к Nginx...${NC}"
    certbot --nginx -d "${DOMAIN}" --non-interactive --reinstall || true
fi

# 6. Build and Start Docker Containers
echo -e "${BLUE}[6/6] Сборка и запуск контейнеров Pic-up...${NC}"

mkdir -p "$SCRIPT_DIR/data/uploads/tmp" "$SCRIPT_DIR/data/uploads/cache"
chmod -R 777 "$SCRIPT_DIR/data"

docker compose down --remove-orphans || true
docker compose up -d --build

# Health check
echo -e "  Ожидание запуска сервиса..."
HEALTHY=false
for i in {1..15}; do
    if curl -s -f "http://127.0.0.1:${PORT}/health" > /dev/null; then
        HEALTHY=true
        break
    fi
    sleep 2
done

if [ "$HEALTHY" = true ]; then
    echo -e "\n${GREEN}==========================================================${NC}"
    echo -e "${GREEN}   🎉 Поздравляем! Pic-up v2.0 успешно развернут!        ${NC}"
    echo -e "${GREEN}==========================================================${NC}"
    echo -e "  🌐 Веб-сайт:             ${CYAN}https://${DOMAIN}${NC}"
    echo -e "  📖 Документация API:     ${CYAN}https://${DOMAIN}/api/docs${NC}"
    echo -e "  ⚡ Интерактивный Swagger: ${CYAN}https://${DOMAIN}/docs${NC}"
    echo -e "  📦 Статус контейнера:    ${GREEN}Работает (Healthy)${NC}"
    echo ""
    echo -e "  Полезные команды:"
    echo -e "    Логи:       ${YELLOW}docker compose logs -f${NC}"
    echo -e "    Перезапуск: ${YELLOW}docker compose restart${NC}"
    echo -e "    Остановка:  ${YELLOW}docker compose down${NC}"
    echo -e "${GREEN}==========================================================${NC}"
else
    echo -e "${RED}[✕] Контейнер запустился, но healthcheck пока не ответил.${NC}"
    echo -e "Проверьте логи командой: docker compose logs"
fi
