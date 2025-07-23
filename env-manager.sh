#!/bin/bash
# env-manager.sh - Fixed for frontend ngrok + backend localhost architecture

set -e

# Configuration
NGROK_URL="https://68c6605bb77f.ngrok-free.app"
BOT_TOKEN="7779533338:AAH-B1-r4GQzJStkyC7Ecziip7ccgso5AOs"
BOT_USERNAME="tgappy_bot"
ADMIN_TELEGRAM_ID="6941596189"

create_dev_env() {
    echo "🌐 Creating development environment (.env.dev)..."
    cat > .env.dev << EOF
# Development Environment - Frontend via Ngrok, Backend localhost
APP_NAME="Telegram Scheduler"
APP_ENV=local
APP_KEY=base64:wtqQ/6f+i9YERhRjXUttSgRw4VCEtn+zSsCyZioemZs=
APP_DEBUG=true
APP_URL=http://localhost:8000

# Database Configuration
DB_CONNECTION=mongodb
MONGO_DB_CONNECTION=mongodb://admin:password@mongodb:27017/telegram_scheduler?authSource=admin
DB_DATABASE=telegram_scheduler
DB_AUTHENTICATION_DATABASE=admin
MONGO_ROOT_USERNAME=admin
MONGO_ROOT_PASSWORD=password

# Queue & Cache Configuration
QUEUE_CONNECTION=redis
CACHE_STORE=redis
SESSION_DRIVER=redis
REDIS_HOST=redis
REDIS_PORT=6379
REDIS_PASSWORD=
REDIS_PREFIX=telegram_scheduler_database_

# JWT Configuration
JWT_SECRET=fIXfC0pDOdYeYxRY35WcPYJgt07tXtfxGzRdRMlHMb4ytitTHbkT8YooO8w3Jxxj
JWT_TTL=60
JWT_REFRESH_TTL=20160

# Telegram Bot Configuration
TELEGRAM_BOT_TOKEN=$BOT_TOKEN
TELEGRAM_BOT_USERNAME=$BOT_USERNAME

# Admin Configuration
ADMIN_TELEGRAM_IDS=$ADMIN_TELEGRAM_ID

# Frontend Configuration (Ngrok for Telegram Web App)
FRONTEND_URL=$NGROK_URL
REACT_APP_API_URL=http://localhost:8000
REACT_APP_TELEGRAM_BOT_USERNAME=$BOT_USERNAME

# CORS Configuration (Allow ngrok frontend + localhost)
CORS_ALLOWED_ORIGINS=$NGROK_URL,http://localhost:3000,http://localhost:8000,https://tgappy.com

# Stripe Test Configuration
STRIPE_KEY=sk_test_51Rcqa9RqiHAAOQNt4Qlp9zmFKkjf78mRgjqjTveYVmZg7z8OpEvElH0qkJMMaNKvgtJwUMa8kxWzER7pRCwwDe5K00I41OXJuh
STRIPE_PUBLIC_KEY=pk_test_51Rcqa9RqiHAAOQNt6dQOzDxtKXI4hl3PjmdxnnOz6Y2ARZJ4zXE03frTz6FPhMbp6ZGMxERdRVi1xJMK0SW6i2Gn00NYOU3H8O
STRIPE_WEBHOOK_SECRET=whsec_6GmWJJhXEHNwVLwi79M3eLZFgNFzcPo8

# Stripe Product IDs
STRIPE_PRICE_ID_PRO=price_1Rdv4sRqiHAAOQNtgBC15qcq
STRIPE_PRODUCT_ID_PRO=prod_SZ3Bv1lG9HoCLM
STRIPE_PRICE_ID_ULTRA=price_1RiLAnRqiHAAOQNt8JKhJKZM
STRIPE_PRODUCT_ID_ULTRA=prod_SdcPkOn7RC90lG

# Docker specific
CONTAINER_ROLE=app
EOF
}

