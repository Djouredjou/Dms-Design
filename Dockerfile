# Utiliser l'image officielle PHP avec Apache
FROM php:8.2-apache

# Activer mod_rewrite
RUN a2enmod rewrite

# Installer les extensions PostgreSQL
RUN apt-get update && apt-get install -y libpq-dev \
    && docker-php-ext-install pdo_pgsql pgsql

# Copier les fichiers PHP dans le dossier Apache
COPY public/ /var/www/html/

# Copier les fichiers de configuration
COPY config.php /var/www/html/

# Fixer les permissions
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

# Exposer le port
EXPOSE 80
