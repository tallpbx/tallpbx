#!/bin/bash
# ==============================================================================
# Let's Encrypt SSL Certificate Issuance & Renewal
# ==============================================================================
# This step installs certbot, issues a TLS certificate for the configured
# domain, and sets up automatic renewal. It can be run standalone after
# install, or during the installer when a domain_name is provided.
#
# Two modes are supported:
#   1) HTTP-01 challenge — for single-domain certificates (no wildcard)
#   2) DNS-01 challenge — for wildcard certificates (requires a DNS
#      provider script, e.g. cloudflare-dns.sh)
#
# Usage:
#   ./letsencrypt.sh                         # HTTP-01, interactive prompt
#   ./letsencrypt.sh --domain pbx.example.com # Specific domain
#   ./letsencrypt.sh --wildcard               # DNS-01 wildcard via Cloudflare
#   ./letsencrypt.sh --email admin@example.com # Provide contact email
#   ./letsencrypt.sh --staging                # Use Let's Encrypt staging env
#
# Safe to re-run: renews if certificate exists, skips certbot install
# if already present.
# ==============================================================================

cd "$(dirname "$0")"

. ./config.sh 2>/dev/null || true
. ./colors.sh 2>/dev/null || true
. ./environment.sh 2>/dev/null || true

# ── Parse arguments ─────────────────────────────────────────────────────────

CERT_DOMAIN="${domain_name:-}"
CERT_EMAIL="${ssl_email:-admin@$(hostname --fqdn 2>/dev/null || hostname)}"
WILDCARD=false
STAGING=false
FORCE_RENEW=false

# Read optional command-line settings so a scheduled or documented invocation
# can run without asking for values already supplied by the administrator.
while [[ $# -gt 0 ]]; do
    case "$1" in
        --domain)   CERT_DOMAIN="$2"; shift 2 ;;
        --email)    CERT_EMAIL="$2"; shift 2 ;;
        --wildcard) WILDCARD=true; shift ;;
        --staging)  STAGING=true; shift ;;
        --renew)    FORCE_RENEW=true; shift ;;
        *) shift ;;
    esac
done

# ── Determine the domain to secure ──────────────────────────────────────────

# If the domain_name from config is "ip_address" or empty, resolve the
# primary IP and prompt the operator. Let's Encrypt requires a real FQDN.
if [ -z "$CERT_DOMAIN" ] || [ "$CERT_DOMAIN" = "ip_address" ]; then
    echo ""
    warning "No domain name configured. Let's Encrypt requires a real domain."
    warning "If you don't have one yet, skip this step for now and run it"
    warning "later once DNS is pointed to this server."
    echo ""
    read -rp "$(printf 'Domain name to secure (or press Enter to skip): ')" CERT_DOMAIN
    if [ -z "$CERT_DOMAIN" ]; then
        verbose "No domain provided — skipping Let's Encrypt certificate issuance."
        exit 0
    fi
fi

# ── Determine challenge mode ────────────────────────────────────────────────

if [ "$WILDCARD" = true ]; then
    verbose "Wildcard certificate requested — using DNS-01 challenge"
    CHALLENGE_MODE="dns"
else
    # Check if port 80 is reachable (required for HTTP-01)
    if nc -z -w 3 localhost 80 2>/dev/null; then
        CHALLENGE_MODE="http"
    else
        warning "Port 80 not reachable locally — falling back to DNS-01 challenge"
        CHALLENGE_MODE="dns"
    fi
fi

# ── Install certbot ─────────────────────────────────────────────────────────

# Install Certbot only when it is absent. A later re-run reuses the installed
# client and certificate material instead of replacing either one.
if ! command -v certbot >/dev/null 2>&1; then
    verbose "Installing certbot (Let's Encrypt client)"
    apt_get_with_lock_wait update -qq
    apt_get_with_lock_wait install -y certbot python3-certbot-nginx

    if [ "$CHALLENGE_MODE" = "dns" ]; then
        apt_get_with_lock_wait install -y python3-certbot-dns-cloudflare
    fi
fi

# ── Issue or renew the certificate ──────────────────────────────────────────

# Build Certbot's non-interactive safety arguments once. This prevents Certbot
# from pausing after the installer has already begun certificate work.
CERTBOT_ARGS=(
    --non-interactive
    --agree-tos
    --email "$CERT_EMAIL"
)

if [ "$STAGING" = true ]; then
    CERTBOT_ARGS+=(--test-cert)
    verbose "Using Let's Encrypt staging environment"
fi

if [ "$FORCE_RENEW" = true ]; then
    CERTBOT_ARGS+=(--force-renewal)
fi

if [ "$CHALLENGE_MODE" = "http" ]; then
    # HTTP-01: certbot places a temporary file in .well-known/acme-challenge/
    # and Let's Encrypt verifies it via port 80.
    verbose "Issuing certificate for ${CERT_DOMAIN} via HTTP-01 challenge"

    if certbot certificates 2>/dev/null | grep -q "$CERT_DOMAIN"; then
        verbose "Certificate for ${CERT_DOMAIN} already exists — renewing"
        certbot renew "${CERTBOT_ARGS[@]}" --cert-name "$CERT_DOMAIN"
    else
        certbot certonly --nginx \
            "${CERTBOT_ARGS[@]}" \
            -d "$CERT_DOMAIN"
    fi

