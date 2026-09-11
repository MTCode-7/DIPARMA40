#!/bin/bash
# يكتشف المجلد الذي يخدم diparmas.com وينشر إليه، مع نسخة احتياطية كاملة.
set -euo pipefail

STAMP=$(date +%F-%H%M%S)
CANDIDATES="/var/www/html/DIPARMA40 /var/www/diparma"
LIVE=""

echo "== 1/7 جذر الموقع الحي =="
VHOST=$(sudo grep -R --include='*.conf' -E 'diparmas\.com|DocumentRoot|root ' /etc/apache2 /etc/nginx /etc/httpd 2>/dev/null | head -80 || true)
echo "$VHOST" | sed 's/^/   /'
for D in $CANDIDATES; do
  if echo "$VHOST" | grep -q "$D"; then
    LIVE="$D"
    break
  fi
done
if [ -z "$LIVE" ]; then
  # إن تعذّر الاستنتاج من الـ vhost: المجلد الذي يملك checkout/paypal.php الأحدث ليس كافياً —
  # نفضّل مسار النشر التاريخي إن وُجد.
  if [ -d /var/www/html/DIPARMA40 ]; then
    LIVE="/var/www/html/DIPARMA40"
  else
    LIVE="/var/www/diparma"
  fi
fi
echo "   المجلد المستهدف: $LIVE"

echo
echo "== 2/7 حالة المجلدين =="
for D in $CANDIDATES; do
  [ -d "$D" ] || { echo "   $D غير موجود"; continue; }
  HEAD="(ليس git)"
  if [ -d "$D/.git" ]; then
    HEAD=$(sudo git -C "$D" log --oneline -1 2>/dev/null || echo "?")
  fi
  HAS="لا"
  [ -f "$D/lib/Adapters/PayPalAdapter.php" ] && HAS="نعم"
  MARK="لا"
  grep -q "Invalid server response" "$D/checkout/paypal.php" 2>/dev/null && MARK="نعم"
  echo "   $D"
  echo "     HEAD=$HEAD  PayPalAdapter=$HAS  checkout-جديد=$MARK"
done

deploy_dir() {
  local APP="$1"
  echo
  echo "== نشر إلى $APP =="
  if [ ! -d "$APP" ]; then
    echo "   تخطي — المجلد غير موجود"
    return 0
  fi

  sudo tar czf "/root/$(basename "$APP")-tree-$STAMP.tar.gz" -C "$(dirname "$APP")" "$(basename "$APP")" 2>/dev/null || true
  echo "   نسخة احتياطية: /root/$(basename "$APP")-tree-$STAMP.tar.gz"

  if [ -d "$APP/.git" ]; then
    sudo git config --global --add safe.directory "$APP" >/dev/null 2>&1 || true
    local BRANCH="server-snapshot-$STAMP"
    if ! sudo git -C "$APP" diff --quiet || ! sudo git -C "$APP" diff --cached --quiet || [ -n "$(sudo git -C "$APP" ls-files --others --exclude-standard)" ]; then
      sudo git -C "$APP" checkout -B "$BRANCH"
      sudo git -C "$APP" add -A
      sudo git -C "$APP" -c user.name="server-snapshot" -c user.email="ops@diparma.local" \
        commit -q -m "Snapshot before live deploy $STAMP" || true
      echo "   فُرّغت التعديلات المحلية في $BRANCH"
      sudo git -C "$APP" checkout main 2>/dev/null || sudo git -C "$APP" checkout master
    fi
    sudo git -C "$APP" fetch origin main
    sudo git -C "$APP" merge --ff-only origin/main
  elif [ -d /var/www/diparma/.git ]; then
    echo "   ليس git — نسخ الكود من /var/www/diparma مع استثناء .env والسجلات"
    sudo rsync -a --delete \
      --exclude '.env' --exclude 'logs/' --exclude 'cache/' --exclude 'tmp/' --exclude 'storage/' \
      /var/www/diparma/ "$APP/"
  else
    echo "   خطأ: لا git هنا ولا مصدر للنسخ"
    return 1
  fi

  if [ -f /var/www/diparma/.env ] && [ "$APP" != /var/www/diparma ]; then
    if [ -f "$APP/.env" ]; then
      sudo cp "$APP/.env" "/root/env-$(basename "$APP")-$STAMP.txt"
      local BACKUP="/root/env-$(basename "$APP")-$STAMP.txt"
      sudo cp /var/www/diparma/.env "$APP/.env"
      for K in DB_HOST DB_NAME DB_USER DB_PASS ENCRYPTION_KEY; do
        sudo sed -i "/^${K}=/d" "$APP/.env"
        LINE=$(sudo grep -m1 -E "^${K}=" "$BACKUP" || true)
        if [ -n "$LINE" ]; then
          printf '%s\n' "$LINE" | sudo tee -a "$APP/.env" >/dev/null
          echo "   حُفظ $K من .env القديم لهذا المجلد"
        fi
      done
    else
      sudo cp /var/www/diparma/.env "$APP/.env"
      echo "   نُسخ .env من /var/www/diparma"
    fi
    sudo chown www-data:www-data "$APP/.env"
    sudo chmod 640 "$APP/.env"
  fi

  sudo chown -R www-data:www-data "$APP"
}

# انشر للمجلد الحي أولاً، ثم للمجلد الآخر حتى لا يتكرر هذا الخطأ
deploy_dir "$LIVE"
for D in $CANDIDATES; do
  [ "$D" = "$LIVE" ] && continue
  deploy_dir "$D"
done

echo
echo "== 6/7 إعادة تحميل الخدمات =="
sudo systemctl reload php8.3-fpm 2>/dev/null \
  || sudo systemctl reload php8.2-fpm 2>/dev/null \
  || sudo systemctl reload php8.1-fpm 2>/dev/null \
  || sudo systemctl reload php-fpm 2>/dev/null \
  || echo "   php-fpm: تعذّر أو غير مستخدم"
sudo systemctl reload apache2 2>/dev/null || sudo systemctl reload httpd 2>/dev/null || true
sudo systemctl reload nginx 2>/dev/null || true

echo
echo "== 7/7 التحقق =="
echo "   المجلد الحي: $LIVE"
echo -n "   HEAD الحي: "
if [ -d "$LIVE/.git" ]; then sudo git -C "$LIVE" log --oneline -1; else echo "(ليس git)"; fi
grep -q "Invalid server response" "$LIVE/checkout/paypal.php" \
  && echo "   ✅ checkout/paypal.php محدّث" || echo "   ❌ checkout/paypal.php ما زال قديماً"
[ -f "$LIVE/lib/Adapters/PayPalAdapter.php" ] \
  && echo "   ✅ PayPalAdapter.php موجود" || echo "   ❌ PayPalAdapter.php مفقود"
grep -q "PayPalAdapter::class" "$LIVE/lib/Adapters/GatewayAdapterFactory.php" \
  && echo "   ✅ المصنع يوجّه paypal إلى PayPalAdapter" || echo "   ❌ التوجيه غير مطبَّق"
for K in PAYPAL_CLIENT_ID PAYPAL_SECRET PAYPAL_CLIENT_SECRET; do
  if sudo grep -q "^${K}=." "$LIVE/.env" 2>/dev/null; then
    echo "   ✅ .env يحوي $K"
  fi
done
echo
echo "للتراجع: sudo git -C $LIVE checkout server-snapshot-$STAMP"
