#!/bin/bash
# complete-dev-setup.sh - Comprehensive development setup based on your files

case "$1" in
    "fix-mongodb")
        echo "🔧 Fixing MongoDB Connection (Using Your Configuration)"
        echo "====================================================="
        
        # Use your existing mongodb-fix.sh
        chmod +x mongodb-fix.sh 2>/dev/null
        ./mongodb-fix.sh 2>/dev/null || {
            echo "Running direct MongoDB fix..."
            
            # Stop everything
            docker-compose -f docker-compose.dev.yml down 2>/dev/null
            docker-compose down 2>/dev/null
            
            # Clean volumes
            docker volume rm telegram-scheduler_mongodb_data 2>/dev/null || true
            docker volume rm telegram-scheduler_redis_data 2>/dev/null || true
            
            # Setup environment using your env-manager
            if [ -f env-manager.sh ]; then
                chmod +x env-manager.sh
                ./env-manager.sh dev
            fi
            
            # Start with dev compose
            docker-compose -f docker-compose.dev.yml up -d --build
            
            echo "⏳ Waiting for services..."
            sleep 45
            
            # Test
            if curl -f -s http://localhost:8000/health >/dev/null; then
                echo "✅ Backend is working!"
            else
                echo "❌ Still having issues"
                docker-compose -f docker-compose.dev.yml logs backend
            fi
        }
        ;;
        
    "start-backend")
        echo "🚀 Starting Backend Services (Your Setup)"
        echo "========================================"
        
        # Use your local-frontend-dev.sh if available
        if [ -f local-frontend-dev.sh ]; then
            chmod +x local-frontend-dev.sh
            ./local-frontend-dev.sh
        elif [ -f dev-local.sh ]; then
            chmod +x dev-local.sh
            ./dev-local.sh start
        else
            echo "Using docker-compose.dev.yml..."
            docker-compose -f docker-compose.dev.yml up -d
        fi
        ;;
        
    "start-frontend")
        echo "🌐 Starting Frontend Locally"
        echo "============================"
        
        if [ ! -d frontend ]; then
            echo "❌ Frontend directory not found"
            exit 1
        fi
        
        cd frontend
        
        # Install dependencies if needed
        if [ ! -d node_modules ]; then
            echo "📦 Installing frontend dependencies..."
            npm install
        fi
        
        # Create/update environment
        echo "📝 Setting up frontend environment..."
        cat > .env.local << 'EOF'
