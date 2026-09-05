#!/bin/bash
# ============================================================
# DI PARMA | deploy.sh
# نشر التطبيق بالكامل على السيرفر
# ============================================================

set -e

# ── الإعدادات ──────────────────────────────────────────────
APP_DIR="/var/www/html/DIPARMA40"
REPO_URL="https://github.com/MTCode-7/DIPARMA40.git"
BRANCH="main"

GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m'

log()  { echo -e "${GREEN}[✓]${NC} $1"; }
warn() { echo -e "${YELLOW}[!]${NC} $1"; }
err()  { echo -e "${RED}[✗]${NC} $1"; exit 1; }

echo "============================================"
echo "  DI PARMA — نشر التطبيق"
echo "============================================"

# ── [1] جلب الكود ──────────────────────────────────────────
log "جلب الكود من GitHub..."
if [ -d "$APP_DIR/.git" ]; then
    cd "$APP_DIR"
    git pull origin $BRANCH
else
    git clone -b $BRANCH "$REPO_URL" "$APP_DIR"
    cd "$APP_DIR"
fi

# ── [2] تثبيت PHP dependencies ────────────────────────────
log "تثبيت PHP dependencies..."
composer install --no-dev --optimize-autoloader

# ── [3] إعداد ملف .env ─────────────────────────────────────
log "إعداد ملف .env..."
if [ ! -f "$APP_DIR/.env" ]; then
    cp "$APP_DIR/.env.example" "$APP_DIR/.env"
    warn "ملف .env تم إنشاؤه. قم بتعديله يدوياً!"
fi

# مزامنة اتصال قاعدة الإنتاج من ملف الاعتماد المحمي.
if [ -f /root/diparma_db_credentials.txt ]; then
    source /root/diparma_db_credentials.txt
    sed -i "s|^DB_NAME=.*|DB_NAME=${DB_NAME}|" "$APP_DIR/.env"
    sed -i "s|^DB_USER=.*|DB_USER=${DB_USER}|" "$APP_DIR/.env"
    sed -i "s|^DB_PASS=.*|DB_PASS=${DB_PASS}|" "$APP_DIR/.env"
    chmod 640 "$APP_DIR/.env"
    log "تمت مزامنة بيانات اتصال قاعدة الإنتاج"
else
    warn "ملف /root/diparma_db_credentials.txt غير موجود؛ لا يمكن تغيير اتصال قاعدة البيانات تلقائياً"
fi

# ── [4] إنشاء مجلدات السجلات ──────────────────────────────
log "إنشاء مجلدات السجلات..."
mkdir -p "$APP_DIR/logs" "$APP_DIR/cache" "$APP_DIR/tmp"
chmod 777 "$APP_DIR/logs" "$APP_DIR/cache" "$APP_DIR/tmp"

# ── [5] تهيئة قاعدة البيانات ──────────────────────────────
log "تهيئة قاعدة البيانات..."
if [ -f "$APP_DIR/setup_database.sql" ]; then
    if [ -f /root/diparma_db_credentials.txt ]; then
        source /root/diparma_db_credentials.txt
        mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" < "$APP_DIR/setup_database.sql"
        log "تم تهيئة قاعدة البيانات"
    fi
fi

# ── [6] إعداد الصلاحيات ────────────────────────────────────
log "إعداد الصلاحيات..."
chown -R www-data:www-data "$APP_DIR"
chmod -R 755 "$APP_DIR"
chmod -R 777 "$APP_DIR/logs" "$APP_DIR/cache" "$APP_DIR/tmp"

# ── [7] إعادة تشغيل الخدمات ────────────────────────────────
log "إعادة تشغيل الخدمات..."
systemctl reload nginx
systemctl reload php8.3-fpm
supervisorctl restart all

echo ""
echo "============================================"
echo "  ✓ تم نشر التطبيق بنجاح"
echo "============================================"