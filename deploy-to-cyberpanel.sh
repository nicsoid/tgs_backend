#!/bin/bash
# deploy-to-cyberpanel.sh - Deployment script for CyberPanel VPS

set -e

echo "🌐 Deploying Telegram Scheduler to CyberPanel VPS"
echo "================================================="

# ===============================================
# Configuration
# ===============================================

VPS_HOST="${VPS_HOST:-your-vps-ip}"
VPS_USER="${VPS_USER:-root}"
DOMAIN="${DOMAIN:-scheduler.yourdomain.com}"
EMAIL="${EMAIL:-admin@yourdomain.com}"

# CyberPanel specific paths
CYBERPANEL_VHOSTS="/home/${DOMAIN}"
APP_DIR="${CYBERPANEL_VHOSTS}/telegram-scheduler"
PUBLIC_HTML="${CYBERPANEL_VHOSTS}/public_html"

echo "📋 Configuration:"
echo "  VPS Host: $VPS_HOST"
echo "  Domain: $DOMAIN"
echo "  Email: $EMAIL"
echo "  App Directory: $APP_DIR"
echo ""

# ===============================================
# CyberPanel Setup Functions
# ===============================================

setup_cyberpanel_site() {
    echo "🌐 Setting up CyberPanel website..."
    
    ssh "$VPS_USER@$VPS_HOST" << EOF
        # Check if CyberPanel is running
        if ! command -v cyberpanel &> /dev/null; then
            echo "❌ CyberPanel not found. Please install CyberPanel first."
            exit 1
        fi
        
        # Create website through CyberPanel CLI (if available)
        # Note: You might need to create the website manually through CyberPanel web interface
        echo "📝 Please ensure the website '$DOMAIN' is created in CyberPanel"
        echo "   1. Log into CyberPanel web interface"
        echo "   2. Go to Websites > Create Website"
        echo "   3. Create: $DOMAIN"
        echo "   4. Enable SSL through CyberPanel"
        
        # Create application directory
        mkdir -p $APP_DIR
        chown cyberpanel:cyberpanel $APP_DIR
        
        echo "✅ CyberPanel site setup prepared"
EOF
}

