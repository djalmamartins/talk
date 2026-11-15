# Connect Talk — imagem de produção (site + worker usam a mesma imagem)
# Construir a partir da raiz do projeto: docker compose -f deploy/docker-compose.yml build

FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --optimize-autoloader --no-scripts --ignore-platform-reqs

FROM php:8.2-apache
# curl e sodium já vêm na imagem oficial; só compila o que falta (sem baixar pacotes do sistema)
RUN set -eux; \
    docker-php-ext-install -j"$(nproc)" pdo_mysql pcntl opcache; \
    a2enmod rewrite headers; \
    a2dismod -f deflate || true

COPY deploy/php.ini /usr/local/etc/php/conf.d/zz-talk.ini
COPY deploy/apache.conf /etc/apache2/sites-available/000-default.conf

WORKDIR /var/www/talk
COPY --from=vendor /app/vendor ./vendor
COPY bootstrap.php composer.json ./
COPY bin ./bin
COPY migrations ./migrations
COPY public ./public
COPY src ./src
COPY views ./views
COPY deploy/entrypoint.sh /usr/local/bin/talk-entrypoint

# arquivos das conversas fora da pasta pública, em volume
ENV TALK_STORAGE=/var/talk/storage
RUN mkdir -p /var/talk/storage && chown -R www-data:www-data /var/talk && chmod +x bin/talk-worker /usr/local/bin/talk-entrypoint

ENTRYPOINT ["talk-entrypoint"]
CMD ["apache2-foreground"]
EXPOSE 80
HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
  CMD php -r 'exit(@file_get_contents("http://127.0.0.1/health") === false ? 1 : 0);'
