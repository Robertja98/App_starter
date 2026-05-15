#!/bin/bash
# Local Development Server Startup Script
# Runs PHP built-in server on localhost:8000
# 
# Usage:
#   bash dev-server.sh
# 
# Then access the app at: http://localhost:8000/
# API endpoints at: http://localhost:8000/api/auth/login (for example)

cd "$(dirname "$0")"

echo "========================================="
echo "PHP MySQL PWA Starter - Dev Server"
echo "========================================="
echo ""
echo "Before starting, ensure:"
echo "  1. Local MySQL is running (service mysql start)"
echo "  2. Database created: mysql -u root -e 'CREATE DATABASE app_local_dev;'"
echo "  3. Schema imported: mysql -u root app_local_dev < database/migrations/001_initial_schema.sql"
echo ""
echo "Starting PHP built-in server on http://localhost:8000"
echo "Press Ctrl+C to stop."
echo ""

php -S localhost:8000 -t public/
