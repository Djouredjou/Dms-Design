# Utiliser une image PHP avec Apache
FROM php:8.2-apache

# Activer mod_rewrite
RUN a2enmod rewrite

# Installer l'extension PostgreSQL PDO
RUN apt-get update && apt-get install -y libpq-dev \
    && docker-php-ext-install pdo pdo_pgsql

# Copier les fichiers dans /var/www/html
COPY public/ /var/www/html/
COPY config.php /var/www/html/

# Définir les droits d’accès
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

# Exposer le port 80
EXPOSE 80
