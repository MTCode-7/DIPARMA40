#!/bin/bash
# ============================================================
# DI PARMA | final_check.sh
# فحص نهائي بعد النشر للتأكد من صحة كل شيء
# ============================================================

set -e

GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m'

log()  { echo -e "${GREEN}[✓]${NC} $1"; }
warn() { echo -e "${YELLOW}[!]${NC} $1"; }
err()  { echo -e "${RED}[✗]${NC} $1"; }

echo "============================================"
echo "  DI PARMA — الفحص النهائي"
echo "============================================"

# ── 1. فحص الخدمات ──────────────────────────────────────────
echo ""
echo "📌 فحص الخدمات:"
for service in nginx php8.3-fpm mysql redis supervisor; do
    if systemctl is-active --quiet $service; then
        log "$service: ✅ نشط"
    else
        err "$service: ❌ غير نشط"
    fi
done

# ── 2. فحص مجلدات التطبيق ──────────────────────────────────
echo ""
echo "📌 فحص مجلدات التطبيق:"
for dir in /var/www/diparma /var/www/diparma/logs /var/www/diparma/cache; do
    if [ -d "$dir" ]; then
        log "$dir: ✅ موجود"
    else
        err "$dir: ❌ غير موجود"
    fi
done

# ── 3. فحص الصلاحيات ────────────────────────────────────────
echo ""
echo "📌 فحص الصلاحيات:"
if [ -w "/var/www/diparma/logs" ]; then
    log "logs: ✅ قابل للكتابة"
else
    err "logs: ❌ غير قابل للكتابة"
fi

# ── 4. فحص قاعدة البيانات ──────────────────────────────────
echo ""
echo "📌 فحص قاعدة البيانات:"
if [ -f /root/diparma_db_credentials.txt ]; then
    source /root/diparma_db_credentials.txt
    if mysql -u "$DB_USER" -p"$DB_PASS" -e "USE $DB_NAME;" 2>/dev/null; then
        log "قاعدة البيانات: ✅ متصلة"
    else
        err "قاعدة البيانات: ❌ غير متصلة"
    fi
else
    err "ملف بيانات قاعدة البيانات غير موجود"
fi

# ── 5. فحص SSL ──────────────────────────────────────────────
echo ""
echo "📌 فحص SSL:"
if certbot certificates 2>/dev/null | grep -q "diparmas.com"; then
    log "SSL: ✅ مثبت"
else
    warn "SSL: ⚠️ غير مثبت (شغّل ssl_setup.sh)"
fi

# ── 6. فحص الموقع ───────────────────────────────────────────
echo ""
echo "📌 فحص الموقع:"
if curl -s -o /dev/null -w "%{http_code}" https://diparmas.com | grep -q "200"; then
    log "الموقع: ✅ يعمل"
else
    warn "الموقع: ⚠️ لا يستجيب"
fi

echo ""
echo "============================================"
echo "  ✓ اكتمل الفحص النهائي"
echo "============================================"