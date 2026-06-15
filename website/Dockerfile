# PHP 8.3 with the built-in web server — simple and reliable on Railway.
# (Avoids the Apache "More than one MPM loaded" issue; fine for preview/dev.
#  Production on Hostinger uses Apache, which this code is fully compatible with.)
FROM php:8.3-cli

RUN apt-get update \
 && apt-get install -y --no-install-recommends libpng-dev libonig-dev libzip-dev \
 && docker-php-ext-install pdo_mysql mysqli gd mbstring \
 && apt-get clean && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html
COPY . /var/www/html/

# Railway provides a dynamic $PORT.
ENV PORT=8080
EXPOSE 8080
CMD ["sh", "-c", "php -S 0.0.0.0:${PORT:-8080} -t /var/www/html"]
