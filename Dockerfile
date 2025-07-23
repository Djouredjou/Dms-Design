# Utiliser une image PHP avec Apache
FROM php:8.2-apache

# Activer mod_rewrite
RUN a2enmod rewrite

# Copier les fichiers PHP du dossier public vers le dossier d’hébergement Apache
COPY public/ /var/www/html/

# Copier les fichiers nécessaires à la racine si besoin
COPY config.php /var/www/html/

# Définir les droits d’accès
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

# Exposer le port 80
EXPOSE 80
