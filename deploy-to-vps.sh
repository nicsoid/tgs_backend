#!/bin/bash
# deploy-to-vps.sh - Complete VPS Deployment Script

set -e

echo "🚀 Deploying Telegram Scheduler to VPS..."

# ===============================================
# Configuration
# ===============================================

VPS_HOST="${VPS_HOST:-your-vps-ip}"
VPS_USER="${VPS_USER:-root}"
APP_DIR="${APP_DIR:-/opt/telegram-scheduler}"
DOMAIN="${DOMAIN:-your-domain.com}"
EMAIL="${EMAIL:-admin@your-domain.com}"

# ===============================================
# Local Preparation
# ===============================================

prepare_local() {
    echo "📦 Preparing local files..."
    
    # Create deployment package
    mkdir -p dist
    
    # Copy necessary files
    cp -r backend dist/
    cp -r frontend dist/
    cp -r docker dist/
    cp docker-compose.yml dist/
    cp .env.example dist/.env
    
    # Create deployment archive
    tar -czf telegram-scheduler-deploy.tar.gz -C dist .
    
    echo "✅ Local preparation completed"
}

# ===============================================
# VPS Setup
# ===============================================

setup_vps() {
    echo "🔧 Setting up VPS..."
    
    ssh "$VPS_USER@$VPS_HOST" << 'EOF'
        # Update system
        apt update && apt upgrade -y
        
        # Install Docker
        curl -fsSL https://get.docker.com -o get-docker.sh
        sh get-docker.sh
        systemctl enable docker
        systemctl start docker
        
        # Install Docker Compose
        curl -L "https://github.com/docker/compose/releases/latest/download/docker-compose-$(uname -s)-$(uname -m)" -o /usr/local/bin/docker-compose
        chmod +x /usr/local/bin/docker-compose
        
        # Install additional tools
        apt install -y git curl wget unzip htop nginx-utils
        
        # Create application directory
        mkdir -p /opt/telegram-scheduler
        
        # Create SSL directory
        mkdir -p /opt/telegram-scheduler/ssl
        
        echo "✅ VPS setup completed"
EOF
}

# ===============================================
# Deploy Application
# ===============================================

