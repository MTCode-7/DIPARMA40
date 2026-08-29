#!/bin/bash
# ============================================================
# DI PARMA | setup_server.sh
# إعداد السيرفر لأول مرة - Ubuntu 22.04/24.04
# ============================================================
# التشغيل:
#   bash deploy/setup_server.sh
# ============================================================

set -e

# ── الألوان ──────────────────────────────────────────────────
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m'

log()  { echo -e "${GREEN}[✓]${NC} $1"; }
warn() { echo -e "${YELLOW}[!]${NC} $1"; }
err()  { echo -e "${RED}[✗]${NC} $1"; exit 1; }

echo "============================================"
echo "  DI PARMA — إعداد السيرفر"
echo "============================================"

# ── [1] تحديث النظام ────────────────────────────────────────
log "تحديث النظام..."
apt-get update && apt-get upgrade -y

# ── [2] تثبيت المتطلبات الأساسية ────────────────────────────
log "تثبيت المتطلبات الأساسية..."
apt-get install -y \
    nginx \
    mysql-server \
    php8.3 \
    php8.3-fpm \
    php8.3-mysql \
    php8.3-curl \
    php8.3-json \
    php8.3-mbstring \
    php8.3-xml \
    php8.3-zip \
    php8.3-bcmath \
    php8.3-intl \
    php8.3-gd \
    php8.3-soap \
    php8.3-redis \
    composer \
    git \
    unzip \
    curl \
    wget \
    certbot \
    python3-certbot-nginx \
    ufw \
    fail2ban \
    supervisor \
    redis-server

# ── [3] إعداد قاعدة البيانات ─────────────────────────────────
log "إعداد قاعدة البيانات..."
DB_NAME="diparma_gateway"
DB_USER="diparma_user"
DB_PASS=$(openssl rand -base64 32)

mysql -u root <<SQL
CREATE DATABASE IF NOT EXISTS ${DB_NAME};
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON ${DB_NAME}.* TO '${DB_USER}'@'localhost';
FLUSH PRIVILEGES;
SQL

# حفظ بيانات قاعدة البيانات
cat > /root/diparma_db_credentials.txt <<EOF
DB_NAME="${DB_NAME}"
DB_USER="${DB_USER}"
DB_PASS="${DB_PASS}"
EOF

log "قاعدة البيانات: ${DB_NAME}"
log "المستخدم: ${DB_USER}"
log "كلمة المرور: ${DB_PASS}"
log "ملف البيانات: /root/diparma_db_credentials.txt"

# ── [4] إعداد Nginx ──────────────────────────────────────────
log "إعداد Nginx..."
cat > /etc/nginx/sites-available/diparma <<'EOF'
server {
    listen 80;
    server_name diparmas.com www.diparmas.com;
    root /var/www/diparma/public;
    index index.php index.html;

    client_max_body_size 100M;
    client_body_timeout 300s;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/var/run/php/php8.3-fpm.sock;
        fastcgi_read_timeout 300s;
    }

    location ~ /\.ht {
        deny all;
    }

    location ~* \.(jpg|jpeg|png|gif|ico|css|js|svg)$ {
        expires 1y;
        add_header Cache-Control "public, immutable";
    }

    location /logs/ {
        deny all;
    }

    location /config/ {
        deny all;
    }

    gzip on;
    gzip_vary on;
    gzip_min_length 1024;
    gzip_types text/plain text/css text/xml text/javascript application/javascript application/xml+rss application/json;
}
EOF

ln -sf /etc/nginx/sites-available/diparma /etc/nginx/sites-enabled/
rm -f /etc/nginx/sites-enabled/default
systemctl restart nginx

# ── [5] إعداد جدار الحماية ──────────────────────────────────
log "إعداد جدار الحماية..."
ufw allow 22/tcp
ufw allow 80/tcp
ufw allow 443/tcp
ufw --force enable

# ── [6] إعداد Fail2ban ──────────────────────────────────────
log "إعداد Fail2ban..."
systemctl enable fail2ban
systemctl start fail2ban

# ── [7] إعداد Supervisor ─────────────────────────────────────
log "إعداد Supervisor..."
cat > /etc/supervisor/conf.d/diparma.conf <<'EOF'
[program:diparma-worker]
command=php /var/www/diparma/artisan queue:work --tries=3
directory=/var/www/diparma
user=www-data
autostart=true
autorestart=true
stdout_logfile=/var/log/diparma-worker.log
stderr_logfile=/var/log/diparma-worker-error.log
EOF

supervisorctl reread
supervisorctl update

# ── [8] إعداد Redis ──────────────────────────────────────────
log "إعداد Redis..."
systemctl enable redis-server
systemctl start redis-server

# ── [9] إعداد PHP ────────────────────────────────────────────
log "إعداد PHP..."
cat > /etc/php/8.3/fpm/pool.d/diparma.conf <<'EOF'
[www]
pm = dynamic
pm.max_children = 50
pm.start_servers = 10
pm.min_spare_servers = 5
pm.max_spare_servers = 20
request_terminate_timeout = 300s
EOF

systemctl restart php8.3-fpm

# ── [10] إنشاء المجلدات ──────────────────────────────────────
log "إنشاء المجلدات..."
mkdir -p /var/www/diparma
mkdir -p /var/www/diparma/public
mkdir -p /var/www/diparma/logs
mkdir -p /var/www/diparma/cache
mkdir -p /var/www/diparma/tmp

chown -R www-data:www-data /var/www/diparma
chmod -R 755 /var/www/diparma
chmod -R 777 /var/www/diparma/logs
chmod -R 777 /var/www/diparma/cache
chmod -R 777 /var/www/diparma/tmp

echo ""
echo "============================================"
echo "  ✓ تم إعداد السيرفر بنجاح"
echo "============================================"
echo ""
echo "📌 معلومات قاعدة البيانات:"
echo "   اسم القاعدة: ${DB_NAME}"
echo "   المستخدم:   ${DB_USER}"
echo "   كلمة المرور: ${DB_PASS}"
echo ""
echo "📌 ملفات التكوين:"
echo "   Nginx:      /etc/nginx/sites-available/diparma"
echo "   Supervisor: /etc/supervisor/conf.d/diparma.conf"
echo "   DB Creds:   /root/diparma_db_credentials.txt"
echo ""
echo "📌 المسارات:"
echo "   التطبيق:    /var/www/diparma"
echo "   السجلات:    /var/www/diparma/logs"
echo "   الكاش:      /var/www/diparma/cache"
echo ""
echo "🔧 الخطوة التالية:"
echo "   bash deploy/deploy.sh"
echo "============================================"