#!/bin/bash
# dev-commands.sh - Corrected for localhost API + ngrok frontend architecture

NGROK_URL="https://68c6605bb77f.ngrok-free.app"
BOT_USERNAME="tgappy_bot"

case "$1" in
    "start")
        echo "🚀 Starting Telegram Scheduler..."
        echo ""
        echo "Architecture:"
        echo "  📱 Frontend: $NGROK_URL (for Telegram Web App)"
        echo "  🔧 Backend API: http://localhost:8000"
        echo "  🔗 Webhook: http://localhost:8000/api/telegram/webhook"
        echo ""
        
        # Start Docker services
        docker-compose up -d
        
        echo "⏳ Waiting for services to start..."
        sleep 20
        
        # Check status
        echo "📊 Service status:"
        docker-compose ps
        
        echo ""
        echo "📋 Next steps:"
        echo "1. Start ngrok in separate terminal: ngrok http 3000"
        echo "2. Update ngrok URL if changed: ./env-manager.sh update-ngrok <new-url>"
        echo "3. Set webhook: ./dev-commands.sh webhook"
        echo "4. Test frontend via ngrok, API stays on localhost"
        ;;
        
    "logs")
        service="${2:-backend}"
        echo "📄 Showing logs for $service..."
        docker-compose logs -f --tail=50 $service
        ;;
        
    "shell")
        service="${2:-backend}"
        echo "🐚 Opening shell in $service container..."
        docker-compose exec $service bash
        ;;
        
    "webhook")
        echo "🔗 Setting Telegram webhook (localhost API)..."
        ./set-webhook.sh
        ;;
        
    "check")
        echo "📊 Checking webhook status..."
        ./check-webhook.sh
        ;;
        
    "restart")
        service="${2:-backend}"
        echo "🔄 Restarting $service..."
        docker-compose restart $service
        ;;
        
    "stop")
        echo "🛑 Stopping all services..."
        docker-compose down
        ;;
        
    "rebuild")
        echo "🔨 Rebuilding and restarting services..."
        docker-compose down
        docker-compose up -d --build
        ;;
        
    "test")
        echo "🧪 Testing application architecture..."
        echo ""
        
        # Test backend API (localhost)
        echo "Testing backend API (localhost:8000):"
        if curl -f -s http://localhost:8000/health > /dev/null; then
            echo "✅ Backend API is responding"
        else
            echo "❌ Backend API not responding"
        fi
        
        # Test if ngrok is accessible (for frontend)
        echo ""
        echo "Testing ngrok frontend access:"
        if curl -f -s "$NGROK_URL" > /dev/null 2>&1; then
            echo "✅ Ngrok tunnel is working"
        else
            echo "❌ Ngrok tunnel not responding"
            echo "   Make sure ngrok is running: ngrok http 3000"
        fi
        
        # Test webhook endpoint
        echo ""
        echo "Testing webhook endpoint:"
        if curl -f -s http://localhost:8000/api/telegram/webhook > /dev/null; then
            echo "✅ Webhook endpoint is accessible"
        else
            echo "❌ Webhook endpoint not responding"
        fi
        
        # Check services
        echo ""
        echo "Docker services status:"
        docker-compose ps
        
        # Architecture summary
        echo ""
        echo "📋 Current Architecture:"
        echo "  📱 Frontend: $NGROK_URL → localhost:3000"
        echo "  🔧 Backend API: http://localhost:8000"
        echo "  🔗 Webhook: http://localhost:8000/api/telegram/webhook"
        echo "  🗄️ MongoDB: localhost:27017"
        echo "  📊 Redis: localhost:6379"
        echo "  💳 Stripe: Test mode enabled"
        ;;
        
    "status")
        echo "📊 Telegram Scheduler Status"
        echo "============================"
        echo ""
        
        # Environment info
        if [ -f .env ]; then
            echo "Environment: $(grep APP_ENV .env | cut -d= -f2)"
            echo "Debug: $(grep APP_DEBUG .env | cut -d= -f2)"
            echo "Backend API: $(grep APP_URL .env | cut -d= -f2)"
            echo "Frontend: $(grep FRONTEND_URL .env | cut -d= -f2)"
        fi
        
        echo ""
        echo "Services:"
        docker-compose ps
        
        echo ""
        echo "Quick health check:"
        curl -f -s http://localhost:8000/health > /dev/null && echo "✅ Backend healthy" || echo "❌ Backend down"
        ;;
        
    "frontend")
        echo "🌐 Starting frontend with ngrok support..."
        docker-compose --profile frontend up -d
        echo ""
        echo "Frontend started on port 3000"
        echo "Access via ngrok: $NGROK_URL"
        echo "Local access: http://localhost:3000"
        ;;
        
    "monitoring")
        echo "📊 Starting monitoring tools..."
        docker-compose --profile monitoring up -d
        echo ""
        echo "Redis Commander: http://localhost:8081"
        ;;
        
    "update-env")
        echo "⚙️ Updating environment with current configuration..."
        ./env-manager.sh dev
        docker-compose restart backend
        echo "✅ Environment updated and backend restarted"
        ;;
        
    *)
        echo "🛠️ Development Commands - Corrected Architecture"
        echo "================================================"
        echo ""
        echo "Architecture:"
        echo "  📱 Frontend: Ngrok tunnel → localhost:3000 (for Telegram Web App)"
        echo "  🔧 Backend API: localhost:8000 (for all API calls)"
        echo "  🔗 Webhook: localhost:8000 (Telegram → localhost directly)"
        echo ""
        echo "Usage: $0 [command] [service]"
        echo ""
        echo "Main Commands:"
        echo "  start                - Start all services"
        echo "  stop                 - Stop all services"
        echo "  restart [service]    - Restart service (default: backend)"
        echo "  rebuild              - Rebuild and restart all"
        echo "  status               - Show status and health"
        echo ""
        echo "Development Tools:"
        echo "  logs [service]       - View logs (default: backend)"
        echo "  shell [service]      - Access container shell (default: backend)"
        echo "  test                 - Test all endpoints"
        echo "  webhook              - Set Telegram webhook"
        echo "  check                - Check webhook status"
        echo ""
        echo "Optional Services:"
        echo "  frontend             - Start React frontend"
        echo "  monitoring           - Start Redis monitoring"
        echo ""
        echo "Environment:"
        echo "  update-env           - Update environment configuration"
        echo ""
        echo "Examples:"
        echo "  $0 start             - Start development environment"
        echo "  $0 logs backend      - View backend logs"
        echo "  $0 shell queue-worker - Access queue worker shell"
        echo "  $0 test              - Test all components"
        echo ""
        echo "🚨 Important Notes:"
        echo "  • Start ngrok separately: ngrok http 3000"
        echo "  • Frontend uses ngrok URL for Telegram Web App access"
        echo "  • API calls go directly to localhost:8000"
        echo "  • Webhook is set to localhost:8000 (not ngrok)"
        ;;
esac