deploy_application() {
    echo "📤 Deploying application to CyberPanel VPS..."
    
    # Create deployment package
    echo "📦 Creating deployment package..."
    mkdir -p dist
    
    # Copy necessary files
    cp -r backend dist/
    cp -r frontend dist/
    cp -r docker dist/
    cp docker-compose.cyberpanel.yml dist/docker-compose.yml
    cp .env.prod dist/.env
    
    # Create deployment archive
    tar -czf telegram-scheduler-cyberpanel.tar.gz -C dist .
    
    # Upload to VPS
    echo "📤 Uploading to VPS..."
    scp telegram-scheduler-cyberpanel.tar.gz "$VPS_USER@$VPS_HOST:/tmp/"
    
    # Extract and setup on VPS
    ssh "$VPS_USER@$VPS_HOST" << EOF
        cd /tmp
        tar -xzf telegram-scheduler-cyberpanel.tar.gz -C $APP_DIR
        cd $APP_DIR
        
        # Set permissions
        chown -R cyberpanel:cyberpanel $APP_DIR
        chmod +x docker/entrypoint.sh
        chmod +x scripts/*.sh 2>/dev/null || true
        
        echo "✅ Application deployed to $APP_DIR"
EOF
    
    # Cleanup
    rm -rf dist telegram-scheduler-cyberpanel.tar.gz
}

setup_cyberpanel_docker() {
    echo "🐳 Setting up Docker for CyberPanel..."
    
    ssh "$VPS_USER@$VPS_HOST" << EOF
        cd $APP_DIR
        
        # Ensure Docker is available
        if ! command -v docker &> /dev/null; then
            echo "Installing Docker..."
            curl -fsSL https://get.docker.com -o get-docker.sh
            sh get-docker.sh
            systemctl enable docker
            systemctl start docker
        fi
        
        # Install Docker Compose if not available
        if ! command -v docker-compose &> /dev/null; then
            echo "Installing Docker Compose..."
            curl -L "https://github.com/docker/compose/releases/latest/download/docker-compose-\$(uname -s)-\$(uname -m)" -o /usr/local/bin/docker-compose
            chmod +x /usr/local/bin/docker-compose
        fi
        
        # Add cyberpanel user to docker group
        usermod -aG docker cyberpanel
        
        echo "✅ Docker setup completed"
EOF
}

configure_cyberpanel_environment() {
    echo "⚙️ Configuring environment for CyberPanel..."
    
    ssh "$VPS_USER@$VPS_HOST" << EOF
        cd $APP_DIR
        
        # Update environment for production
        cat > .env << 'ENVEOF'
# Production Environment for CyberPanel
APP_NAME="Telegram Scheduler"
APP_ENV=production
APP_KEY=base64:wtqQ/6f+i9YERhRjXUttSgRw4VCEtn+zSsCyZioemZs=
APP_DEBUG=false
APP_URL=https://$DOMAIN

# Database Configuration
DB_CONNECTION=mongodb
MONGO_DB_CONNECTION=mongodb://admin:\$(openssl rand -base64 32 | tr -d '=+/')@mongodb:27017/telegram_scheduler?authSource=admin
DB_DATABASE=telegram_scheduler
DB_AUTHENTICATION_DATABASE=admin
MONGO_ROOT_USERNAME=admin
MONGO_ROOT_PASSWORD=\$(openssl rand -base64 32 | tr -d '=+/')

# Queue & Cache Configuration
QUEUE_CONNECTION=redis
CACHE_STORE=redis
SESSION_DRIVER=redis
REDIS_HOST=redis
REDIS_PORT=6379
REDIS_PASSWORD=\$(openssl rand -base64 32 | tr -d '=+/')

# JWT Configuration
JWT_SECRET=$JWT_SECRET
JWT_TTL=60
JWT_REFRESH_TTL=20160

# Telegram Bot Configuration
TELEGRAM_BOT_TOKEN=$TELEGRAM_BOT_TOKEN
TELEGRAM_BOT_USERNAME=$TELEGRAM_BOT_USERNAME

# Admin Configuration
ADMIN_TELEGRAM_IDS=$ADMIN_TELEGRAM_IDS
ADMIN_EMAIL=$EMAIL

# Frontend Configuration
FRONTEND_URL=https://$DOMAIN
REACT_APP_API_URL=https://$DOMAIN
REACT_APP_TELEGRAM_BOT_USERNAME=$TELEGRAM_BOT_USERNAME

# CORS Configuration
CORS_ALLOWED_ORIGINS=https://$DOMAIN

# Stripe Production Configuration (UPDATE THESE!)
STRIPE_KEY=sk_live_YOUR_LIVE_SECRET_KEY
STRIPE_PUBLIC_KEY=pk_live_YOUR_LIVE_PUBLIC_KEY
STRIPE_WEBHOOK_SECRET=whsec_YOUR_LIVE_WEBHOOK_SECRET
STRIPE_PRICE_ID_PRO=price_YOUR_PROD_PRO_PRICE_ID
STRIPE_PRODUCT_ID_PRO=prod_YOUR_PROD_PRO_PRODUCT_ID
STRIPE_PRICE_ID_ULTRA=price_YOUR_PROD_ULTRA_PRICE_ID
STRIPE_PRODUCT_ID_ULTRA=prod_YOUR_PROD_ULTRA_PRODUCT_ID

# SSL Configuration
SSL_EMAIL=$EMAIL
DOMAIN=$DOMAIN

# CyberPanel specific
CYBERPANEL_ENABLED=true
PUBLIC_HTML_PATH=$PUBLIC_HTML
ENVEOF
        
        echo "✅ Environment configured for CyberPanel"
EOF
}

create_cyberpanel_docker_compose() {
    echo "📝 Creating CyberPanel-compatible Docker Compose..."
    
    cat > docker-compose.cyberpanel.yml << 'EOF'
# docker-compose.yml - CyberPanel Production Configuration
version: "3.8"

services:
  # MongoDB Database
  mongodb:
    image: mongo:7
    container_name: telegram-scheduler-mongodb
    restart: unless-stopped
    ports:
      - "127.0.0.1:27017:27017"  # Bind to localhost only
    environment:
      - MONGO_INITDB_ROOT_USERNAME=${MONGO_ROOT_USERNAME}
      - MONGO_INITDB_ROOT_PASSWORD=${MONGO_ROOT_PASSWORD}
      - MONGO_INITDB_DATABASE=${DB_DATABASE}
    volumes:
      - mongodb_data:/data/db
      - ./docker/mongodb/mongod.conf:/etc/mongod.conf
    networks:
      - telegram-scheduler
    command: mongod --config /etc/mongod.conf

  # Redis Cache & Queue
  redis:
    image: redis:7-alpine
    container_name: telegram-scheduler-redis
    restart: unless-stopped
    ports:
      - "127.0.0.1:6379:6379"  # Bind to localhost only
    environment:
      - REDIS_PASSWORD=${REDIS_PASSWORD}
    volumes:
      - redis_data:/data
      - ./docker/redis/redis.conf:/etc/redis/redis.conf
    networks:
      - telegram-scheduler
    command: redis-server /etc/redis/redis.conf --requirepass ${REDIS_PASSWORD}

  # Backend (Laravel API)
  backend:
    build:
      context: ./backend
      dockerfile: Dockerfile
      target: production
    container_name: telegram-scheduler-backend
    restart: unless-stopped
    ports:
      - "127.0.0.1:8000:8000"  # Bind to localhost only
    environment:
      - APP_ENV=production
      - APP_DEBUG=false
      - APP_KEY=${APP_KEY}
      - APP_URL=${APP_URL}
      - DB_CONNECTION=${DB_CONNECTION}
      - MONGO_DB_CONNECTION=${MONGO_DB_CONNECTION}
      - DB_DATABASE=${DB_DATABASE}
      - QUEUE_CONNECTION=${QUEUE_CONNECTION}
      - CACHE_STORE=${CACHE_STORE}
      - REDIS_HOST=redis
      - REDIS_PASSWORD=${REDIS_PASSWORD}
      - JWT_SECRET=${JWT_SECRET}
      - TELEGRAM_BOT_TOKEN=${TELEGRAM_BOT_TOKEN}
      - TELEGRAM_BOT_USERNAME=${TELEGRAM_BOT_USERNAME}
      - ADMIN_TELEGRAM_IDS=${ADMIN_TELEGRAM_IDS}
      - STRIPE_KEY=${STRIPE_KEY}
      - STRIPE_PUBLIC_KEY=${STRIPE_PUBLIC_KEY}
      - STRIPE_WEBHOOK_SECRET=${STRIPE_WEBHOOK_SECRET}
      - CONTAINER_ROLE=app
    volumes:
      - ./backend:/var/www/html
      - backend_storage:/var/www/html/storage
      - backend_uploads:/var/www/html/storage/app/public
      # Mount to CyberPanel public_html for static files
      - ${PUBLIC_HTML_PATH}/storage:/var/www/html/storage/app/public
    networks:
      - telegram-scheduler
    depends_on:
      - mongodb
      - redis

  # Queue Workers
  queue-worker:
    build:
      context: ./backend
      dockerfile: Dockerfile
      target: production
    restart: unless-stopped
    environment:
      - APP_ENV=production
      - APP_KEY=${APP_KEY}
      - DB_CONNECTION=${DB_CONNECTION}
      - MONGO_DB_CONNECTION=${MONGO_DB_CONNECTION}
      - QUEUE_CONNECTION=${QUEUE_CONNECTION}
      - REDIS_HOST=redis
      - REDIS_PASSWORD=${REDIS_PASSWORD}
      - JWT_SECRET=${JWT_SECRET}
      - TELEGRAM_BOT_TOKEN=${TELEGRAM_BOT_TOKEN}
      - CONTAINER_ROLE=queue
    volumes:
      - ./backend:/var/www/html
      - backend_storage:/var/www/html/storage
    networks:
      - telegram-scheduler
    depends_on:
      - backend
    deploy:
      replicas: 2
    command: php artisan queue:work redis --sleep=1 --tries=3 --max-time=3600

  # Scheduler
  scheduler:
    build:
      context: ./backend
      dockerfile: Dockerfile
      target: production
    restart: unless-stopped
    environment:
      - APP_ENV=production
      - APP_KEY=${APP_KEY}
      - DB_CONNECTION=${DB_CONNECTION}
      - MONGO_DB_CONNECTION=${MONGO_DB_CONNECTION}
      - REDIS_HOST=redis
      - REDIS_PASSWORD=${REDIS_PASSWORD}
      - JWT_SECRET=${JWT_SECRET}
      - TELEGRAM_BOT_TOKEN=${TELEGRAM_BOT_TOKEN}
      - CONTAINER_ROLE=scheduler
    volumes:
      - ./backend:/var/www/html
      - backend_storage:/var/www/html/storage
    networks:
      - telegram-scheduler
    depends_on:
      - backend
    command: >
      sh -c "
        while true; do
          php artisan schedule:run --verbose --no-interaction
          sleep 60
        done
      "

networks:
  telegram-scheduler:
    driver: bridge

volumes:
  mongodb_data:
    driver: local
  redis_data:
    driver: local
  backend_storage:
    driver: local
  backend_uploads:
    driver: local
EOF
}

setup_cyberpanel_nginx() {
    echo "🌐 Setting up Nginx configuration for CyberPanel..."
    
    ssh "$VPS_USER@$VPS_HOST" << EOF
        cd $APP_DIR
        
        # Create Nginx configuration for CyberPanel
        # Note: CyberPanel manages Nginx, so we create a custom config
        cat > nginx-cyberpanel.conf << 'NGINXEOF'
# Custom Nginx configuration for Telegram Scheduler on CyberPanel

# API Backend proxy
location /api/ {
    proxy_pass http://127.0.0.1:8000;
    proxy_set_header Host \$host;
    proxy_set_header X-Real-IP \$remote_addr;
    proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto \$scheme;
    
    # Handle large uploads
    client_max_body_size 50M;
    proxy_read_timeout 300s;
    proxy_connect_timeout 75s;
}

# Health check
location /health {
    proxy_pass http://127.0.0.1:8000/health;
    access_log off;
}

# Telegram webhook
location /api/telegram/webhook {
    proxy_pass http://127.0.0.1:8000;
    proxy_set_header Host \$host;
    proxy_set_header X-Real-IP \$remote_addr;
    proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto \$scheme;
}

# Static files (served by CyberPanel/Nginx)
location /storage/ {
    alias $PUBLIC_HTML/storage/;
    expires 1y;
    add_header Cache-Control "public, immutable";
}
NGINXEOF
        
        echo "✅ Nginx configuration created"
        echo "📝 Manual step required:"
        echo "   1. Log into CyberPanel"
        echo "   2. Go to Websites > $DOMAIN > Rewrite Rules"
        echo "   3. Add the contents of nginx-cyberpanel.conf"
        echo "   4. Or include it in the site's nginx configuration"
EOF
}

start_services() {
    echo "🚀 Starting services on CyberPanel..."
    
    ssh "$VPS_USER@$VPS_HOST" << EOF
        cd $APP_DIR
        
        # Start Docker services
        docker-compose up -d --build
        
        # Wait for services
        echo "⏳ Waiting for services to start..."
        sleep 30
        
        # Check status
        docker-compose ps
        
        # Test health
        echo "🧪 Testing health..."
        curl -f http://127.0.0.1:8000/health || echo "⚠️ Backend not responding yet"
        
        echo "✅ Services started"
EOF
}

setup_cyberpanel_ssl() {
    echo "🔒 Setting up SSL for CyberPanel..."
    
    ssh "$VPS_USER@$VPS_HOST" << EOF
        echo "📝 SSL Setup Instructions:"
        echo "=========================="
        echo "1. Log into CyberPanel web interface"
        echo "2. Go to SSL > Manage SSL"
        echo "3. Select domain: $DOMAIN"
        echo "4. Choose 'Let's Encrypt SSL'"
        echo "5. Click 'Issue SSL'"
        echo ""
        echo "CyberPanel will automatically:"
        echo "- Generate SSL certificate"
        echo "- Configure Nginx with SSL"
        echo "- Set up auto-renewal"
EOF
}

post_deployment_setup() {
    echo "🔧 Running post-deployment setup..."
    
    ssh "$VPS_USER@$VPS_HOST" << EOF
        cd $APP_DIR
        
        # Run database setup
        docker-compose exec backend php artisan migrate --force
        docker-compose exec backend php artisan db:seed --force
        docker-compose exec backend php artisan db:setup-indexes
        
        # Create management script
        cat > manage.sh << 'MANAGEEOF'
#!/bin/bash
# CyberPanel Telegram Scheduler Management

APP_DIR="$APP_DIR"
cd \$APP_DIR

case "\$1" in
    start)
        docker-compose up -d
        ;;
    stop)
        docker-compose down
        ;;
    restart)
        docker-compose restart backend
        ;;
    logs)
        docker-compose logs -f backend
        ;;
    status)
        docker-compose ps
        curl -f http://127.0.0.1:8000/health
        ;;
    shell)
        docker-compose exec backend bash
        ;;
    *)
        echo "Usage: \$0 {start|stop|restart|logs|status|shell}"
        ;;
esac
MANAGEEOF
        
        chmod +x manage.sh
        
        # Create symlink for easy access
        ln -sf $APP_DIR/manage.sh /usr/local/bin/telegram-scheduler
        
        echo "✅ Post-deployment setup completed"
EOF
}

# ===============================================
# Main Deployment Function
# ===============================================

main() {
    echo "🚀 CyberPanel Deployment Starting..."
    
    # Check required variables
    if [ -z "$TELEGRAM_BOT_TOKEN" ]; then
        echo "❌ TELEGRAM_BOT_TOKEN environment variable is required"
        exit 1
    fi
    
    # Run deployment steps
    create_cyberpanel_docker_compose
    setup_cyberpanel_site
    deploy_application
    setup_cyberpanel_docker
    configure_cyberpanel_environment
    setup_cyberpanel_nginx
    start_services
    post_deployment_setup
    setup_cyberpanel_ssl
    
    echo ""
    echo "🎉 CyberPanel Deployment Completed!"
    echo "=================================="
    echo ""
    echo "📋 Next Steps:"
    echo "1. 🌐 Configure website in CyberPanel:"
    echo "   - Create website: $DOMAIN"
    echo "   - Enable SSL through CyberPanel interface"
    echo ""
    echo "2. 🔧 Configure Nginx:"
    echo "   - Add API proxy rules from nginx-cyberpanel.conf"
    echo "   - Set document root to $PUBLIC_HTML"
    echo ""
    echo "3. 🔗 Set Telegram webhook:"
    echo "   curl -X POST \"https://api.telegram.org/bot\$TELEGRAM_BOT_TOKEN/setWebhook\" \\"
    echo "        -d '{\"url\":\"https://$DOMAIN/api/telegram/webhook\"}'"
    echo ""
    echo "4. 💳 Update Stripe webhooks:"
    echo "   - Point to: https://$DOMAIN/api/stripe/webhook"
    echo ""
    echo "🛠️ Management Commands:"
    echo "  telegram-scheduler start   - Start services"
    echo "  telegram-scheduler status  - Check status"
    echo "  telegram-scheduler logs    - View logs"
    echo ""
    echo "📊 Access Points:"
    echo "  Application: https://$DOMAIN"
    echo "  CyberPanel: https://$VPS_HOST:8090"
    echo ""
}

# ===============================================
# Command Line Interface
# ===============================================

case "${1:-deploy}" in
    deploy)
        main
        ;;
    help|--help|-h)
        echo "CyberPanel VPS Deployment Script"
        echo "================================"
        echo ""
        echo "Usage: $0 [command]"
        echo ""
        echo "Commands:"
        echo "  deploy (default)  - Full deployment"
        echo "  help             - Show this help"
        echo ""
        echo "Required Environment Variables:"
        echo "  VPS_HOST              - VPS IP address"
        echo "  DOMAIN                - Your domain name"
        echo "  EMAIL                 - Your email for SSL"
        echo "  TELEGRAM_BOT_TOKEN    - Bot token"
        echo "  TELEGRAM_BOT_USERNAME - Bot username"
        echo "  ADMIN_TELEGRAM_IDS    - Admin Telegram IDs"
        echo ""
        echo "Example:"
        echo "  export VPS_HOST=192.168.1.100"
        echo "  export DOMAIN=scheduler.example.com"
        echo "  $0 deploy"
        ;;
    *)
        echo "Unknown command: $1"
        echo "Run: $0 help"
        exit 1
        ;;
esac