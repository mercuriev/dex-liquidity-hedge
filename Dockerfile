FROM php:8.2-cli

# zip is required by composer
RUN apt-get update && apt-get install -y git zlib1g-dev libzip-dev libxml2-dev zip \
 	&& apt-get clean

#dom is required by phpunit
RUN docker-php-ext-install pdo_mysql bcmath zip dom pcntl
RUN pecl install xdebug trader && docker-php-ext-enable xdebug trader

ADD https://getcomposer.org/composer-stable.phar /usr/local/bin/composer
RUN chmod +x /usr/local/bin/composer

RUN ln -s /srv/php.ini  /usr/local/etc/php/conf.d/app.ini

WORKDIR "/srv"
ENTRYPOINT ["php", "cli.php"]