deploy_app() {
    echo "📤 Deploying application..."
    
    # Upload files
    scp telegram-scheduler-deploy.tar.gz "$VPS_USER@$VPS_HOST:/opt/"
    
    # Extract and setup on VPS
    ssh "$VPS_USER@$VPS_HOST" << EOF
        cd /opt
        tar -xzf telegram-scheduler-deploy.tar.gz -C telegram-scheduler
        cd telegram-scheduler
        
        # Make scripts executable
        chmod +x docker/entrypoint.sh
        chmod +x scripts/*.sh
        
        echo "✅ Application deployed"
EOF
}

# ===============================================
# Configure Environment
# ===============================================

setup_environment() {
    echo "⚙️  Setting up environment..."
    
    # Generate app key if not provided
    if [ -z "$APP_KEY" ]; then
        APP_KEY=$(openssl rand -base64 32)
    fi
    
    # Create environment file on VPS
    ssh "$VPS_USER@$VPS_HOST" << EOF
        cd /opt/telegram-scheduler
        
        cat > .env << 'ENVEOF'
# Telegram Scheduler - Production Environment

# Application
APP_NAME="Telegram Scheduler"
APP_ENV=production
APP_KEY=base64:$APP_KEY
APP_DEBUG=false
APP_URL=https://$DOMAIN

# Database
DB_DATABASE=telegram_scheduler
MONGO_ROOT_USERNAME=admin
MONGO_ROOT_PASSWORD=$(openssl rand -base64 32)

# Redis
REDIS_PASSWORD=$(openssl rand -base64 32)

# Telegram Bot
TELEGRAM_BOT_TOKEN=$TELEGRAM_BOT_TOKEN
TELEGRAM_BOT_USERNAME=$TELEGRAM_BOT_USERNAME

# Admin
ADMIN_TELEGRAM_IDS=$ADMIN_TELEGRAM_IDS
ADMIN_EMAIL=$EMAIL

# SSL
SSL_EMAIL=$EMAIL
DOMAIN=$DOMAIN

ENVEOF
        
        echo "✅ Environment configured"
EOF
}

# ===============================================
# Setup SSL Certificate
# ===============================================

setup_ssl() {
    echo "🔒 Setting up SSL certificate..."
    
    ssh "$VPS_USER@$VPS_HOST" << EOF
        # Install Certbot
        apt install -y certbot
        
        # Get SSL certificate
        certbot certonly --standalone --email $EMAIL --agree-tos --no-eff-email -d $DOMAIN
        
        # Copy certificates to app directory
        cp /etc/letsencrypt/live/$DOMAIN/fullchain.pem /opt/telegram-scheduler/ssl/
        cp /etc/letsencrypt/live/$DOMAIN/privkey.pem /opt/telegram-scheduler/ssl/
        
        # Setup auto-renewal
        echo "0 12 * * * /usr/bin/certbot renew --quiet" | crontab -
        
        echo "✅ SSL certificate configured"
EOF
}

# ===============================================
# Start Services
# ===============================================

start_services() {
    echo "🚀 Starting services..."
    
    ssh "$VPS_USER@$VPS_HOST" << 'EOF'
        cd /opt/telegram-scheduler
        
        # Build and start containers
        docker-compose up -d --build
        
        # Wait for services to be ready
        echo "Waiting for services to start..."
        sleep 30
        
        # Check service status
        docker-compose ps
        
        echo "✅ Services started"
EOF
}

# ===============================================
# Post-deployment Setup
# ===============================================

post_deploy_setup() {
    echo "🔧 Running post-deployment setup..."
    
    ssh "$VPS_USER@$VPS_HOST" << 'EOF'
        cd /opt/telegram-scheduler
        
        # Run database migrations and setup
        docker-compose exec backend php artisan migrate --force
        docker-compose exec backend php artisan db:setup-indexes
        docker-compose exec backend php artisan app:optimize-performance
        
        # Test the application
        echo "Testing application..."
        curl -f http://localhost/health || echo "Health check failed"
        
        echo "✅ Post-deployment setup completed"
EOF
}

# ===============================================
# Setup Monitoring
# ===============================================

setup_monitoring() {
    echo "📊 Setting up monitoring..."
    
    ssh "$VPS_USER@$VPS_HOST" << 'EOF'
        # Create monitoring script
        cat > /opt/telegram-scheduler/scripts/monitor.sh << 'MONITOR_EOF'
#!/bin/bash
# Monitoring script for Telegram Scheduler

LOG_FILE="/var/log/telegram-scheduler-monitor.log"

log() {
    echo "$(date '+%Y-%m-%d %H:%M:%S') $1" | tee -a $LOG_FILE
}

# Check Docker containers
check_containers() {
    cd /opt/telegram-scheduler
    
    # Get container status
    down_containers=$(docker-compose ps --services --filter "status=exited")
    
    if [ -n "$down_containers" ]; then
        log "ERROR: Containers down: $down_containers"
        
        # Try to restart
        docker-compose up -d
        log "INFO: Attempted to restart containers"
        
        return 1
    else
        log "INFO: All containers running"
        return 0
    fi
}

# Check application health
check_health() {
    if curl -f -s http://localhost/health > /dev/null; then
        log "INFO: Application health check passed"
        return 0
    else
        log "ERROR: Application health check failed"
        return 1
    fi
}

# Check disk space
check_disk() {
    usage=$(df / | awk 'NR==2 {print $5}' | sed 's/%//')
    if [ $usage -gt 90 ]; then
        log "WARNING: Disk usage high: ${usage}%"
        return 1
    else
        log "INFO: Disk usage OK: ${usage}%"
        return 0
    fi
}

# Main monitoring
main() {
    log "Starting monitoring check"
    
    check_containers
    check_health
    check_disk
    
    log "Monitoring check completed"
}

main "$@"
MONITOR_EOF
        
        chmod +x /opt/telegram-scheduler/scripts/monitor.sh
        
        # Add to crontab (every 5 minutes)
        echo "*/5 * * * * /opt/telegram-scheduler/scripts/monitor.sh" | crontab -
        
        echo "✅ Monitoring setup completed"
EOF
}

