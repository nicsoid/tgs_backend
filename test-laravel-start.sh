#!/bin/bash
echo "🧪 Testing Laravel Server Start"
echo "==============================="

echo "Stopping and rebuilding..."
docker-compose down
docker-compose build backend
docker-compose up -d

echo ""
echo "Waiting 30 seconds for startup..."
sleep 30

echo ""
echo "📊 Container status:"
docker-compose ps

echo ""
echo "📄 Backend logs (last 20 lines):"
docker-compose logs --tail=20 backend

echo ""
echo "🧪 Testing health endpoint:"
if curl -f -s http://localhost:8000/health; then
    echo "✅ Laravel server is responding!"
else
    echo "❌ Laravel server not responding"
fi
