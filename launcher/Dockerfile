FROM php:8.3-apache
RUN apt-get update && apt-get install -y --no-install-recommends libonig-dev libcurl4-openssl-dev libpng-dev libjpeg62-turbo-dev libwebp-dev && docker-php-ext-configure gd --with-jpeg --with-webp && docker-php-ext-install -j2 pdo_mysql mbstring curl gd && a2enmod rewrite headers && rm -rf /var/lib/apt/lists/*
COPY site/ /var/www/html/
COPY host-config.php /var/www/html/config.php
RUN chmod -R a+rX /var/www/html && chown -R www-data:www-data /var/www/html/data /var/www/html/files
