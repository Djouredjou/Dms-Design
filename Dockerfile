# Utiliser une image PHP avec Apache
FROM php:8.2-apache

# Activer mod_rewrite si besoin
RUN a2enmod rewrite

# Copier les fichiers dans /var/www/html
COPY public/ /var/www/html/

# Copier les fichiers de configuration PHP (facultatif)
COPY config.php /var/www/html/

# Définir les droits
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

# Exposer le port
EXPOSE 80
