FROM php:8.4-cli

# Install required extensions
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    redis-tools \
    && rm -rf /var/lib/apt/lists/*

# Install Swoole and Redis extensions
RUN pecl install swoole redis && \
    docker-php-ext-enable swoole redis

# Install Composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

WORKDIR /app

# Copy composer files
COPY composer.json composer.lock* ./

# Install PHP dependencies
RUN composer install --no-dev --optimize-autoloader

# Copy application code
COPY . .

EXPOSE ${PORT:-3000}

CMD ["php", "-S", "0.0.0.0:${PORT:-3000}", "-t", "public"]
