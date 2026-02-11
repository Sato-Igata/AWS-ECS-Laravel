# apps/server/Dockerfile

FROM php:8.4-apache
# FROM php:8.3-apache
# FROM php:8.3-fpm

# DocumentRoot を Laravel の public に変更
ARG APACHE_DOCUMENT_ROOT=/var/www/html/public

# 必要パッケージと PHP拡張
# RUN apt-get update && apt-get install -y \
#     git \
#     unzip \
#     libzip-dev \
#   && docker-php-ext-install pdo pdo_mysql zip \
#   && apt-get clean && rm -rf /var/lib/apt/lists/*
# 必要パッケージ + PHP拡張（Cashierの依存で bcmath が必要）
RUN apt-get update && apt-get install -y \
    git unzip libzip-dev \
  && docker-php-ext-install pdo pdo_mysql zip bcmath \
  && apt-get clean && rm -rf /var/lib/apt/lists/*

# server(Docker) に Composer を入れる
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN sed -ri -e "s!/var/www/html!${APACHE_DOCUMENT_ROOT}!g" \
    /etc/apache2/sites-available/000-default.conf \
    /etc/apache2/apache2.conf \
    /etc/apache2/conf-available/*.conf \
  && a2enmod rewrite

# Laravel を置く場所
WORKDIR /var/www/html

# build 時用に laravel ディレクトリをコピー
# （context は ./apps/server なので、相対パスで laravel/ を指定）
COPY laravel/ ./
RUN composer install --no-interaction --prefer-dist
RUN php artisan package:discover --ansi || true

# storage/bootstrap/cache が存在する場合のみ権限調整
RUN if [ -d storage ] && [ -d bootstrap/cache ]; then \
      chown -R www-data:www-data storage bootstrap/cache; \
    else \
      echo "storage or bootstrap/cache not found at build time"; \
    fi

# .env が無いと Laravel が 500 になりがちなので、最低限の .env を用意
RUN if [ ! -f .env ]; then cp .env.example .env; fi \
 && php artisan key:generate --force \
 && php artisan config:clear || true \
 && php artisan route:clear || true

# .htaccess を有効化（Laravel の rewrite を効かせる）
RUN printf '%s\n' \
  '<Directory /var/www/html/public>' \
  '    AllowOverride All' \
  '    Require all granted' \
  '</Directory>' \
  > /etc/apache2/conf-available/laravel.conf \
  && a2enconf laravel

CMD ["apachectl", "-D", "FOREGROUND"]