# ===============================================
# Setup Backup
# ===============================================

setup_backup() {
    echo "💾 Setting up backup..."
    
    ssh "$VPS_USER@$VPS_HOST" << 'EOF'
        # Create backup script
        cat > /opt/telegram-scheduler/scripts/backup.sh << 'BACKUP_EOF'
#!/bin/bash
# Backup script for Telegram Scheduler

BACKUP_DIR="/opt/backups/telegram-scheduler"
DATE=$(date +%Y%m%d_%H%M%S)

# Create backup directory
mkdir -p $BACKUP_DIR

log() {
    echo "$(date '+%Y-%m-%d %H:%M:%S') $1"
}

# Backup MongoDB
backup_mongodb() {
    log "Backing up MongoDB..."
    
    docker-compose exec -T mongodb mongodump --db telegram_scheduler --archive > "$BACKUP_DIR/mongodb_$DATE.archive"
    
    if [ $? -eq 0 ]; then
        log "MongoDB backup completed: mongodb_$DATE.archive"
    else
        log "ERROR: MongoDB backup failed"
        return 1
    fi
}

# Backup application files
backup_files() {
    log "Backing up application files..."
    
    tar -czf "$BACKUP_DIR/files_$DATE.tar.gz" \
        /opt/telegram-scheduler/backend/storage \
        /opt/telegram-scheduler/.env
    
    if [ $? -eq 0 ]; then
        log "Files backup completed: files_$DATE.tar.gz"
    else
        log "ERROR: Files backup failed"
        return 1
    fi
}

# Cleanup old backups (keep last 7 days)
cleanup_old_backups() {
    log "Cleaning up old backups..."
    
    find $BACKUP_DIR -name "*.archive" -mtime +7 -delete
    find $BACKUP_DIR -name "*.tar.gz" -mtime +7 -delete
    
    log "Old backups cleaned up"
}

# Main backup process
main() {
    log "Starting backup process"
    
    cd /opt/telegram-scheduler
    
    backup_mongodb
    backup_files
    cleanup_old_backups
    
    log "Backup process completed"
}

main "$@"
BACKUP_EOF
        
        chmod +x /opt/telegram-scheduler/scripts/backup.sh
        
        # Add to crontab (daily at 2 AM)
        (crontab -l 2>/dev/null; echo "0 2 * * * /opt/telegram-scheduler/scripts/backup.sh") | crontab -
        
        echo "✅ Backup setup completed"
EOF
}

# ===============================================
# Setup Nginx with SSL
# ===============================================

