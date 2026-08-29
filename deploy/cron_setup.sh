#!/bin/bash
# ============================================================
# DI PARMA | cron_setup.sh
# إعداد المهام المجدولة (Cron Jobs)
# ============================================================

set -e

echo "============================================"
echo "  DI PARMA — إعداد المهام المجدولة"
echo "============================================"

# ── إضافة مهام Cron ──────────────────────────────────────────

# 1. عمل نسخة احتياطية يومية
(crontab -l 2>/dev/null || echo "") | cat - <(echo "0 2 * * * /var/www/diparma/deploy/backup.sh") | crontab -

# 2. تنظيف السجلات القديمة أسبوعياً
(crontab -l 2>/dev/null || echo "") | cat - <(echo "0 3 * * 0 find /var/www/diparma/logs -name '*.log' -mtime +30 -delete") | crontab -

# 3. تحديث أسعار الصرف كل ساعة
(crontab -l 2>/dev/null || echo "") | cat - <(echo "0 * * * * php /var/www/diparma/api/update_rates.php") | crontab -

# 4. التحقق من صحة النظام كل 10 دقائق
(crontab -l 2>/dev/null || echo "") | cat - <(echo "*/10 * * * * /var/www/diparma/deploy/health_check.sh") | crontab -

# 5. تجديد SSL كل يوم
(crontab -l 2>/dev/null || echo "") | cat - <(echo "0 0 * * * certbot renew --quiet && systemctl reload nginx") | crontab -

# 6. تنظيف قاعدة البيانات (حذف المعاملات المعلقة القديمة)
(crontab -l 2>/dev/null || echo "") | cat - <(echo "0 4 * * * php /var/www/diparma/api/cleanup_pending.php") | crontab -

echo "✓ تم إعداد المهام المجدولة"
echo ""
echo "📌 المهام المضافة:"
echo "   2:00  → نسخة احتياطية يومية"
echo "   3:00  → تنظيف السجلات (أسبوعياً)"
echo "   *:00  → تحديث أسعار الصرف (كل ساعة)"
echo "   */10  → فحص صحة النظام (كل 10 دقائق)"
echo "   0:00  → تجديد SSL (يومياً)"
echo "   4:00  → تنظيف المعاملات المعلقة (يومياً)"
echo "============================================"