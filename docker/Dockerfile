FROM php:8.2-apache

RUN apt-get update \
 && apt-get install -y --no-install-recommends curl tzdata \
 && rm -rf /var/lib/apt/lists/* \
 && docker-php-ext-install pdo_mysql \
 && a2enmod headers

COPY docker/php.ini /usr/local/etc/php/conf.d/zz-batsignal.ini
COPY docker/apache-vhost.conf /etc/apache2/sites-available/000-default.conf
COPY docker/runner-loop.sh /usr/local/bin/runner-loop.sh
COPY . /var/www/html

# Files copied from Windows may carry CRLF line endings, which break shell scripts.
RUN sed -i 's/\r$//' /usr/local/bin/runner-loop.sh \
 && chmod +x /usr/local/bin/runner-loop.sh \
 && chown -R www-data:www-data /var/www/html

WORKDIR /var/www/html
