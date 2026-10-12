#!/usr/bin/env bash
# provision.sh — arm N bots on the queue router (setWebhook with unique secrets)
# PROPOSAL — review the URLs/tokens below before running. Requires: bash, curl.
#
# Usage:
#   chmod +x provision.sh && ./provision.sh
#
# After this:
#   1. Put the printed secrets into mb_router_queue.php $REGISTRY
#   2. Put the tokens into mb_worker.php BOTS
#   3. php -l both files, run the worker under a process manager
#
# RULE: the bot must NOT be polled (getUpdates) while the webhook is armed,
#       otherwise Telegram returns 409 Conflict. One ingest per bot, always.

set -euo pipefail

WEBHOOK_BASE="https://alirezax5.ir/proposal/queue-router-v2/mb_router_queue.php"

# bot_token|short_name   (edit this list, then run)
BOTS=(
  "PASTE_TOKEN_A|main"
  "PASTE_TOKEN_B|second"
)

gen_secret() { php -r 'echo bin2hex(random_bytes(24));' 2>/dev/null \
            || openssl rand -hex 24; }

arm() { # $1=token $2=name
  local secret; secret="$(gen_secret)"
  echo "== setWebhook: $2"
  curl -fsS "https://api.telegram.org/bot$1/setWebhook" \
    -d url="${WEBHOOK_BASE}" \
    -d secret_token="${secret}" \
    -d allowed_updates='["message","edited_message","channel_post","callback_query","inline_query","managed_bot","guest_message","chat_join_request","business_message"]' \
    -d drop_pending_updates=false
  echo
  echo "   secret for '$2'  ->  $secret"
  echo "   (store it in \$REGISTRY; rotation = re-run this script)"
}

for entry in "${BOTS[@]}"; do
  tok="${entry%%|*}"; name="${entry##*|}"
  arm "$tok" "$name"
done

echo
echo "== getWebhookInfo (verify: url + pending_update_count + last_error) =="
for entry in "${BOTS[@]}"; do
  tok="${entry%%|*}"; name="${entry##*|}"
  echo "-- $name"
  curl -fsS "https://api.telegram.org/bot$tok/getWebhookInfo"; echo
done

echo
echo "Reminder: stop any polling daemon for these bots BEFORE arming (409 rule)."