setup_nginx_ssl() {
    echo "🌐 Setting up Nginx with SSL..."
    
    ssh "$VPS_USER@$VPS_HOST" << EOF
        # Create Nginx configuration
        cat > /etc/nginx/sites-available/telegram-scheduler << 'NGINX_EOF'
# Telegram Scheduler - Nginx Configuration with SSL

# Rate limiting
limit_req_zone \$binary_remote_addr zone=api:10m rate=60r/m;
limit_req_zone \$binary_remote_addr zone=webhook:10m rate=10r/s;

# Redirect HTTP to HTTPS
server {
    listen 80;
    listen [::]:80;
    server_name $DOMAIN;
    return 301 https://\$server_name\$request_uri;
}

# HTTPS Server
server {
    listen 443 ssl http2;
    listen [::]:443 ssl http2;
    server_name $DOMAIN;

    # SSL Configuration
    ssl_certificate /opt/telegram-scheduler/ssl/fullchain.pem;
    ssl_certificate_key /opt/telegram-scheduler/ssl/privkey.pem;
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers ECDHE-RSA-AES256-GCM-SHA512:DHE-RSA-AES256-GCM-SHA512:ECDHE-RSA-AES256-GCM-SHA384;
    ssl_prefer_server_ciphers off;
    ssl_session_cache shared:SSL:10m;
    ssl_session_timeout 10m;

    # Security Headers
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-XSS-Protection "1; mode=block" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header Referrer-Policy "no-referrer-when-downgrade" always;
    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;

    # Gzip compression
    gzip on;
    gzip_vary on;
    gzip_min_length 1024;
    gzip_types text/plain text/css text/xml text/javascript application/javascript application/xml+rss application/json;

    # Frontend (React)
    location / {
        proxy_pass http://127.0.0.1:3000;
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
        
        # Handle WebSocket connections
        proxy_http_version 1.1;
        proxy_set_header Upgrade \$http_upgrade;
        proxy_set_header Connection "upgrade";
    }

    # API Backend
    location /api/ {
        limit_req zone=api burst=20 nodelay;
        
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

    # Telegram webhook with special rate limiting
    location /api/telegram/webhook {
        limit_req zone=webhook burst=5 nodelay;
        
        proxy_pass http://127.0.0.1:8000;
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
    }

    # Health check
    location /health {
        access_log off;
        proxy_pass http://127.0.0.1:8000/health;
    }

    # Admin panel (optional monitoring)
    location /admin/redis {
        proxy_pass http://127.0.0.1:8081;
        auth_basic "Admin Area";
        auth_basic_user_file /etc/nginx/.htpasswd;
    }
}
NGINX_EOF

        # Enable the site
        ln -sf /etc/nginx/sites-available/telegram-scheduler /etc/nginx/sites-enabled/
        rm -f /etc/nginx/sites-enabled/default
        
        # Test and reload Nginx
        nginx -t && systemctl reload nginx
        
        echo "✅ Nginx with SSL configured"
EOF
}

# ===============================================
# Create Management Scripts
# ===============================================

create_management_scripts() {
    echo "📝 Creating management scripts..."
    
    ssh "$VPS_USER@$VPS_HOST" << 'EOF'
        mkdir -p /opt/telegram-scheduler/scripts
        
        # Application management script
        cat > /opt/telegram-scheduler/scripts/manage.sh << 'MANAGE_EOF'
#!/bin/bash
# Management script for Telegram Scheduler

APP_DIR="/opt/telegram-scheduler"
cd $APP_DIR

case "$1" in
    start)
        echo "Starting Telegram Scheduler..."
        docker-compose up -d
        ;;
    stop)
        echo "Stopping Telegram Scheduler..."
        docker-compose down
        ;;
    restart)
        echo "Restarting Telegram Scheduler..."
        docker-compose down
        docker-compose up -d
        ;;
    status)
        echo "Telegram Scheduler Status:"
        docker-compose ps
        ;;
    logs)
        service="${2:-backend}"
        echo "Showing logs for $service..."
        docker-compose logs -f --tail=100 $service
        ;;
    update)
        echo "Updating Telegram Scheduler..."
        git pull
        docker-compose build
        docker-compose down
        docker-compose up -d
        ;;
    backup)
        echo "Running backup..."
        ./scripts/backup.sh
        ;;
    restore)
        if [ -z "$2" ]; then
            echo "Usage: $0 restore <backup_date>"
            echo "Available backups:"
            ls -la /opt/backups/telegram-scheduler/
            exit 1
        fi
        echo "Restoring from backup $2..."
        docker-compose exec -T mongodb mongorestore --archive < "/opt/backups/telegram-scheduler/mongodb_$2.archive"
        ;;
    shell)
        service="${2:-backend}"
        echo "Opening shell in $service container..."
        docker-compose exec $service bash
        ;;
    *)
        echo "Usage: $0 {start|stop|restart|status|logs|update|backup|restore|shell}"
        echo ""
        echo "Examples:"
        echo "  $0 start              # Start all services"
        echo "  $0 logs backend       # Show backend logs"
        echo "  $0 logs queue-worker  # Show queue worker logs"
        echo "  $0 shell backend      # Open shell in backend container"
        echo "  $0 backup             # Run backup"
        echo "  $0 restore 20240119_140530  # Restore from specific backup"
        exit 1
        ;;
