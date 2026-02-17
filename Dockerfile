FROM ubuntu:24.04

LABEL Maintainer="MAHAMOUD BRAHIM ADOUM"
LABEL Description="PHP 8.4 Laravel setup with MySQL, MSSQL and LDAP"

ENV DEBIAN_FRONTEND=noninteractive

# -------------------------------------------------
# UID / GID (CRITICAL FOR PERMISSIONS)
# -------------------------------------------------
ARG WWWUSER=1000
ARG WWWGROUP=1000

# System dependencies
RUN apt-get update && \
    apt-get install -y software-properties-common curl wget gnupg ca-certificates \
    apt-transport-https unzip git lsb-release libldap2-dev

# Microsoft SQL Server Repo
RUN set -eux; \
    mkdir -p /etc/apt/keyrings; \
    wget -O - https://packages.microsoft.com/keys/microsoft.asc > /etc/apt/keyrings/microsoft.asc && \
    chmod go+r /etc/apt/keyrings/microsoft.asc && \
    echo "deb [signed-by=/etc/apt/keyrings/microsoft.asc] https://packages.microsoft.com/debian/12/prod bookworm main" > /etc/apt/sources.list.d/mssql-release.list

# PHP Repo
RUN add-apt-repository -y ppa:ondrej/php && apt-get update

# ODBC + MSSQL
RUN ACCEPT_EULA=Y apt-get install -y unixodbc unixodbc-dev msodbcsql18 mssql-tools18

# PHP 8.4
RUN apt-get install -y \
    php8.4 php8.4-cli php8.4-common php8.4-fpm php8.4-mysql php8.4-zip \
    php8.4-gd php8.4-mbstring php8.4-curl php8.4-xml php8.4-bcmath \
    php8.4-pdo php8.4-bz2 php8.4-dev php8.4-igbinary php8.4-intl \
    php8.4-opcache php8.4-readline php8.4-redis php8.4-pgsql \
    php8.4-ssh2 php8.4-soap php8.4-ldap \
    supervisor nano nginx

RUN phpenmod ldap

# SQLSRV
RUN pecl channel-update pecl.php.net && \
    pecl install sqlsrv pdo_sqlsrv && \
    echo "extension=sqlsrv.so" > /etc/php/8.4/mods-available/sqlsrv.ini && \
    echo "extension=pdo_sqlsrv.so" > /etc/php/8.4/mods-available/pdo_sqlsrv.ini && \
    phpenmod sqlsrv pdo_sqlsrv

# Composer
RUN curl -sS https://getcomposer.org/installer | php && \
    mv composer.phar /usr/local/bin/composer

# -------------------------------------------------
# FIX www-data UID/GID
# -------------------------------------------------
RUN groupmod -g ${WWWGROUP} www-data && \
    usermod -u ${WWWUSER} -g ${WWWGROUP} www-data

# -------------------------------------------------
# Working Directory
# -------------------------------------------------
WORKDIR /var/www

# Copy project
COPY . /var/www
COPY ./.docker/start.sh /start.sh
COPY ./.docker/nginx.conf /etc/nginx/nginx.conf
COPY ./.docker/supervisord.conf /etc/supervisord.conf

RUN chmod +x /start.sh

# Install dependencies
RUN composer install --no-interaction --prefer-dist --optimize-autoloader --ignore-platform-reqs

EXPOSE 80

CMD ["/bin/bash", "/start.sh"]
