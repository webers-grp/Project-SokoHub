FROM php:8.3-apache

# Install MySQL PDO and mysqli extensions required by SokoHub
RUN docker-php-ext-install pdo_mysql mysqli

# Enable Apache rewrite module for .htaccess rules
RUN a2enmod rewrite

# Copy application source into Apache document root
COPY . /var/www/html/

# Ensure uploads directory is writable
RUN chown -R www-data:www-data /var/www/html/assets/img/uploads \
    && chmod -R 755 /var/www/html

EXPOSE 80
