#!/bin/bash
# check-webhook.sh - Check webhook status

echo "📊 Checking Telegram webhook status..."
echo "Expected webhook: http://localhost:8000/api/telegram/webhook"
echo ""

curl -s "https://api.telegram.org/bot7779533338:AAH-B1-r4GQzJStkyC7Ecziip7ccgso5AOs/getWebhookInfo" | jq '.' 2>/dev/null || curl -s "https://api.telegram.org/bot7779533338:AAH-B1-r4GQzJStkyC7Ecziip7ccgso5AOs/getWebhookInfo"

echo ""
echo "To update webhook: ./set-webhook.sh"
