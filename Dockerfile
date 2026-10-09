FROM php:8.2-apache

# Kwemera ama-extension ya Postgres muli Linux server
RUN apt-get update && apt-get install -y libpq-dev \
    && docker-php-ext-install pdo pdo_pgsql

# Gukopera ama-folder yose mu myanya yabyo muli server
COPY . /var/www/html/

# Guha uburenganzira bwa Apache server
RUN chown -R www-data:www-data /var/www/html/

EXPOSE 80
