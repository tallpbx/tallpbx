#!/bin/bash
# ==============================================================================
# Nginx Web Server
# ==============================================================================
# This step installs Nginx (a web server) and creates a configuration
# file that serves the Laravel application. The site will be available
# at the server's IP address on port 80 (HTTP).
#
# After running letsencrypt.sh, the configuration will be upgraded to
# HTTPS automatically. Run this script again after certificate issuance
# to apply the HTTPS template without overwriting an existing cert config.
#
# Safe to re-run: the site configuration is overwritten but the web
# server just reloads — no data is affected.
# ==============================================================================

cd "$(dirname "$0")"

. ./config.sh
. ./colors.sh
. ./environment.sh

# Install Nginx if needed. Apt leaves an already installed copy in place.
verbose "Installing Nginx"

apt_get_with_lock_wait install -y nginx

# Create the Nginx site configuration for the Laravel application.
# This tells Nginx where the app files are and how to run PHP scripts.
cat > /etc/nginx/sites-available/pbx <<NGINX
server {
    listen 80;
    listen [::]:80;
    server_name _;
    root /var/www/tallpbx/public;

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";

    index index.php;

    charset utf-8;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    # Reverse proxy WebSocket connections for Laravel Reverb real-time events.
    # Browser clients connect to /app (or /apps) on standard HTTP/HTTPS ports (80/443).
    # Nginx upgrades the HTTP connection to a persistent WebSocket tunnel and forwards
    # traffic to the internal Reverb daemon running on 127.0.0.1:8080. This eliminates
    # the need to open port 8080 in the firewall and guarantees seamless SSL/TLS encryption.
    location /app {
        proxy_http_version 1.1;
        proxy_set_header Host \$http_host;
        proxy_set_header Scheme \$scheme;
        proxy_set_header SERVER_PORT \$server_port;
        proxy_set_header REMOTE_ADDR \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header Upgrade \$http_upgrade;
        proxy_set_header Connection "Upgrade";

        proxy_pass http://127.0.0.1:8080;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    error_page 404 /index.php;

    location ~ ^/index\.php(/|\$) {
        fastcgi_pass unix:/var/run/php/php$php_version-fpm.sock;
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_hide_header X-Powered-By;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
NGINX

# Enable the TallPBX site by linking it into Nginx's active configuration
# directory. Remove only Debian's unused default site, not any application data.
ln -sf /etc/nginx/sites-available/pbx /etc/nginx/sites-enabled/
rm -f /etc/nginx/sites-enabled/default

# Reload systemd's service definitions, make Nginx start after reboots, and
# restart it so the new site configuration becomes active immediately.
systemctl daemon-reload
systemctl enable nginx
systemctl restart nginx
