FROM ubuntu:24.04 AS base
LABEL Maintainer="MAHAMOUD BRAHIM ADOUM"
LABEL Description="PHP 8.4 Laravel setup with MySQL and MSSQL"
ENV DEBIAN_FRONTEND=noninteractive

# System dependencies
RUN apt-get update && \
    apt-get install -y software-properties-common curl wget gnupg ca-certificates apt-transport-https unzip git lsb-release

# Add Microsoft SQL Server package repository (değişen yöntem)
RUN set -eux; \
    mkdir -p /etc/apt/keyrings; \
    wget -O - https://packages.microsoft.com/keys/microsoft.asc > /etc/apt/keyrings/microsoft.asc && \
    chmod go+r /etc/apt/keyrings/microsoft.asc && \
    echo "deb [signed-by=/etc/apt/keyrings/microsoft.asc] https://packages.microsoft.com/debian/12/prod bookworm main" > /etc/apt/sources.list.d/mssql-release.list

# Add PHP repository
RUN add-apt-repository -y ppa:ondrej/php

# Update package lists
RUN apt-get update

# Install ODBC and SQL Server tools
RUN ACCEPT_EULA=Y apt-get install -y \
    unixodbc \
    unixodbc-dev \
    msodbcsql18 \
    mssql-tools18

# Install PHP 8.4 and required extensions
RUN apt-get install -y \
    php8.4 \
    php8.4-cli \
    php8.4-common \
    php8.4-fpm \
    php8.4-mysql \
    php8.4-zip \
    php8.4-gd \
    php8.4-mbstring \
    php8.4-curl \
    php8.4-xml \
    php8.4-bcmath \
    php8.4-pdo \
    php8.4-bz2 \
    php8.4-dev \
    php8.4-igbinary \
    php8.4-intl \
    php8.4-opcache \
    php8.4-readline \
    php8.4-redis \
    php8.4-pgsql \
    php8.4-ssh2 \
    php8.4-soap \
    supervisor \
    nano \
    nginx

# Install SQL Server PHP extensions
RUN pecl channel-update pecl.php.net && \
    pecl install sqlsrv pdo_sqlsrv && \
    echo "extension=sqlsrv.so" > /etc/php/8.4/mods-available/sqlsrv.ini && \
    echo "extension=pdo_sqlsrv.so" > /etc/php/8.4/mods-available/pdo_sqlsrv.ini && \
    phpenmod sqlsrv pdo_sqlsrv

# Install Composer globally
RUN curl -sS https://getcomposer.org/installer | php && \
    mv composer.phar /usr/local/bin/composer

# Copy Nginx and startup config
COPY ./.docker/start.sh /start.sh
COPY ./.docker/nginx.conf /etc/nginx/nginx.conf

# Set working directory
WORKDIR /var/www
RUN rm -rf *
COPY . /var/www
RUN chown www-data:www-data * -R

# Install Laravel dependencies
RUN composer install --no-interaction --prefer-dist --optimize-autoloader

EXPOSE 80

CMD ["sh", "/start.sh"]
