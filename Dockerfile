FROM php:8.2-apache

# Install dependencies dan ekstensi PHP yang dibutuhkan (pdo_mysql, gd, dll)
RUN apt-get update && apt-get install -y \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libzip-dev \
    zip \
    unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) pdo_mysql gd \
    && rm -rf /var/lib/apt/lists/*

# Aktifkan Apache rewrite dan headers module (wajib untuk .htaccess & SPA routing)
RUN a2enmod rewrite headers

# Izinkan AllowOverride All agar file .htaccess berfungsi
RUN sed -i '/<Directory \/var\/www\/>/,/<\/Directory>/ s/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf

# Konfigurasi upload limit PHP
RUN echo "upload_max_filesize = 50M" > /usr/local/etc/php/conf.d/uploads.ini \
    && echo "post_max_size = 50M" >> /usr/local/etc/php/conf.d/uploads.ini \
    && echo "memory_limit = 256M" >> /usr/local/etc/php/conf.d/uploads.ini

# Salin source code project ke direktori web Apache
WORKDIR /var/www/html
COPY . /var/www/html/

# Atur permissions untuk folder uploads
RUN mkdir -p /var/www/html/uploads \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html \
    && chmod -R 777 /var/www/html/uploads

EXPOSE 80

CMD ["apache2-foreground"]

