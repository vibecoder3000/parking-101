# Render (and any other Docker host) builds this image. The app is one PHP file plus a
# MySQL database that lives outside the container.
FROM php:8.3-apache

# PDO_MySQL is the only extension the app needs; opcache is a free speed-up.
RUN docker-php-ext-install -j"$(nproc)" pdo_mysql opcache \
 && mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

# The application is a single file, served as the site root. config.php is deliberately not
# copied: a hosted deploy takes its credentials from the environment instead.
COPY parking.php /var/www/html/index.php

RUN printf '%s\n' \
    '<Directory /var/www/html>' \
    '  Options -Indexes' \
    '  AllowOverride None' \
    '</Directory>' \
    'ServerTokens Prod' \
    'ServerSignature Off' \
    'ServerName localhost' \
    > /etc/apache2/conf-available/parking.conf \
 && a2enconf parking

# Render assigns the port at runtime; 8080 is the local default.
ENV PORT=8080
EXPOSE 8080
CMD ["sh", "-c", "sed -ri \"s/^Listen 80$/Listen ${PORT}/\" /etc/apache2/ports.conf && sed -ri \"s/:80>/:${PORT}>/\" /etc/apache2/sites-available/000-default.conf && exec apache2-foreground"]
