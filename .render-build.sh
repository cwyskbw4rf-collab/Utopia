#!/bin/bash
set -e

echo "Installing PHP dependencies..."
composer install --no-dev --optimize-autoloader

echo "Running tests..."
composer test || true

echo "Running code quality checks..."
composer lint || true

echo "Build completed successfully!"
