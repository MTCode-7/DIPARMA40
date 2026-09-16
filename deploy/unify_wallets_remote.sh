#!/bin/bash
set -euo pipefail
STAMP=$(date +%F-%H%M%S)
for APP in /var/www/html/DIPARMA40 /var/www/diparma; do
  echo "-- $APP"
  sudo cp "$APP/.env" "/root/env-wallet-backup-$(basename "$APP")-${STAMP}.txt"
  sudo php /tmp/merge_env_keys.php "$APP/.env" /tmp/diparma_wallet_overlay.env
  sudo grep -E '^LEDGER_TRC20_ADDRESS=' "$APP/.env"
  sudo grep -E '^HOT_WALLET_TRC20_ADDRESS=' "$APP/.env"
  sudo grep -E '^COLD_WALLET_TRC20_ADDRESS=' "$APP/.env" || true
  sudo grep -E '^GATEWAY_FEE_MULTIPLIER=' "$APP/.env" || true
  sudo grep -E '^SETTLEMENT_ASSET=' "$APP/.env" || true
  sudo cp /tmp/pos_index.php "$APP/pos/index.php"
  sudo chown www-data:www-data "$APP/.env" "$APP/pos/index.php"
  sudo chmod 640 "$APP/.env"
done
sudo systemctl reload php8.3-fpm 2>/dev/null || sudo systemctl reload php8.2-fpm 2>/dev/null || true
rm -f /tmp/diparma_wallet_overlay.env /tmp/merge_env_keys.php /tmp/pos_index.php /tmp/unify_wallets.sh
echo WALLET_UNIFY_OK
