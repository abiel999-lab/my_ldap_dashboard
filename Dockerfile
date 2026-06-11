FROM php:8.4-apache

WORKDIR /var/www/html

RUN apt-get update && apt-get install -y \
    git unzip zip curl netcat-openbsd nodejs npm \
    libpq-dev postgresql-client libldap2-dev ldap-utils libpng-dev libjpeg-dev libfreetype6-dev \
    libonig-dev libxml2-dev libicu-dev libzip-dev \
    && docker-php-ext-configure ldap --with-libdir=lib/x86_64-linux-gnu \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo pdo_pgsql pgsql ldap mbstring xml gd bcmath intl zip opcache \
    && a2enmod rewrite headers \
    && echo "ServerName localhost" >> /etc/apache2/apache2.conf \
    && curl -L -o /usr/local/bin/kubectl https://dl.k8s.io/release/v1.33.9/bin/linux/amd64/kubectl \
    && chmod +x /usr/local/bin/kubectl \
    && kubectl version --client=true \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY . .

RUN composer install --no-dev --optimize-autoloader --no-interaction

RUN if [ -f package.json ]; then \
      npm install && npm run build; \
    fi

RUN php artisan filament:assets || true


# Ensure Laravel public storage symlink exists in container image
RUN mkdir -p /var/www/html/storage/app/public /var/www/html/public \
    && ln -sfn /var/www/html/storage/app/public /var/www/html/public/storage

RUN chown -R www-data:www-data storage bootstrap/cache public \
    && chmod -R 775 storage bootstrap/cache public

RUN sed -i 's|DocumentRoot /var/www/html|DocumentRoot /var/www/html/public|g' \
    /etc/apache2/sites-available/000-default.conf

RUN printf '\n<Directory /var/www/html/public>\n\
    AllowOverride All\n\
    Require all granted\n\
</Directory>\n' >> /etc/apache2/apache2.conf

EXPOSE 80

CMD ["apache2-foreground"]
