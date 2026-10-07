#!/bin/bash
set -euo pipefail
a2enmod ssl
a2enmod headers
cat > /etc/apache2/sites-available/diparma-ssl.conf << "EOF"
<VirtualHost *:443>
    ServerName diparmas.com
    ServerAlias www.diparmas.com
    DocumentRoot /var/www/html/DIPARMA40
    SSLEngine on
    SSLCertificateFile /etc/ssl/certs/ssl-cert-snakeoil.pem
    SSLCertificateKeyFile /etc/ssl/private/ssl-cert-snakeoil.key
    <Directory /var/www/html/DIPARMA40>
        AllowOverride All
        Require all granted
        Options -Indexes +FollowSymLinks
    </Directory>
    ErrorLog ${APACHE_LOG_DIR}/diparma_ssl_error.log
    CustomLog ${APACHE_LOG_DIR}/diparma_ssl_access.log combined
</VirtualHost>
EOF
a2ensite diparma-ssl
apache2ctl configtest
systemctl reload apache2
ss -lntp | grep -E ':443|:80' || true
curl -skI -m 8 https://127.0.0.1/ -H 'Host: diparmas.com' | head -12
echo SSL_LISTEN_OK
