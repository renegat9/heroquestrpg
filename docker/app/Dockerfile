# docker/app/Dockerfile — image commune app / reverb / queue
FROM php:8.3-fpm-alpine

# Dépendances système
#  - libwebp-tools : le binaire `cwebp`. PHP n'a ici ni gd ni imagick, et toute
#    image générée doit avoir son jumeau .webp (~4 % du poids du PNG, servi en
#    priorité par BibliothequeImages::url()). Tant que la conversion vivait dans
#    `image-tools/webp.sh`, c'était une ÉTAPE à penser après coup — et des
#    illustrations de quête sont restées servies en 1,3 Mo faute de l'avoir
#    relancé. Elle se fait désormais à l'écriture, dans le conteneur.
RUN apk add --no-cache \
    git curl zip unzip icu-dev libzip-dev oniguruma-dev linux-headers libwebp-tools $PHPIZE_DEPS

# Extensions PHP nécessaires au projet
#  - pdo_mysql : MariaDB
#  - pcntl     : queue:work / reverb (signaux, timeouts)
#  - sockets   : Reverb (WebSocket)
#  - intl, zip, bcmath, opcache : Laravel standard
RUN docker-php-ext-install pdo_mysql pcntl sockets intl zip bcmath opcache mbstring

# Redis en option (si tu passes les queues sur Redis plus tard) :
# RUN pecl install redis && docker-php-ext-enable redis

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Utilisateur non-root aligné sur l'hôte (évite les soucis de droits avec le bind mount)
ARG UID=1000
ARG GID=1000
RUN addgroup -g ${GID} laravel && adduser -D -u ${UID} -G laravel laravel
USER laravel

WORKDIR /var/www/html

# Réglages PHP raisonnables pour les jobs IA (réponses LLM longues)
USER root
RUN { \
      echo "memory_limit=512M"; \
      echo "max_execution_time=120"; \
      echo "opcache.enable=1"; \
      echo "opcache.validate_timestamps=1"; \
    } > /usr/local/etc/php/conf.d/app.ini
USER laravel

# PHP-FPM écoute sur 9000 (cible FastCGI de nginx)
EXPOSE 9000
CMD ["php-fpm"]
