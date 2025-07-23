#!/bin/bash
# dev-local.sh - Local frontend development commands

case "$1" in
    "start")
        echo "🚀 Starting backend services with Docker..."
        docker-compose -f docker-compose.dev.yml up -d
        
        echo "⏳ Waiting for services to be ready..."
        sleep 30
        
        echo "📊 Backend services status:"
        docker-compose -f docker-compose.dev.yml ps
        
        echo "🧪 Testing backend health..."
        if curl -f -s http://localhost:8000/health; then
            echo "✅ Backend is ready!"
        else
            echo "⚠️ Backend not ready yet, check logs"
        fi
        
        echo ""
        echo "📋 Next steps:"
        echo "1. Start frontend: cd frontend && npm start"
        echo "2. Start ngrok: ngrok http 3000"
        echo "3. Update ngrok URL: ./env-manager.sh update-ngrok <url>"
        echo "4. Set webhook: ./set-webhook.sh"
        ;;
        
    "frontend")
        echo "🌐 Starting frontend locally..."
        cd frontend
        
        if [ ! -d node_modules ]; then
            echo "📦 Installing dependencies..."
            npm install
        fi
        
        echo "🚀 Starting React development server..."
        npm start
        ;;
        
    "stop")
        echo "🛑 Stopping backend services..."
        docker-compose -f docker-compose.dev.yml down
        ;;
        
    "logs")
        service="${2:-backend}"
        echo "📄 Showing logs for $service..."
        docker-compose -f docker-compose.dev.yml logs -f --tail=50 $service
        ;;
        
    "shell")
        service="${2:-backend}"
        echo "🐚 Opening shell in $service..."
        docker-compose -f docker-compose.dev.yml exec $service bash
        ;;
        
    "status")
        echo "📊 Development Environment Status"
        echo "================================"
        
        echo "🐳 Docker services:"
        docker-compose -f docker-compose.dev.yml ps
        
        echo ""
        echo "🔗 Service health:"
        curl -f -s http://localhost:8000/health && echo "✅ Backend API" || echo "❌ Backend API"
        curl -f -s http://localhost:27017 && echo "✅ MongoDB" || echo "❌ MongoDB"
        curl -f -s http://localhost:6379 && echo "✅ Redis" || echo "❌ Redis"
        curl -f -s http://localhost:3000 && echo "✅ Frontend" || echo "❌ Frontend (not running locally)"
        
        echo ""
        echo "📱 Port usage:"
        echo "  3000: Frontend (local npm start)"
        echo "  8000: Backend API (Docker)"
        echo "  27017: MongoDB (Docker)"
        echo "  6379: Redis (Docker)"
        echo "  8081: Redis UI (Docker - optional)"
        ;;
        
    "test")
        echo "🧪 Testing complete development setup..."
        
        echo "Backend API:"
        curl -f http://localhost:8000/health || echo "❌ Backend not responding"
        
        echo "Frontend (if running):"
        curl -f -s http://localhost:3000 >/dev/null && echo "✅ Frontend responding" || echo "❌ Frontend not running (run: ./dev-local.sh frontend)"
        
        echo "Database connection:"
        docker-compose -f docker-compose.dev.yml exec -T backend php artisan tinker --execute="echo 'DB connection: '; try { DB::connection('mongodb')->listCollections(); echo 'OK'; } catch(Exception \$e) { echo 'Failed: ' . \$e->getMessage(); }" || echo "❌ Database test failed"
        ;;
        
    *)
        echo "🛠️ Local Frontend Development Commands"
        echo "====================================="
        echo ""
        echo "Usage: $0 [command]"
        echo ""
        echo "Commands:"
        echo "  start           - Start backend services (Docker)"
        echo "  frontend        - Start frontend (local npm start)"
        echo "  stop            - Stop backend services"
        echo "  status          - Show all services status"
        echo "  logs [service]  - View service logs"
        echo "  shell [service] - Access service shell"
        echo "  test            - Test complete setup"
        echo ""
        echo "Development Workflow:"
        echo "  1. ./dev-local.sh start        # Start backend"
        echo "  2. ./dev-local.sh frontend     # Start frontend"
        echo "  3. ngrok http 3000             # Expose frontend"
        echo ""
        echo "Architecture:"
        echo "  🐳 MongoDB, Redis, Backend, Queue, Scheduler: Docker"
        echo "  💻 Frontend: Local (npm start)"
        echo "  🌐 Ngrok: Tunnel to local frontend"
        ;;
esac