REACT_APP_API_URL=http://localhost:8000
REACT_APP_TELEGRAM_BOT_USERNAME=tgappy_bot
REACT_APP_FRONTEND_URL=https://68c6605bb77f.ngrok-free.app
GENERATE_SOURCEMAP=true
FAST_REFRESH=true
EOF
        
        echo "🚀 Starting React development server..."
        echo "Frontend will be available at: http://localhost:3000"
        echo "Remember to start ngrok: ngrok http 3000"
        npm start
        ;;
        
    "check-health")
        echo "🧪 Complete Health Check"
        echo "======================="
        
        echo "1. Docker Services:"
        if [ -f docker-compose.dev.yml ]; then
            docker-compose -f docker-compose.dev.yml ps
        else
            docker-compose ps
        fi
        
        echo ""
        echo "2. Backend API:"
        if response=$(curl -f -s http://localhost:8000/health 2>/dev/null); then
            echo "✅ Backend API responding"
            echo "   Response: $response"
        else
            echo "❌ Backend API not responding"
        fi
        
        echo ""
        echo "3. MongoDB Connection:"
        if [ -f docker-compose.dev.yml ]; then
            COMPOSE_FILE="docker-compose.dev.yml"
        else
            COMPOSE_FILE="docker-compose.yml"
        fi
        
        if docker-compose -f $COMPOSE_FILE exec -T backend php artisan tinker --execute="try { DB::connection('mongodb')->listCollections(); echo 'Connected'; } catch(Exception \$e) { echo 'Failed: ' . \$e->getMessage(); }" 2>/dev/null; then
            echo "✅ MongoDB connection working"
        else
            echo "❌ MongoDB connection failed"
        fi
        
        echo ""
        echo "4. Frontend:"
        if curl -f -s http://localhost:3000 >/dev/null 2>&1; then
            echo "✅ Frontend running on localhost:3000"
        else
            echo "❌ Frontend not running (start with: $0 start-frontend)"
        fi
        
        echo ""
        echo "5. Environment Check:"
        if [ -f .env ]; then
            echo "✅ .env file exists"
            echo "   Environment: $(grep APP_ENV .env 2>/dev/null | cut -d= -f2)"
            echo "   Debug: $(grep APP_DEBUG .env 2>/dev/null | cut -d= -f2)"
        else
            echo "❌ .env file missing"
        fi
        ;;
        
    "logs")
        service="${2:-backend}"
        echo "📄 Viewing logs for: $service"
        
        if [ -f docker-compose.dev.yml ]; then
            docker-compose -f docker-compose.dev.yml logs -f --tail=50 $service
        else
            docker-compose logs -f --tail=50 $service
        fi
        ;;
        
    "shell")
        service="${2:-backend}"
        echo "🐚 Opening shell in: $service"
        
        if [ -f docker-compose.dev.yml ]; then
            docker-compose -f docker-compose.dev.yml exec $service bash
        else
            docker-compose exec $service bash
        fi
        ;;
        
    "reset-all")
        echo "🔄 Complete Reset (Nuclear Option)"
        echo "================================="
        echo "This will:"
        echo "  • Stop all containers"
        echo "  • Remove all volumes"
        echo "  • Clean Docker system"
        echo "  • Rebuild everything"
        echo ""
        read -p "Are you sure? (y/N): " -n 1 -r
        echo
        
        if [[ $REPLY =~ ^[Yy]$ ]]; then
            echo "Stopping containers..."
            docker-compose -f docker-compose.dev.yml down 2>/dev/null
            docker-compose down 2>/dev/null
            
            echo "Removing volumes..."
            docker volume rm telegram-scheduler_mongodb_data 2>/dev/null || true
            docker volume rm telegram-scheduler_redis_data 2>/dev/null || true
            
            echo "Docker system cleanup..."
            docker system prune -f
            docker volume prune -f
            
            echo "Setting up environment..."
            if [ -f env-manager.sh ]; then
                chmod +x env-manager.sh
                ./env-manager.sh dev
            fi
            
            echo "Starting fresh..."
            $0 start-backend
            
            echo "✅ Complete reset finished!"
        else
            echo "Reset cancelled"
        fi
        ;;
        
    "webhook")
        echo "🔗 Setting up Telegram webhook"
        
        if [ -f set-webhook.sh ]; then
            chmod +x set-webhook.sh
            ./set-webhook.sh
        else
            echo "❌ set-webhook.sh not found"
            echo "Creating basic webhook setup..."
            
            BOT_TOKEN="7779533338:AAH-B1-r4GQzJStkyC7Ecziip7ccgso5AOs"
            WEBHOOK_URL="http://localhost:8000/api/telegram/webhook"
            
            echo "Setting webhook to: $WEBHOOK_URL"
            response=$(curl -s -X POST "https://api.telegram.org/bot$BOT_TOKEN/setWebhook" \
                 -H "Content-Type: application/json" \
                 -d "{\"url\":\"$WEBHOOK_URL\"}")
            
            if echo "$response" | grep -q '"ok":true'; then
                echo "✅ Webhook set successfully!"
            else
                echo "❌ Failed to set webhook: $response"
            fi
        fi
        ;;
        
    "ngrok-setup")
        echo "🌐 Ngrok Setup Instructions"
        echo "=========================="
        echo ""
        echo "1. Install ngrok:"
        echo "   • Download from: https://ngrok.com/"
        echo "   • Or: brew install ngrok (macOS)"
        echo ""
        echo "2. Start ngrok tunnel:"
        echo "   ngrok http 3000"
        echo ""
        echo "3. Copy the HTTPS URL (e.g., https://abc123.ngrok-free.app)"
        echo ""
        echo "4. Update your configuration:"
        if [ -f env-manager.sh ]; then
            echo "   ./env-manager.sh update-ngrok <your-ngrok-url>"
        else
            echo "   Update FRONTEND_URL in your .env file"
        fi
        echo ""
        echo "5. Restart backend to apply CORS changes:"
        echo "   docker-compose restart backend"
        ;;
        
    *)
        echo "🛠️ Complete Development Setup Guide"
        echo "==================================="
        echo ""
        echo "Based on your configuration files:"
        echo "✅ docker-compose.dev.yml (backend services only)"
        echo "✅ env-manager.sh (environment management)"
        echo "✅ local-frontend-dev.sh (local frontend commands)"
        echo "✅ set-webhook.sh (webhook management)"
        echo ""
        echo "Usage: $0 [command]"
        echo ""   
        echo "🚀 Quick Start (Recommended):"
        echo "  fix-mongodb         - Fix MongoDB connection issues"
        echo "  start-backend       - Start backend services (Docker)"
        echo "  start-frontend      - Start frontend locally (npm)"
        echo ""
        echo "🔧 Management:"
        echo "  check-health        - Complete system health check"
        echo "  logs [service]      - View service logs"
        echo "  shell [service]     - Access container shell"
        echo "  webhook             - Set Telegram webhook"
        echo ""
        echo "🆘 Troubleshooting:"
        echo "  reset-all           - Nuclear option: reset everything"
        echo "  ngrok-setup         - Ngrok setup instructions"
        echo ""
        echo "📋 Complete Development Workflow:"
        echo "  1. $0 fix-mongodb      # Fix any MongoDB issues"
        echo "  2. $0 start-backend    # Start Docker services"
        echo "  3. $0 start-frontend   # Start React locally (new terminal)"
        echo "  4. ngrok http 3000     # Tunnel frontend (new terminal)"
        echo "  5. $0 webhook          # Set webhook to localhost:8000"
        echo ""
        echo "🏗️ Your Architecture:"
        echo "  🐳 Docker Services: MongoDB, Redis, Backend API, Queue Workers"
        echo "  💻 Local Frontend: React on localhost:3000 (npm start)"
        echo "  🌐 Ngrok Tunnel: Exposes frontend for Telegram Web App"
        echo "  🔗 Webhook: localhost:8000/api/telegram/webhook"
        echo ""
        echo "❌ Current Issue:"
        echo "  Backend (Docker) cannot connect to MongoDB (Docker)"
        echo "  Solution: Run $0 fix-mongodb first"
        ;;
esac