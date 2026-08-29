#!/bin/bash
# ============================================================
# DI PARMA | fix_db.sh
# إصلاح مشاكل قاعدة البيانات
# ============================================================

set -e

GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m'

log()  { echo -e "${GREEN}[✓]${NC} $1"; }
warn() { echo -e "${YELLOW}[!]${NC} $1"; }
err()  { echo -e "${RED}[✗]${NC} $1"; exit 1; }

echo "============================================"
echo "  DI PARMA — إصلاح قاعدة البيانات"
echo "============================================"

if [ ! -f /root/diparma_db_credentials.txt ]; then
    err "ملف بيانات قاعدة البيانات غير موجود"
fi

source /root/diparma_db_credentials.txt

# ── 1. فحص الجداول ──────────────────────────────────────────
log "فحص الجداول..."
mysqlcheck -u "$DB_USER" -p"$DB_PASS" --check "$DB_NAME"

# ── 2. إصلاح الجداول ────────────────────────────────────────
log "إصلاح الجداول..."
mysqlcheck -u "$DB_USER" -p"$DB_PASS" --repair "$DB_NAME"

# ── 3. تحسين الجداول ────────────────────────────────────────
log "تحسين الجداول..."
mysqlcheck -u "$DB_USER" -p"$DB_PASS" --optimize "$DB_NAME"

# ── 4. إنشاء الجداول المفقودة ──────────────────────────────
log "إنشاء الجداول المفقودة..."
mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" <<SQL
CREATE TABLE IF NOT EXISTS dp_integrations LIKE diparma_gateway.dp_integrations;
CREATE TABLE IF NOT EXISTS dp_api_clients LIKE diparma_gateway.dp_api_clients;
CREATE TABLE IF NOT EXISTS dp_transactions LIKE diparma_gateway.dp_transactions;
SQL

log "✓ تم إصلاح قاعدة البيانات"

echo "============================================"
echo "  ✓ اكتمل الإصلاح"
echo "============================================"