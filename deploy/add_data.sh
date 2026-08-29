#!/bin/bash
# ============================================================
# DI PARMA | إصلاح كلمة مرور قاعدة البيانات والإضافة
# ============================================================

SERVER_IP="65.2.184.57"
SERVER_USER="ubuntu"
SSH_KEY="${SSH_KEY:-$HOME/.ssh/diparma_lightsail.pem}"

ssh -i "${SSH_KEY}" "${SERVER_USER}@${SERVER_IP}" bash << 'REMOTE'

# 1. إنشاء مستخدم جديد أو تحديث كلمة مرور
echo "إصلاح بيانات MySQL..."
sudo mysql -u root << MYSQL
DROP USER IF EXISTS 'diparma_user'@'localhost';
CREATE USER 'diparma_user'@'localhost' IDENTIFIED BY 'diparma_secure_2024';
GRANT ALL PRIVILEGES ON diparma_gateway.* TO 'diparma_user'@'localhost' WITH GRANT OPTION;
FLUSH PRIVILEGES;
MYSQL

# 2. تحديث ملف .env
echo "تحديث ملف .env..."
sudo sed -i "s/^DB_PASS=.*/DB_PASS=diparma_secure_2024/" /var/www/diparma/.env

# 3. إضافة البيانات عن طريق MySQL
echo "إضافة بيانات الاتصالات..."

# البنوك
mysql -u diparma_user -pdiparma_secure_2024 diparma_gateway << 'MYSQL_DATA'
INSERT IGNORE INTO dp_payment_gateways (code, name, type, status, config, credentials, settings, connection_type, gateway_type, sort_order) VALUES
('bank_001', 'Stripe Bank Transfer', 'bank', 'active', '{"currencies":["USD"],"fees":{"percentage":2.5}}', '{"api_key":"demo_key_1"}', '{}', 'rest', 'bank', 1),
('bank_002', 'PayPal Bank', 'bank', 'active', '{"currencies":["USD"]}', '{"api_key":"demo_key_2"}', '{}', 'rest', 'bank', 2),
('bank_003', 'Square Bank', 'bank', 'inactive', '{"currencies":["USD"]}', '{"api_key":"demo_key_3"}', '{}', 'rest', 'bank', 3),
('bank_004', '2Checkout Bank', 'bank', 'active', '{"currencies":["USD"]}', '{"api_key":"demo_key_4"}', '{}', 'rest', 'bank', 4),
('bank_005', 'Worldpay Bank', 'bank', 'active', '{"currencies":["USD"]}', '{"api_key":"demo_key_5"}', '{}', 'rest', 'bank', 5);
MYSQL_DATA

# الإلكترونية
mysql -u diparma_user -pdiparma_secure_2024 diparma_gateway << 'MYSQL_DATA'
INSERT IGNORE INTO dp_payment_gateways (code, name, type, status, config, credentials, settings, connection_type, gateway_type, sort_order) VALUES
('elec_001', 'Alipay', 'electronic', 'active', '{"currencies":["USD"]}', '{"api_key":"demo_key_e1"}', '{}', 'rest', 'wallet', 1),
('elec_002', 'WeChat Pay', 'electronic', 'active', '{"currencies":["USD"]}', '{"api_key":"demo_key_e2"}', '{}', 'rest', 'wallet', 2),
('elec_003', 'Google Pay', 'electronic', 'active', '{"currencies":["USD"]}', '{"api_key":"demo_key_e3"}', '{}', 'rest', 'wallet', 3),
('elec_004', 'Apple Pay', 'electronic', 'active', '{"currencies":["USD"]}', '{"api_key":"demo_key_e4"}', '{}', 'rest', 'wallet', 4),
('elec_005', 'Samsung Pay', 'electronic', 'inactive', '{"currencies":["USD"]}', '{"api_key":"demo_key_e5"}', '{}', 'rest', 'wallet', 5);
MYSQL_DATA

# العملات الرقمية
mysql -u diparma_user -pdiparma_secure_2024 diparma_gateway << 'MYSQL_DATA'
INSERT IGNORE INTO dp_payment_gateways (code, name, type, status, config, credentials, settings, connection_type, gateway_type, sort_order) VALUES
('crypto_001', 'Bitcoin Payments', 'crypto', 'active', '{"currencies":["BTC"]}', '{"api_key":"demo_key_c1"}', '{}', 'web3', 'crypto', 1),
('crypto_002', 'Ethereum Payments', 'crypto', 'active', '{"currencies":["ETH"]}', '{"api_key":"demo_key_c2"}', '{}', 'web3', 'crypto', 2),
('crypto_003', 'Litecoin Payments', 'crypto', 'active', '{"currencies":["LTC"]}', '{"api_key":"demo_key_c3"}', '{}', 'web3', 'crypto', 3),
('crypto_004', 'Ripple XRP', 'crypto', 'inactive', '{"currencies":["XRP"]}', '{"api_key":"demo_key_c4"}', '{}', 'web3', 'crypto', 4),
('crypto_005', 'Bitcoin Cash', 'crypto', 'active', '{"currencies":["BCH"]}', '{"api_key":"demo_key_c5"}', '{}', 'web3', 'crypto', 5);
MYSQL_DATA

# المحافظ
mysql -u diparma_user -pdiparma_secure_2024 diparma_gateway << 'MYSQL_DATA'
INSERT IGNORE INTO dp_payment_gateways (code, name, type, status, config, credentials, settings, connection_type, gateway_type, sort_order) VALUES
('wallet_001', 'MetaMask Wallet', 'wallet', 'active', '{"currencies":["ETH"]}', '{"api_key":"demo_key_w1"}', '{}', 'web3', 'wallet', 1),
('wallet_002', 'Trust Wallet', 'wallet', 'active', '{"currencies":["BTC","ETH"]}', '{"api_key":"demo_key_w2"}', '{}', 'web3', 'wallet', 2),
('wallet_003', 'Coinbase Wallet', 'wallet', 'active', '{"currencies":["BTC"]}', '{"api_key":"demo_key_w3"}', '{}', 'web3', 'wallet', 3),
('wallet_004', 'Ledger', 'wallet', 'active', '{"currencies":["BTC","ETH"]}', '{"api_key":"demo_key_w4"}', '{}', 'web3', 'wallet', 4),
('wallet_005', 'Trezor', 'wallet', 'inactive', '{"currencies":["BTC","ETH"]}', '{"api_key":"demo_key_w5"}', '{}', 'web3', 'wallet', 5);
MYSQL_DATA

echo "✓ تمت إضافة البيانات بنجاح!"
mysql -u diparma_user -pdiparma_secure_2024 diparma_gateway -e "SELECT type, COUNT(*) FROM dp_payment_gateways GROUP BY type;"

REMOTE

echo "✓ اكتمل الإصلاح والإضافة!"
