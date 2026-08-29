#!/bin/bash
# ============================================================
# DI PARMA | Upload connection manager files
# ============================================================

SERVER_IP="65.2.184.57"
SERVER_USER="ubuntu"
SSH_KEY="${SSH_KEY:-$HOME/.ssh/diparma_lightsail.pem}"

echo "رفع ملفات إدارة الاتصال..."

# رفع الملفات
scp -i "${SSH_KEY}" admin/connection_manager.php ubuntu@65.2.184.57:/tmp/
scp -i "${SSH_KEY}" admin/add_connection_data.php ubuntu@65.2.184.57:/tmp/

# نقل الملفات إلى المجلد الصحيح
ssh -i "${SSH_KEY}" "${SERVER_USER}@${SERVER_IP}" bash << 'REMOTE'
    echo "نقل الملفات إلى المجلد الصحيح..."
    sudo mv /tmp/connection_manager.php /var/www/diparma/admin/
    sudo mv /tmp/add_connection_data.php /var/www/diparma/admin/
    sudo chown www-data:www-data /var/www/diparma/admin/connection_manager.php
    sudo chown www-data:www-data /var/www/diparma/admin/add_connection_data.php
    sudo chmod 644 /var/www/diparma/admin/connection_manager.php
    sudo chmod 644 /var/www/diparma/admin/add_connection_data.php
    
    echo "التحقق من الملفات..."
    ls -la /var/www/diparma/admin/connection_manager.php /var/www/diparma/admin/add_connection_data.php
REMOTE

echo "✓ اكتمل الرفع!"
