# PHP 8.3 + Apache image for Railway (and any Docker PHP host).
FROM php:8.3-apache

# MySQL + common PHP extensions
RUN apt-get update \
 && apt-get install -y --no-install-recommends libpng-dev libonig-dev libzip-dev \
 && docker-php-ext-install pdo_mysql mysqli gd mbstring \
 && apt-get clean && rm -rf /var/lib/apt/lists/*

RUN a2enmod rewrite

# App code. With Railway "Root Directory = website", the build context IS the
# website/ folder, so this copies the site into Apache's web root.
COPY . /var/www/html/

# Railway assigns a dynamic $PORT; make Apache listen on it at runtime.
ENV PORT=8080
RUN printf '#!/bin/sh\nset -e\n: "${PORT:=8080}"\nsed -i "s/Listen 80/Listen ${PORT}/" /etc/apache2/ports.conf\nsed -i "s/:80>/:${PORT}>/" /etc/apache2/sites-available/000-default.conf\nexec apache2-foreground\n' > /usr/local/bin/start.sh \
 && chmod +x /usr/local/bin/start.sh

EXPOSE 8080
CMD ["/usr/local/bin/start.sh"]