create_prod_env() {
    echo "🚀 Creating production environment template (.env.prod)..."
    cat > .env.prod << EOF
# Production Environment Template (VPS)
APP_NAME="Telegram Scheduler"
APP_ENV=production
APP_KEY=base64:wtqQ/6f+i9YERhRjXUttSgRw4VCEtn+zSsCyZioemZs=
APP_DEBUG=false
APP_URL=https://back.tgappy.com

# Database Configuration
DB_CONNECTION=mongodb
MONGO_DB_CONNECTION=mongodb://admin:DbEJY5sE832L21U@mongodb:27017/telegram_scheduler?authSource=admin
DB_DATABASE=telegram_scheduler
DB_AUTHENTICATION_DATABASE=admin
MONGO_ROOT_USERNAME=admin
MONGO_ROOT_PASSWORD=DbEJY5sE832L21U

# Queue & Cache Configuration
QUEUE_CONNECTION=redis
CACHE_STORE=redis
SESSION_DRIVER=redis
REDIS_HOST=redis
REDIS_PORT=6379
REDIS_PASSWORD=DbEJY5sE832L21U
REDIS_PREFIX=telegram_scheduler_database_

# JWT Configuration
JWT_SECRET=fIXfC0pDOdYeYxRY35WcPYJgt07tXtfxGzRdRMlHMb4ytitTHbkT8YooO8w3Jxxj
JWT_TTL=60
JWT_REFRESH_TTL=20160

# Telegram Bot Configuration
TELEGRAM_BOT_TOKEN=$BOT_TOKEN
TELEGRAM_BOT_USERNAME=$BOT_USERNAME

# Admin Configuration
ADMIN_TELEGRAM_IDS=$ADMIN_TELEGRAM_ID
ADMIN_EMAIL=admin@yourdomain.com

# Frontend Configuration (Production domain)
FRONTEND_URL=https://tgappy.com
REACT_APP_API_URL=https://back.tgappy.com
REACT_APP_TELEGRAM_BOT_USERNAME=$BOT_USERNAME

# CORS Configuration
CORS_ALLOWED_ORIGINS=https://back.tgappy.com,https://tgappy.com

# Stripe Production Configuration (UPDATE THESE FOR PRODUCTION!)
STRIPE_KEY=sk_live_YOUR_LIVE_SECRET_KEY_HERE
STRIPE_PUBLIC_KEY=pk_live_YOUR_LIVE_PUBLIC_KEY_HERE
STRIPE_WEBHOOK_SECRET=whsec_YOUR_LIVE_WEBHOOK_SECRET_HERE

# Stripe Product IDs (UPDATE FOR PRODUCTION!)
STRIPE_PRICE_ID_PRO=price_YOUR_PROD_PRO_PRICE_ID
STRIPE_PRODUCT_ID_PRO=prod_YOUR_PROD_PRO_PRODUCT_ID
STRIPE_PRICE_ID_ULTRA=price_YOUR_PROD_ULTRA_PRICE_ID
STRIPE_PRODUCT_ID_ULTRA=prod_YOUR_PROD_ULTRA_PRODUCT_ID

# SSL Configuration
SSL_EMAIL=admin@tgappy.com
DOMAIN=tgappy.com

# Docker specific
CONTAINER_ROLE=app
EOF
}

switch_to_dev() {
    echo "🌐 Switching to development environment..."
    if [ -f .env.dev ]; then
        cp .env.dev .env
        echo "✅ Switched to development environment"
        echo "🔗 Frontend Ngrok URL: $NGROK_URL"
        echo "🔗 Backend API URL: http://localhost:8000"
        echo "💳 Using Stripe test keys"
    else
        echo "❌ .env.dev not found. Creating it..."
        create_dev_env
        cp .env.dev .env
        echo "✅ Created and switched to development environment"
    fi
}

switch_to_prod() {
    echo "🚀 Switching to production environment..."
    if [ -f .env.prod ]; then
        cp .env.prod .env
        echo "✅ Switched to production environment"
        echo "⚠️ Remember to update domain, passwords, and Stripe live keys in .env"
    else
        echo "❌ .env.prod not found. Creating template..."
        create_prod_env
        echo "📝 Created .env.prod template"
        echo "⚠️ Please edit .env.prod with your production values before switching"
        return 1
    fi
}

