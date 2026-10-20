# syntax=docker/dockerfile:1

FROM php:8.3-apache

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public \
    TZ=Asia/Shanghai

RUN apt-get update && apt-get install -y --no-install-recommends \
        libfreetype6-dev \
        libjpeg62-turbo-dev \
        libpng-dev \
        libwebp-dev \
        libonig-dev \
        libzip-dev \
        unzip \
        curl \
        tzdata \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" gd pdo_mysql mbstring opcache zip \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

# 网站运行目录改成 public，并让 public/.htaccess 生效
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/docker-php.conf \
    && sed -ri -e 's!AllowOverride None!AllowOverride All!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/docker-php.conf

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# 依赖单独一层，改代码不用重装
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --no-progress --no-scripts

COPY . .
RUN composer dump-autoload --no-dev --optimize --no-scripts \
    && php think service:discover \
    && rm -rf .git data .env

# .env 和 runtime 放到 /data（compose 里挂载），重建容器不丢安装状态
RUN ln -sfn /data/.env /var/www/html/.env \
    && rm -rf /var/www/html/runtime \
    && ln -sfn /data/runtime /var/www/html/runtime

COPY docker/entrypoint.sh /usr/local/bin/wxredirect-entrypoint
RUN chmod +x /usr/local/bin/wxredirect-entrypoint

EXPOSE 80

HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD curl -fsS -o /dev/null http://127.0.0.1/install || exit 1

ENTRYPOINT ["/usr/local/bin/wxredirect-entrypoint"]
CMD ["apache2-foreground"]
