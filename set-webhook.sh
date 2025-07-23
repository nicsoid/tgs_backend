#!/bin/bash
# set-webhook-corrected.sh - Webhook management for localhost API + ngrok frontend

BOT_TOKEN="7779533338:AAH-B1-r4GQzJStkyC7Ecziip7ccgso5AOs"
BOT_USERNAME="tgappy_bot"

# Webhook points to localhost API (not ngrok)
# Ngrok only tunnels the frontend for Telegram Web App
WEBHOOK_URL="http://localhost:8000/api/telegram/webhook"

echo "🔗 Setting Telegram webhook for localhost API..."
echo "Architecture:"
echo "  📱 Frontend (Web App): Ngrok tunnel"
echo "  🔧 Backend API: localhost:8000"
echo "  🔗 Webhook: localhost:8000/api/telegram/webhook"
echo ""
echo "Setting webhook to: $WEBHOOK_URL"

# Set the webhook
response=$(curl -s -X POST "https://api.telegram.org/bot$BOT_TOKEN/setWebhook" \
     -H "Content-Type: application/json" \
     -d "{\"url\":\"$WEBHOOK_URL\"}")

echo ""
echo "Response: $response"

# Check if successful
if echo "$response" | grep -q '"ok":true'; then
    echo "✅ Webhook set successfully!"
    echo "🤖 Test by sending a message to @$BOT_USERNAME"
else
    echo "❌ Failed to set webhook"
    echo "Response: $response"
fi

echo ""
echo "📋 Architecture Notes:"
echo "  • Telegram sends webhooks to localhost:8000/api/telegram/webhook"
echo "  • Frontend runs on ngrok for Telegram Web App access"
echo "  • API calls from frontend go to localhost:8000"
echo "  • This setup works when both ngrok and backend are running locally"

# Create check-webhook script as well
cat > check-webhook.sh << EOF
#!/bin/bash
# check-webhook.sh - Check webhook status

echo "📊 Checking Telegram webhook status..."
echo "Expected webhook: http://localhost:8000/api/telegram/webhook"
echo ""

curl -s "https://api.telegram.org/bot$BOT_TOKEN/getWebhookInfo" | jq '.' 2>/dev/null || curl -s "https://api.telegram.org/bot$BOT_TOKEN/getWebhookInfo"

echo ""
echo "To update webhook: ./set-webhook.sh"
EOF

chmod +x check-webhook.sh

echo ""
echo "✅ Created check-webhook.sh script as well"