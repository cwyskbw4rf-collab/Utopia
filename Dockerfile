FROM php:8.4-cli

# Install build dependencies
RUN apt-get update && apt-get install -y \
    build-essential \
    git \
    unzip \
    redis-tools \
    libssl-dev \
    && rm -rf /var/lib/apt/lists/*

# Install Redis extension
RUN pecl install redis && docker-php-ext-enable redis

# Install Swoole with proper flags (optional - allow failure)
RUN pecl install swoole \
    && docker-php-ext-enable swoole \
    || echo "Swoole installation skipped"

# Install Composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

WORKDIR /app

# Copy composer files
COPY composer.json composer.lock* ./

# Install PHP dependencies (skip Swoole if not available)
RUN composer install --no-dev --optimize-autoloader --ignore-platform-reqs || \
    composer install --no-dev --optimize-autoloader

# Copy application code
COPY . .

EXPOSE ${PORT:-3000}

CMD ["php", "-S", "0.0.0.0:${PORT:-3000}", "-t", "public"]
