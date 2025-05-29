# Telegram Post Scheduler

A comprehensive web application for scheduling posts to Telegram groups with subscription management, built with Laravel and React.

## Features

- 🔐 Telegram OAuth Authentication
- 📅 Advanced Post Scheduling with Multiple Time Slots
- 👥 Multi-Group Management
- 💳 Subscription Plans (Free, Pro, Ultra)
- 📊 Comprehensive Statistics & Analytics
- 🌍 Multi-language Support (EN, UK, RU, DE, ES)
- 💰 Multi-currency Support
- 📆 Interactive Calendar View
- ⏰ Timezone Support
- 📱 Responsive Design

## Tech Stack

- **Backend**: Laravel 10, MongoDB, JWT Authentication
- **Frontend**: React 18, Tailwind CSS, i18n
- **Database**: MongoDB (Atlas recommended)
- **Payments**: Stripe
- **Bot Integration**: Telegram Bot API
- **Deployment**: Optimized for cPanel hosting

## Quick Start

### Prerequisites

- PHP 8.0+
- Node.js 16+
- MongoDB
- Composer
- Telegram Bot Token
- Stripe Account

### Installation

1. Clone the repository:
```bash
git clone https://github.com/yourusername/telegram-scheduler.git
cd telegram-scheduler
```

2. Backend setup:
```bash
cd backend
composer install
cp .env.example .env
php artisan key:generate
php artisan jwt:secret
```

3. Frontend setup:
```bash
cd ../frontend
npm install
cp .env.example .env
```

4. Configure environment variables in both `.env` files

5. Run migrations and seeders:
```bash
cd backend
php artisan migrate
php artisan db:seed
```

6. Start development servers:
```bash
# Terminal 1 - Laravel
cd backend
php artisan serve

# Terminal 2 - React  
cd frontend
npm start

# Terminal 3 - Queue Worker
cd backend
php artisan queue:work
```

## Deployment

See `deployment/DEPLOYMENT_INSTRUCTIONS.md` for detailed cPanel deployment guide.

## License

MIT License