esac
MANAGE_EOF
        
        chmod +x /opt/telegram-scheduler/scripts/manage.sh
        
        # Create symlink for easy access
        ln -sf /opt/telegram-scheduler/scripts/manage.sh /usr/local/bin/telegram-scheduler
        
        echo "✅ Management scripts created"
        echo "Usage: telegram-scheduler {start|stop|restart|status|logs|update|backup|restore|shell}"
EOF
}

# ===============================================
# Setup Firewall
# ===============================================

setup_firewall() {
    echo "🔥 Setting up firewall..."
    
    ssh "$VPS_USER@$VPS_HOST" << 'EOF'
        # Install and configure UFW
        apt install -y ufw
        
        # Default policies
        ufw default deny incoming
        ufw default allow outgoing
        
        # Allow SSH
        ufw allow ssh
        
        # Allow HTTP and HTTPS
        ufw allow 80/tcp
        ufw allow 443/tcp
        
        # Allow monitoring (optional, remove if not needed)
        ufw allow from 127.0.0.1 to any port 8081
        
        # Enable firewall
        ufw --force enable
        
        echo "✅ Firewall configured"
EOF
}

# ===============================================
# Final Setup and Testing
# ===============================================

final_setup() {
    echo "🎯 Running final setup and tests..."
    
    ssh "$VPS_USER@$VPS_HOST" << 'EOF'
        cd /opt/telegram-scheduler
        
        # Wait for all services to be fully ready
        echo "Waiting for services to stabilize..."
        sleep 60
        
        # Test application endpoints
        echo "Testing application..."
        
        # Test health endpoint
        if curl -f -s https://localhost/health > /dev/null; then
            echo "✅ Health check passed"
        else
            echo "❌ Health check failed"
        fi
        
        # Test API endpoint
        if curl -f -s -k https://localhost/api/health > /dev/null; then
            echo "✅ API health check passed"
        else
            echo "❌ API health check failed"
        fi
        
        # Show final status
        echo ""
        echo "🎉 Deployment completed!"
        echo "=================================="
        echo "Application URL: https://$DOMAIN"
        echo "Redis Admin: https://$DOMAIN/admin/redis"
        echo ""
        echo "Management commands:"
        echo "  telegram-scheduler status    # Check status"
        echo "  telegram-scheduler logs      # View logs"
        echo "  telegram-scheduler restart   # Restart services"
        echo ""
        echo "Logs location:"
        echo "  Application: docker-compose logs"
        echo "  Nginx: /var/log/nginx/"
        echo "  Monitoring: /var/log/telegram-scheduler-monitor.log"
        echo ""
        echo "Next steps:"
        echo "1. Set up your Telegram bot webhook: https://$DOMAIN/api/telegram/webhook"
        echo "2. Configure your bot with @BotFather"
        echo "3. Test the application by logging in"
        echo ""
EOF
}

# ===============================================
# Main Deployment Function
# ===============================================

