#!/bin/bash
# ============================================================
# DI PARMA | رفع التعديلات إلى السيرفر
# ============================================================
SERVER_PATH="/var/www/html/DIPARMA40"

FILES=(
  ".env"
  ".env.production"
  ".htaccess"
  "includes/config.php"
  "includes/auth_check.php"
  "includes/database.php"
  "includes/gateways.php"
  "includes/lang.php"
  "includes/limits.php"
  "lib/PayRamAdapter.php"
  "lib/PaymentOrchestrator.php"
  "lib/Adapters/GatewayAdapterFactory.php"
  "lib/ISO8583/FieldDefinitions.php"
  "lib/ISO8583/Message.php"
  "lib/ISO8583/Processor.php"
  "api/payram_payment.php"
  "api/payram_webhook.php"
  "api/check_transaction.php"
  "api/create_txn.php"
  "api/direct_payment.php"
  "api/nuvei_create_txn.php"
  "api/offline_approve.php"
  "api/pos_ledger_transfer.php"
  "api/pos_transaction.php"
  "api/bin_lookup.php"
  "api/v1/ApiAuth.php"
  "api/v1/charge.php"
  "api/v1/diparma_charge.php"
  "api/v1/docs.php"
  "api/v1/index.php"
  "api/v1/transactions.php"
  "api/v1/schema.sql"
  "admin/add_connection_data.php"
  "admin/api_dashboard.php"
  "admin/connection_manager.php"
  "admin/gateway_manager.php"
  "admin/iso8583_monitor.php"
  "admin/user_approvals.php"
  "checkout/nuvei.php"
  "checkout/paypal.php"
  "checkout/payram.php"
  "checkout/stripe.php"
  "checkout/whop.php"
  "checkout_diparma.php"
  "checkout_ledger.php"
  "checkout_payram.php"
  "checkout_router.php"
  "checkout_template.php"
  "index.php"
  "landing.php"
  "login.php"
  "logout.php"
  "pay.php"
  "process_live_payment.php"
  "receipt.php"
  "register.php"
  "final_status.php"
  "webhook_test_ui.php"
  "protocols/families/CardProtocol.php"
  "scripts/seed_integrations.php"
  "setup_database.sql"
  "deploy/integrations.sql"
  "deploy/migrate_db.sh"
  "deploy/fix_db.sh"
)

echo "=== رفع الملفات إلى $SERVER_PATH ==="
OK=0; FAIL=0
for f in "${FILES[@]}"; do
  SRC="$SERVER_PATH/$f"
  if [ -f "$SRC" ]; then
    echo "✓ موجود: $f"
    ((OK++))
  else
    echo "✗ مفقود: $f"
    ((FAIL++))
  fi
done
echo ""
echo "✓ موجود: $OK | ✗ مفقود: $FAIL"
echo ""
echo "=== ضبط الصلاحيات ==="
chmod 644 $SERVER_PATH/.env
chmod 644 $SERVER_PATH/.env.production
find $SERVER_PATH -name "*.php" -exec chmod 644 {} \;
find $SERVER_PATH/deploy -name "*.sh" -exec chmod 755 {} \;
echo "✓ الصلاحيات مضبوطة"

echo ""
echo "=== مسح Cache ==="
find $SERVER_PATH/cache -type f -delete 2>/dev/null
find $SERVER_PATH/tmp -type f -delete 2>/dev/null
echo "✓ Cache ممسوح"

echo ""
echo "=== إعادة تشغيل PHP-FPM ==="
systemctl reload php8.2-fpm 2>/dev/null || systemctl reload php8.1-fpm 2>/dev/null || systemctl reload php-fpm 2>/dev/null || service php8.2-fpm reload 2>/dev/null
echo "✓ PHP-FPM أُعيد تشغيله"

echo ""
echo "✅ اكتمل الرفع"
