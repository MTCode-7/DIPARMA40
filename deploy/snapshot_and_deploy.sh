#!/bin/bash
# يحفظ حالة السيرفر الحالية في فرع git ونسخة tar، ثم ينشر آخر كود.
# كل ما يُستبدل يبقى قابلاً للاسترجاع بالكامل.
set -e
APP="${APP:-/var/www/diparma}"
STAMP=$(date +%F-%H%M%S)
BRANCH="server-snapshot-$STAMP"
G="sudo git -C $APP"

cd "$APP" || { echo "خطأ: $APP غير موجود"; exit 1; }

echo "== 1/6 نسخة احتياطية كاملة للمجلد =="
sudo tar czf "/root/diparma-tree-$STAMP.tar.gz" -C /var/www diparma 2>/dev/null || true
echo "   /root/diparma-tree-$STAMP.tar.gz ($(sudo du -h /root/diparma-tree-$STAMP.tar.gz | cut -f1))"

echo "== 2/6 حفظ فروق السيرفر كملف patch =="
$G diff > /tmp/local.patch 2>/dev/null || true
sudo cp /tmp/local.patch "/root/server-local-changes-$STAMP.patch"
echo "   /root/server-local-changes-$STAMP.patch ($(wc -l < /tmp/local.patch) سطر)"

echo "== 3/6 تجميد حالة السيرفر في فرع git =="
$G checkout -b "$BRANCH"
$G add -A
$G -c user.name="server-snapshot" -c user.email="ops@diparma.local" \
   commit -q -m "Snapshot of production working tree before deploying latest code" || echo "   (لا جديد لحفظه)"
echo "   الفرع: $BRANCH"

echo "== 4/6 العودة إلى main وسحب آخر كود =="
$G checkout main
$G fetch origin main
$G merge --ff-only origin/main

echo "== 5/6 ضبط الملكية وإعادة تحميل PHP =="
sudo chown -R www-data:www-data "$APP"
sudo systemctl reload "php$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')-fpm" 2>/dev/null \
  || sudo systemctl reload php8.1-fpm 2>/dev/null \
  || sudo systemctl reload php-fpm 2>/dev/null || echo "   (تعذّرت إعادة التحميل — تجاهل إن كان apache mod_php)"
sudo systemctl reload apache2 2>/dev/null || sudo systemctl reload nginx 2>/dev/null || true

echo "== 6/6 التحقق =="
echo -n "   HEAD: "; $G log --oneline -1
[ -f "$APP/lib/Adapters/PayPalAdapter.php" ] && echo "   ✅ PayPalAdapter.php موجود" || echo "   ❌ PayPalAdapter.php مفقود"
grep -q "PayPalAdapter::class" "$APP/lib/Adapters/GatewayAdapterFactory.php" \
  && echo "   ✅ المصنع يوجّه paypal إلى PayPalAdapter" || echo "   ❌ التوجيه غير مطبَّق"
for K in DB_PASS ENCRYPTION_KEY PAYPAL_CLIENT_ID PAYPAL_SECRET; do
  sudo grep -q "^${K}=." "$APP/.env" && echo "   ✅ .env يحوي $K" || echo "   ❌ .env ينقصه $K"
done
echo
echo "للتراجع الكامل:  sudo git -C $APP checkout $BRANCH"
