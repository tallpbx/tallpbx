#!/bin/bash
# ==============================================================================
# Cloudflare DNS API Helper for Let's Encrypt DNS-01 Challenge
# ==============================================================================
# This script prompts for a Cloudflare API token and saves it in the
# format certbot expects. It is idempotent — re-running with the same
# domain just validates the existing credentials.
#
# The Cloudflare API token needs these permissions:
#   Zone:Zone:Read
#   Zone:DNS:Edit
#
# Create a token at: https://dash.cloudflare.com/profile/api-tokens
#
# Usage:
#   ./cloudflare-dns.sh                           # Interactive prompt
#   ./cloudflare-dns.sh --token <cf-api-token>    # Non-interactive
#   ./cloudflare-dns.sh --verify                  # Verify existing credentials
# ==============================================================================

cd "$(dirname "$0")"

. ./colors.sh 2>/dev/null || true

CLOUDFLARE_CREDS="/etc/letsencrypt/cloudflare.ini"
CF_TOKEN=""
VERIFY_ONLY=false

# ── Parse arguments ─────────────────────────────────────────────────────────

# Read the optional non-interactive token or verification request before any
# network call or credential-file change is made.
while [[ $# -gt 0 ]]; do
    case "$1" in
        --token)  CF_TOKEN="$2"; shift 2 ;;
        --verify) VERIFY_ONLY=true; shift ;;
        *) shift ;;
    esac
done

# ── Collect credentials ─────────────────────────────────────────────────────

if [ "$VERIFY_ONLY" = true ]; then
    if [ -f "$CLOUDFLARE_CREDS" ] && grep -q "dns_cloudflare_api_token" "$CLOUDFLARE_CREDS"; then
        verbose "Cloudflare credentials file exists at ${CLOUDFLARE_CREDS}"
        echo ""

# Verify the token without printing it. The HTTP status tells the administrator
# whether Cloudflare accepted the credential.
        CF_TOKEN=$(grep "dns_cloudflare_api_token" "$CLOUDFLARE_CREDS" | awk -F'= ' '{print $2}' | tr -d ' ')
        RESPONSE=$(curl -s -o /dev/null -w "%{http_code}" \
            -H "Authorization: Bearer $CF_TOKEN" \
            "https://api.cloudflare.com/client/v4/user/tokens/verify" 2>/dev/null)

        if [ "$RESPONSE" = "200" ]; then
            success "Cloudflare API token is valid and working"
            exit 0
        else
            error "Cloudflare API token verification failed (HTTP ${RESPONSE})"
            exit 1
        fi
    else
        error "No Cloudflare credentials file found at ${CLOUDFLARE_CREDS}"
        echo ""
        verbose "Run this script without --verify to set up credentials:"
        verbose "  ./cloudflare-dns.sh"
        exit 1
    fi
fi

if [ -z "$CF_TOKEN" ]; then
    echo ""
    verbose "Cloudflare API Token Setup"
    verbose "=========================="
    verbose ""
    verbose "A Cloudflare API token is needed for DNS-01 challenges (wildcard"
    verbose "certificates). Create one at:"
    verbose ""
    verbose "  https://dash.cloudflare.com/profile/api-tokens"
    verbose ""
    verbose "The token needs these permissions:"
    verbose "  - Zone:Zone:Read"
    verbose "  - Zone:DNS:Edit"
    verbose ""
    read -rsp "$(printf 'Cloudflare API token: ')" CF_TOKEN
    echo ""

    if [ -z "$CF_TOKEN" ]; then
        verbose "No token provided — credentials not saved."
        exit 0
    fi
fi

# ── Save credentials ────────────────────────────────────────────────────────

# Create Certbot's credential directory if necessary. Re-running this does not
# remove an existing token until a new token is explicitly supplied.
mkdir -p "$(dirname "$CLOUDFLARE_CREDS")"

cat > "$CLOUDFLARE_CREDS" <<CREDS
# Cloudflare API credentials for certbot DNS-01 challenge
# Created by TallPBX installer
dns_cloudflare_api_token = ${CF_TOKEN}
CREDS

# Restrict the token file to root because it can change public DNS records.
chmod 600 "$CLOUDFLARE_CREDS"

# The directory must be accessible by certbot (root) only
chown root:root "$CLOUDFLARE_CREDS" 2>/dev/null || true

verbose "Cloudflare credentials saved to ${CLOUDFLARE_CREDS}"
verbose "You can now run ./letsencrypt.sh --wildcard for a wildcard certificate"
