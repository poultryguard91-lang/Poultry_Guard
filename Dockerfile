FROM php:8.2-apache

RUN docker-php-ext-install pdo_mysql \
    && rm -f /etc/apache2/mods-enabled/mpm_*.load /etc/apache2/mods-enabled/mpm_*.conf \
    && a2enmod mpm_prefork headers expires rewrite \
    && sed -ri 's/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf \
    && echo 'ServerName localhost' > /etc/apache2/conf-available/servername.conf \
    && a2enconf servername

WORKDIR /var/www/html
COPY . /var/www/html/
RUN mkdir -p logs && chown -R www-data:www-data logs \
    && sed -i 's/\r$//' deploy/start.sh \
    && chmod +x deploy/start.sh

# Railway injects $PORT; start.sh points Apache at it.
EXPOSE 8080
CMD ["/var/www/html/deploy/start.sh"]
