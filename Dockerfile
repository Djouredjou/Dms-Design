# 1. Utiliser l'image PHP officielle avec Apache
FROM php:8.2-apache

# 2. Activer mod_rewrite (utile pour .htaccess si nécessaire)
RUN a2enmod rewrite

# 3. Copier les fichiers de ton projet dans le conteneur
COPY . /var/www/html/

# 4. Définir le répertoire racine comme dossier "public"
WORKDIR /var/www/html/public

# 5. Donner les bons droits
RUN chown -R www-data:www-data /var/www/html

# 6. Installer les extensions PHP nécessaires
RUN docker-php-ext-install mysqli pdo pdo_mysql

# 7. Installer Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/html/
RUN composer install || true  # en cas de vendor/ déjà présent

# 8. Exposer le port utilisé par Apache
EXPOSE 80