update_ngrok_url() {
    NEW_URL="$1"
    if [ -z "$NEW_URL" ]; then
        echo "❌ Please provide new ngrok URL"
        echo "Usage: $0 update-ngrok https://your-new-url.ngrok-free.app"
        return 1
    fi
    
    echo "🔄 Updating ngrok URL to: $NEW_URL"
    
    # Update in development env (only FRONTEND_URL and CORS, not API)
    if [ -f .env.dev ]; then
        sed -i.bak "s|FRONTEND_URL=.*|FRONTEND_URL=$NEW_URL|g" .env.dev
        sed -i.bak "s|CORS_ALLOWED_ORIGINS=.*|CORS_ALLOWED_ORIGINS=$NEW_URL,http://localhost:3000,http://localhost:8000|g" .env.dev
        rm .env.dev.bak
        echo "✅ Updated .env.dev"
    fi
    
    # Update current env if it's dev
    if [ -f .env ] && grep -q "APP_ENV=local" .env; then
        sed -i.bak "s|FRONTEND_URL=.*|FRONTEND_URL=$NEW_URL|g" .env
        sed -i.bak "s|CORS_ALLOWED_ORIGINS=.*|CORS_ALLOWED_ORIGINS=$NEW_URL,http://localhost:3000,http://localhost:8000|g" .env
        rm .env.bak
        echo "✅ Updated current .env"
    fi
    
    # Update webhook setter script
    if [ -f set-webhook.sh ]; then
        sed -i.bak "s|WEBHOOK_URL=.*|WEBHOOK_URL=\"http://localhost:8000/api/telegram/webhook\"|g" set-webhook.sh
        rm set-webhook.sh.bak
        echo "✅ Updated webhook script (webhook stays on localhost)"
    fi
    
    echo "🔄 Restart your services to apply changes:"
    echo "docker-compose restart backend"
    echo ""
    echo "ℹ️ Note: API stays on localhost:8000, only frontend uses ngrok"
}

show_status() {
    echo "📊 Current Environment Status:"
    echo "============================="
    
    if [ -f .env ]; then
        echo "Backend API URL: $(grep APP_URL .env | cut -d= -f2)"
        echo "Frontend URL: $(grep FRONTEND_URL .env | cut -d= -f2)"
        echo "Environment: $(grep APP_ENV .env | cut -d= -f2)"
        echo "Debug Mode: $(grep APP_DEBUG .env | cut -d= -f2)"
        echo "Stripe Mode: $(grep STRIPE_KEY .env | cut -d= -f2 | grep -q 'test' && echo 'test' || echo 'live')"
    else
        echo "❌ No .env file found"
    fi
    
    echo ""
    echo "Available environment files:"
    [ -f .env.dev ] && echo "✅ .env.dev (development)" || echo "❌ .env.dev (missing)"
    [ -f .env.prod ] && echo "✅ .env.prod (production)" || echo "❌ .env.prod (missing)"
    
    echo ""
    echo "Architecture:"
    echo "  📱 Frontend (Telegram Web App): Ngrok URL"
    echo "  🔧 Backend API: localhost:8000"
    echo "  🔗 Webhook: localhost:8000/api/telegram/webhook"
    echo "  💳 Payments: Stripe integration"
    
    echo ""
    echo "Docker status:"
    if command -v docker-compose &> /dev/null; then
        docker-compose ps 2>/dev/null || echo "❌ Docker services not running"
    else
        echo "❌ Docker Compose not available"
    fi
}

case "$1" in
    "dev")
        switch_to_dev
        ;;
    "prod")
        switch_to_prod
        ;;
    "create-envs")
        create_dev_env
        create_prod_env
        echo "✅ Created both environment files"
        ;;
    "update-ngrok")
        update_ngrok_url "$2"
        ;;
    "status")
        show_status
        ;;
    *)
        echo "🛠️ Environment Manager - Corrected Architecture"
        echo "================================================"
        echo ""
        echo "Architecture:"
        echo "  📱 Frontend: Ngrok URL (for Telegram Web App)"
        echo "  🔧 Backend API: localhost:8000 (internal)"
        echo "  🔗 Webhook: localhost:8000 (Telegram → ngrok → localhost)"
        echo ""
        echo "Usage: $0 [command]"
        echo ""
        echo "Commands:"
        echo "  dev                           - Switch to development environment"
        echo "  prod                          - Switch to production environment"
        echo "  create-envs                   - Create both env files"
        echo "  update-ngrok <url>            - Update ngrok URL (frontend only)"
        echo "  status                        - Show current status"
        echo ""
        echo "Examples:"
        echo "  $0 dev                        - Switch to development"
        echo "  $0 update-ngrok https://abc.ngrok-free.app"
        echo ""
        show_status
        ;;
esac