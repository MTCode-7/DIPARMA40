#!/bin/bash
# ============================================================
# DI PARMA | migrate_db.sh
# تصدير قاعدة البيانات المحلية ورفعها على السيرفر
# ============================================================
# التشغيل:
#   bash deploy/migrate_db.sh
# ============================================================

set -e

# ── الإعدادات — عدّل هذه القيم ──────────────────────────────
SERVER_IP="65.2.184.57"
SERVER_USER="ubuntu"
SSH_KEY="$HOME/.ssh/diparma_lightsail.pem"

# قاعدة البيانات المحلية (XAMPP)
LOCAL_DB_HOST="localhost"
LOCAL_DB_PORT="3306"
LOCAL_DB_NAME="diparma_gateway"
LOCAL_DB_USER="root"
LOCAL_DB_PASS=""

# مسار mysqldump على Windows/XAMPP
MYSQLDUMP="C:/xampp/mysql/bin/mysqldump.exe"

# مسار حفظ الملف
DUMP_FILE="$(dirname "$0")/backups/diparma_$(date +%Y%m%d_%H%M%S).sql"

# ── الألوان ──────────────────────────────────────────────────
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m'

log()  { echo -e "${GREEN}[✓]${NC} $1"; }
warn() { echo -e "${YELLOW}[!]${NC} $1"; }
err()  { echo -e "${RED}[✗]${NC} $1"; exit 1; }

echo "============================================"
echo "  DI PARMA — نقل قاعدة البيانات"
echo "============================================"

# ── إنشاء مجلد النسخ الاحتياطية ─────────────────────────────
mkdir -p "$(dirname "$DUMP_FILE")"

# ── [1] تصدير DB المحلية ────────────────────────────────────
log "تصدير قاعدة البيانات المحلية..."

# دعم XAMPP على Windows
if [ -f "$MYSQLDUMP" ]; then
    DUMP_CMD="$MYSQLDUMP"
elif command -v mysqldump &>/dev/null; then
    DUMP_CMD="mysqldump"
else
    err "mysqldump غير موجود. تأكد من تشغيل XAMPP أو تثبيت MySQL client"
}

if [ -z "$LOCAL_DB_PASS" ]; then
    "$DUMP_CMD" \
        -h "$LOCAL_DB_HOST" \
        -P "$LOCAL_DB_PORT" \
        -u "$LOCAL_DB_USER" \
        --single-transaction \
        --routines \
        --triggers \
        --add-drop-table \
        --complete-insert \
        "$LOCAL_DB_NAME" > "$DUMP_FILE"
else
    "$DUMP_CMD" \
        -h "$LOCAL_DB_HOST" \
        -P "$LOCAL_DB_PORT" \
        -u "$LOCAL_DB_USER" \
        -p"$LOCAL_DB_PASS" \
        --single-transaction \
        --routines \
        --triggers \
        --add-drop-table \
        --complete-insert \
        "$LOCAL_DB_NAME" > "$DUMP_FILE"
fi

DUMP_SIZE=$(du -sh "$DUMP_FILE" | cut -f1)
log "تم التصدير: $DUMP_FILE ($DUMP_SIZE)"

# ── [2] رفع ملف SQL للسيرفر ─────────────────────────────────
log "رفع الملف للسيرفر..."
[ ! -f "$SSH_KEY" ] && err "مفتاح SSH غير موجود: $SSH_KEY"
chmod 400 "$SSH_KEY"

scp -i "${SSH_KEY}" \
    "${DUMP_FILE}" \
    "${SERVER_USER}@${SERVER_IP}:/tmp/diparma_import.sql"

# ── [3] استيراد DB على السيرفر ──────────────────────────────
log "استيراد قاعدة البيانات على السيرفر..."
ssh -i "${SSH_KEY}" "${SERVER_USER}@${SERVER_IP}" bash <<'REMOTE'
    if [ ! -f /root/diparma_db_credentials.txt ]; then
        echo "✗ لم يُعثر على /root/diparma_db_credentials.txt"
        echo "  شغّل setup_server.sh أولاً"
        exit 1
    fi

    source /root/diparma_db_credentials.txt

    echo "→ استيراد البيانات في ${DB_NAME}..."
    mysql -u root "${DB_NAME}" < /tmp/diparma_import.sql
    echo "✓ تم الاستيراد بنجاح"

    rm -f /tmp/diparma_import.sql
    echo "✓ حُذف الملف المؤقت"
REMOTE

# ── [4] حذف الملف المحلي المؤقت ────────────────────────────
warn "هل تريد حذف ملف SQL المحلي؟ [y/N]"
read -r answer
if [[ "$answer" =~ ^[Yy]$ ]]; then
    rm -f "$DUMP_FILE"
    log "حُذف: $DUMP_FILE"
else
    warn "محفوظ: $DUMP_FILE"
fi

echo ""
echo "============================================"
echo "  ✓ قاعدة البيانات منقولة بنجاح"
echo "============================================"