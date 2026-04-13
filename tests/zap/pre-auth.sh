#!/usr/bin/env bash
# ZAP Pre-Authentication Script
#
# Logs into the StratFlow test environment before ZAP runs, captures the
# session cookie, and writes it to a file ZAP can import.
#
# Usage:
#   bash tests/zap/pre-auth.sh <target_url> <email> <password> <cookie_file>
#
# Outputs:
#   $cookie_file — Netscape-format cookie jar for use with ZAP
#   Exits 0 on success, 1 on failure.

set -euo pipefail

TARGET_URL="${1:?Usage: $0 <target_url> <email> <password> <cookie_file>}"
SMOKE_EMAIL="${2:?Missing email}"
SMOKE_PASS="${3:?Missing password}"
COOKIE_FILE="${4:-/tmp/zap-session.txt}"

echo "[zap-pre-auth] Target: $TARGET_URL"

# Step 1: GET /login to extract the CSRF token from the form
CSRF_RESPONSE=$(curl -sf \
  --cookie-jar "$COOKIE_FILE" \
  --location \
  "${TARGET_URL}/login" \
  --user-agent "ZAP-PreAuth/1.0" \
  -o /tmp/login-page.html 2>&1) || {
  echo "[zap-pre-auth] ERROR: Could not fetch login page"
  exit 1
}

CSRF_TOKEN=$(grep -o 'name="_csrf_token" value="[^"]*"' /tmp/login-page.html \
  | head -1 \
  | sed 's/.*value="\([^"]*\)".*/\1/')

if [ -z "$CSRF_TOKEN" ]; then
  echo "[zap-pre-auth] ERROR: Could not extract CSRF token from login page"
  cat /tmp/login-page.html | head -50
  exit 1
fi

echo "[zap-pre-auth] CSRF token extracted (${#CSRF_TOKEN} chars)"

# Step 2: POST /login with credentials + CSRF token
HTTP_CODE=$(curl -sf \
  --cookie "$COOKIE_FILE" \
  --cookie-jar "$COOKIE_FILE" \
  --location \
  --output /tmp/login-response.html \
  --write-out "%{http_code}" \
  -X POST "${TARGET_URL}/login" \
  --data-urlencode "email=${SMOKE_EMAIL}" \
  --data-urlencode "password=${SMOKE_PASS}" \
  --data-urlencode "_csrf_token=${CSRF_TOKEN}" \
  --user-agent "ZAP-PreAuth/1.0" \
  --referer "${TARGET_URL}/login" \
  2>&1) || HTTP_CODE="0"

echo "[zap-pre-auth] Login POST HTTP code: $HTTP_CODE"

# Check we landed on /app/home (successful auth redirects there)
FINAL_URL=$(curl -sf \
  --cookie "$COOKIE_FILE" \
  --output /dev/null \
  --write-out "%{url_effective}" \
  --location \
  "${TARGET_URL}/app/home" \
  --user-agent "ZAP-PreAuth/1.0" \
  2>&1) || FINAL_URL=""

echo "[zap-pre-auth] Post-login navigation URL: $FINAL_URL"

if echo "$FINAL_URL" | grep -q "/app/home"; then
  echo "[zap-pre-auth] Authentication successful — session cookie saved to $COOKIE_FILE"
  echo "[zap-pre-auth] Cookie jar:"
  cat "$COOKIE_FILE"
  exit 0
elif echo "$FINAL_URL" | grep -q "/login"; then
  echo "[zap-pre-auth] ERROR: Still on login page — check credentials or MFA status"
  cat /tmp/login-response.html | head -30
  exit 1
else
  echo "[zap-pre-auth] WARNING: Unexpected final URL ($FINAL_URL) — proceeding anyway"
  exit 0
fi
