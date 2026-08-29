#!/bin/bash
# ============================================================
# DI PARMA | التحقق من وإصلاح كلمة مرور قاعدة البيانات
# ============================================================

SERVER_IP="65.2.184.57"
SERVER_USER="ubuntu"
SSH_KEY="${SSH_KEY:-$HOME/.ssh/diparma_lightsail.pem}"

ssh -i "${SSH_KEY}" "${SERVER_USER}@${SERVER_IP}" bash << 'REMOTE'

echo "=== التحقق من كلمة المرور الحالية ==="
sudo grep DB_PASS /var/www/diparma/.env

echo ""
echo "=== إصلاح كلمة المرور ==="
sudo sed -i "s/^DB_PASS=.*/DB_PASS=diparma_secure_2024/" /var/www/diparma/.env

echo ""
echo "=== التحقق من التحديث ==="
sudo grep DB_PASS /var/www/diparma/.env

echo ""
echo "=== اختبار الاتصال ==="
sudo mysql -u diparma_user -pdiparma_secure_2024 diparma_gateway -e "SELECT 'Connection OK' AS status;"

REMOTE

echo "✓ اكتمل الإصلاح!"