elif [ "$CHALLENGE_MODE" = "dns" ]; then
    # DNS-01: certbot uses a Cloudflare API token to create a TXT record
    # for _acme-challenge.<domain>. This works for wildcard certs too.
    DOMAINS_ARG="$CERT_DOMAIN"

    if [ "$WILDCARD" = true ]; then
        DOMAINS_ARG="$CERT_DOMAIN,*.$CERT_DOMAIN"
        verbose "Issuing wildcard certificate for *.${CERT_DOMAIN} via DNS-01"
    else
        verbose "Issuing certificate for ${CERT_DOMAIN} via DNS-01 (Cloudflare)"
    fi

    # Collect Cloudflare API token. The cloudflare-dns.sh companion script
    # writes a credentials file that certbot reads automatically.
    CLOUDFLARE_CREDS="/etc/letsencrypt/cloudflare.ini"

    if [ ! -f "$CLOUDFLARE_CREDS" ]; then
        echo ""
        warning "Cloudflare API credentials not found."
        warning "Run ./cloudflare-dns.sh first, or provide a token now."
        echo ""
        read -rsp "$(printf 'Cloudflare API token (or press Enter to skip): ')" CF_TOKEN
        echo ""

        if [ -z "$CF_TOKEN" ]; then
            error "DNS-01 challenge requires a Cloudflare API token. Aborting."
            exit 1
        fi

# Save the token in Certbot's protected configuration directory with a mode
# that prevents ordinary users from reading this DNS credential.
        mkdir -p /etc/letsencrypt
        cat > "$CLOUDFLARE_CREDS" <<CREDS
# Cloudflare API credentials for certbot DNS-01 challenge
dns_cloudflare_api_token = ${CF_TOKEN}
CREDS
        chmod 600 "$CLOUDFLARE_CREDS"
        verbose "Cloudflare credentials saved to ${CLOUDFLARE_CREDS}"
    fi

    if certbot certificates 2>/dev/null | grep -q "$CERT_DOMAIN"; then
        verbose "Certificate for ${CERT_DOMAIN} already exists — renewing"
        certbot renew "${CERTBOT_ARGS[@]}" --cert-name "$CERT_DOMAIN"
    else
        certbot certonly --dns-cloudflare \
            --dns-cloudflare-credentials "$CLOUDFLARE_CREDS" \
            "${CERTBOT_ARGS[@]}" \
            -d "$DOMAINS_ARG"
    fi
fi

# ── Post-issuance: update Nginx to use the new certificate ──────────────────

CERT_PATH="/etc/letsencrypt/live/${CERT_DOMAIN}/fullchain.pem"
KEY_PATH="/etc/letsencrypt/live/${CERT_DOMAIN}/privkey.pem"

# Change Nginx only after both certificate files exist. A failed certificate
# request therefore leaves the working HTTP configuration in place.
if [ -f "$CERT_PATH" ] && [ -f "$KEY_PATH" ]; then
    verbose "Certificate issued successfully"

    # Check if Nginx is already configured for HTTPS on this domain.
    # If not, create/replace the HTTPS server block.
    NGINX_SITE="/etc/nginx/sites-available/pbx"

    if [ -f "$NGINX_SITE" ] && ! grep -q "listen 443 ssl" "$NGINX_SITE"; then
        verbose "Updating Nginx configuration for HTTPS"

        cat > "$NGINX_SITE" <<NGINX
# ── HTTP → HTTPS redirect ───────────────────────────────────────────────
server {
    listen 80;
    listen [::]:80;
    server_name ${CERT_DOMAIN};

    # Let's Encrypt HTTP-01 challenge
    location /.well-known/acme-challenge/ {
        root /var/www/html;
    }

    location / {
        return 301 https://\$host\$request_uri;
    }
}

# ── HTTPS application server ─────────────────────────────────────────────
server {
    listen 443 ssl http2;
    listen [::]:443 ssl http2;
    server_name ${CERT_DOMAIN};

    ssl_certificate     ${CERT_PATH};
    ssl_certificate_key ${KEY_PATH};
    ssl_trusted_certificate ${CERT_PATH};

    # Modern TLS configuration (Mozilla intermediate, 2024)
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384:ECDHE-ECDSA-CHACHA20-POLY1305:ECDHE-RSA-CHACHA20-POLY1305:DHE-RSA-AES128-GCM-SHA256:DHE-RSA-AES256-GCM-SHA384;
    ssl_prefer_server_ciphers off;
    ssl_session_cache shared:SSL:10m;
    ssl_session_timeout 1d;
    ssl_session_tickets off;

    # HSTS (uncomment after confirming HTTPS works reliably)
    # add_header Strict-Transport-Security "max-age=63072000" always;

    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-XSS-Protection "1; mode=block" always;

    root /var/www/tallpbx/public;
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
        fastcgi_pass unix:/var/run/php/php${php_version:-8.5}-fpm.sock;
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_hide_header X-Powered-By;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
NGINX

        nginx -t 2>/dev/null && systemctl reload nginx
        verbose "Nginx updated and reloaded with HTTPS configuration"
    fi

    # Enable auto-renewal via systemd timer (certbot package creates this)
    if systemctl is-enabled certbot.timer 2>/dev/null; then
        verbose "Certificate auto-renewal is active (certbot.timer)"
    else
        systemctl enable --now certbot.timer 2>/dev/null || true
        verbose "Certificate auto-renewal timer enabled"
    fi
else
    error "Certificate issuance may have failed. Check certbot output above."
    exit 1
fi

verbose "Let's Encrypt certificate setup complete for ${CERT_DOMAIN}"
