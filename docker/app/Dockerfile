# docker/app/Dockerfile — image commune app / reverb / queue
FROM php:8.3-fpm-alpine

# Dépendances système
#  - libpng / libjpeg-turbo / libwebp / freetype : pour GD ci-dessous. Toute
#    image générée doit avoir son jumeau .webp (~4 % du poids du PNG, servi en
#    priorité par BibliothequeImages::url()), et la conversion se fait à
#    l'écriture (App\Partie\Images\ConvertisseurWebp) plutôt que dans un script
#    à relancer — une étape à penser est une étape oubliée.
RUN apk add --no-cache \
    git curl zip unzip icu-dev libzip-dev oniguruma-dev linux-headers \
    libpng-dev libjpeg-turbo-dev libwebp-dev freetype-dev $PHPIZE_DEPS

# Extensions PHP nécessaires au projet
#  - pdo_mysql : MariaDB
#  - pcntl     : queue:work / reverb (signaux, timeouts)
#  - sockets   : Reverb (WebSocket)
#  - gd        : jumeaux .webp des images générées (--with-webp OBLIGATOIRE :
#                sans lui `imagewebp` n'existe pas, et la conversion se
#                désactiverait en silence — elle est best-effort par dessein)
#  - intl, zip, bcmath, opcache : Laravel standard
RUN docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql pcntl sockets gd intl zip bcmath opcache mbstring

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