main() {
    echo "🚀 Telegram Scheduler VPS Deployment"
    echo "====================================="
    
    # Check required variables
    if [ -z "$TELEGRAM_BOT_TOKEN" ]; then
        echo "❌ TELEGRAM_BOT_TOKEN environment variable is required"
        exit 1
    fi
    
    if [ -z "$TELEGRAM_BOT_USERNAME" ]; then
        echo "❌ TELEGRAM_BOT_USERNAME environment variable is required"
        exit 1
    fi
    
    if [ -z "$ADMIN_TELEGRAM_IDS" ]; then
        echo "❌ ADMIN_TELEGRAM_IDS environment variable is required"
        exit 1
    fi
    
    echo "Configuration:"
    echo "  VPS Host: $VPS_HOST"
    echo "  VPS User: $VPS_USER"
    echo "  Domain: $DOMAIN"
    echo "  Email: $EMAIL"
    echo ""
    
    read -p "Continue with deployment? (y/N): " -n 1 -r
    echo
    if [[ ! $REPLY =~ ^[Yy]$ ]]; then
        echo "Deployment cancelled"
        exit 0
    fi
    
    # Run deployment steps
    prepare_local
    setup_vps
    deploy_app
    setup_environment
    setup_ssl
    setup_nginx_ssl
    start_services
    post_deploy_setup
    setup_monitoring
    setup_backup
    create_management_scripts
    setup_firewall
    final_setup
    
    echo "🎉 Deployment completed successfully!"
}

# ===============================================
# Quick Deployment Commands
# ===============================================

# Function for quick updates
quick_update() {
    echo "🔄 Quick update deployment..."
    
    prepare_local
    deploy_app
    
    ssh "$VPS_USER@$VPS_HOST" << 'EOF'
        cd /opt/telegram-scheduler
        docker-compose build
        docker-compose down
        docker-compose up -d
        echo "✅ Quick update completed"
EOF
}

# Function to show status
show_status() {
    ssh "$VPS_USER@$VPS_HOST" << 'EOF'
        cd /opt/telegram-scheduler
        echo "📊 Telegram Scheduler Status"
        echo "=========================="
        echo ""
        echo "🐳 Docker Containers:"
        docker-compose ps
        echo ""
        echo "💾 Disk Usage:"
        df -h /
        echo ""
        echo "🔄 Recent Logs:"
        tail -10 /var/log/telegram-scheduler-monitor.log 2>/dev/null || echo "No monitor logs yet"
EOF
}

# ===============================================
# Helper Functions
# ===============================================

usage() {
    echo "Usage: $0 [command]"
    echo ""
    echo "Commands:"
    echo "  deploy    - Full deployment (default)"
    echo "  update    - Quick update deployment"
    echo "  status    - Show application status"
    echo "  help      - Show this help"
    echo ""
    echo "Environment Variables:"
    echo "  VPS_HOST              - VPS IP address or hostname"
    echo "  VPS_USER              - VPS user (default: root)"
    echo "  DOMAIN                - Your domain name"
    echo "  EMAIL                 - Your email for SSL certificate"
    echo "  TELEGRAM_BOT_TOKEN    - Your Telegram bot token"
    echo "  TELEGRAM_BOT_USERNAME - Your Telegram bot username"
    echo "  ADMIN_TELEGRAM_IDS    - Comma-separated admin Telegram IDs"
    echo ""
    echo "Example:"
    echo "  export VPS_HOST=192.168.1.100"
    echo "  export DOMAIN=scheduler.example.com"
    echo "  export EMAIL=admin@example.com"
    echo "  export TELEGRAM_BOT_TOKEN=123456:ABC-DEF..."
    echo "  export TELEGRAM_BOT_USERNAME=my_scheduler_bot"
    echo "  export ADMIN_TELEGRAM_IDS=123456789,987654321"
    echo "  $0 deploy"
}

# ===============================================
# Command Line Interface
# ===============================================

case "${1:-deploy}" in
    deploy)
        main
        ;;
    update)
        quick_update
        ;;
    status)
        show_status
        ;;
    help|--help|-h)
        usage
        ;;
    *)
        echo "Unknown command: $1"
        usage
        exit 1
        ;;
esac