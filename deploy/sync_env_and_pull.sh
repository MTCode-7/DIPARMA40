#!/bin/bash
# ============================================================
# DI PARMA | تثبيت .env المرفوع + سحب آخر كود
# ============================================================
# يُرفع إلى /tmp ثم يُنفَّذ على السيرفر:
#   scp .env                      ubuntu@HOST:/tmp/env.new
#   scp deploy/sync_env_and_pull.sh ubuntu@HOST:/tmp/
#   ssh ubuntu@HOST "bash /tmp/sync_env_and_pull.sh"
#
# مفاتيح خاصة بالسيرفر تُستعاد من النسخة القديمة، لأن استبدالها
# يغيّر عناوين المحافظ المُشتقّة ويُبطل مفاتيح الـ API.
# ============================================================
set -e

APP="${APP:-/var/www/diparma}"
NEW_ENV="/tmp/env.new"
BACKUP="/root/env-backup-latest.txt"
PRESERVE="DB_HOST DB_NAME DB_USER DB_PASS ENCRYPTION_KEY"

echo "== مجلد التطبيق: $APP =="
if [ ! -d "$APP" ]; then
  echo "خطأ: $APP غير موجود. أعد التنفيذ مع APP=/المسار/الصحيح"
  exit 1
fi
if [ ! -f "$NEW_ENV" ]; then
  echo "خطأ: $NEW_ENV غير مرفوع. نفّذ خطوة scp أولاً"
  exit 1
fi

echo "== نسخة احتياطية من .env الحالي =="
sudo cp "$APP/.env" "/root/env-backup-$(date +%F-%H%M%S).txt"
sudo cp "$APP/.env" "$BACKUP"

echo "== تثبيت الملف المرفوع =="
sudo cp "$NEW_ENV" "$APP/.env"

echo "== استعادة مفاتيح السيرفر =="
for K in $PRESERVE; do
  sudo sed -i "/^${K}=/d" "$APP/.env"
  LINE=$(sudo grep -m1 -E "^${K}=" "$BACKUP" || true)
  if [ -n "$LINE" ]; then
    printf '%s\n' "$LINE" | sudo tee -a "$APP/.env" >/dev/null
    echo "   تم الحفاظ على $K"
  else
    echo "   $K غير موجود في النسخة القديمة"
  fi
done

sudo chown www-data:www-data "$APP/.env"
sudo chmod 640 "$APP/.env"
rm -f "$NEW_ENV"

echo "== سحب آخر كود =="
sudo git config --global --add safe.directory "$APP" >/dev/null 2>&1 || true
sudo git -C "$APP" pull origin main

echo "== إعادة تحميل PHP-FPM =="
sudo systemctl reload php8.3-fpm 2>/dev/null \
  || sudo systemctl reload php8.2-fpm 2>/dev/null \
  || sudo systemctl reload php-fpm 2>/dev/null \
  || echo "   أعد تحميل php-fpm يدوياً"

echo "== التحقق =="
sudo git -C "$APP" log --oneline -1
if [ -f "$APP/lib/Adapters/PayPalAdapter.php" ]; then
  echo "   PayPalAdapter.php موجود"
else
  echo "   تحذير: PayPalAdapter.php مفقود — كل عمليات البطاقات ستفشل"
fi
echo "== اكتمل =="
