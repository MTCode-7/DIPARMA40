#!/bin/bash
# نشر الكود الحالي إلى كلا مجلدي السيرفر بعد commit+push على main.
# الاستخدام من السيرفر: bash /tmp/pull_both_trees.sh
set -euo pipefail
STAMP=$(date +%F-%H%M%S)

pull_one() {
  local APP="$1"
  [ -d "$APP/.git" ] || { echo "تخطي $APP (ليس git)"; return 0; }
  echo "== $APP =="
  sudo git config --global --add safe.directory "$APP" >/dev/null 2>&1 || true
  if ! sudo git -C "$APP" diff --quiet || ! sudo git -C "$APP" diff --cached --quiet \
     || [ -n "$(sudo git -C "$APP" ls-files --others --exclude-standard 2>/dev/null)" ]; then
    local BR="server-snapshot-$STAMP"
    sudo git -C "$APP" checkout -B "$BR" 2>/dev/null || true
    sudo git -C "$APP" add -A
    sudo git -C "$APP" -c user.name="server-snapshot" -c user.email="ops@diparma.local" \
      commit -q -m "Snapshot before pull $STAMP" || true
    sudo git -C "$APP" checkout main 2>/dev/null || sudo git -C "$APP" checkout master
    echo "   حفظت التعديلات المحلية في $BR"
  fi
  sudo git -C "$APP" fetch origin main
  sudo git -C "$APP" merge --ff-only origin/main
  sudo chown -R www-data:www-data "$APP"
  echo -n "   HEAD: "; sudo git -C "$APP" log --oneline -1
  [ -f "$APP/lib/Adapters/PayPalAdapter.php" ] && echo "   PayPalAdapter OK" || echo "   PayPalAdapter MISSING"
  [ -f "$APP/includes/api_guard.php" ] && echo "   api_guard OK" || echo "   api_guard MISSING"
}

pull_one /var/www/diparma
pull_one /var/www/html/DIPARMA40

sudo systemctl reload php8.3-fpm 2>/dev/null \
  || sudo systemctl reload php8.2-fpm 2>/dev/null \
  || sudo systemctl reload php8.1-fpm 2>/dev/null \
  || true
sudo systemctl reload apache2 2>/dev/null || sudo systemctl reload nginx 2>/dev/null || true
echo "== اكتمل =="
