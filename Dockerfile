FROM ubuntu:24.04
LABEL Maintainer="MAHAMOUD BRAHIM ADOUM"
LABEL Description="PHP 8.4 Laravel setup with MySQL, MSSQL, Node.js 20 and npm"

ENV DEBIAN_FRONTEND=noninteractive

# --- Sistem bağımlılıkları ---
RUN apt-get update && \
    apt-get install -y software-properties-common curl wget gnupg ca-certificates \
    apt-transport-https unzip git lsb-release nano supervisor nginx

# --- Microsoft SQL Server repo ---
RUN set -eux; \
    mkdir -p /etc/apt/keyrings; \
    wget -O - https://packages.microsoft.com/keys/microsoft.asc > /etc/apt/keyrings/microsoft.asc && \
    chmod go+r /etc/apt/keyrings/microsoft.asc && \
    echo "deb [signed-by=/etc/apt/keyrings/microsoft.asc] https://packages.microsoft.com/debian/12/prod bookworm main" > /etc/apt/sources.list.d/mssql-release.list

# --- PHP repo ---
RUN add-apt-repository -y ppa:ondrej/php && apt-get update

# --- ODBC ve SQL Server tools ---
RUN ACCEPT_EULA=Y apt-get install -y \
    unixodbc \
    unixodbc-dev \
    msodbcsql18 \
    mssql-tools18

# --- PHP 8.4 ve extensionlar ---
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
    php8.4-ldap \
    php8.4-redis \
    php8.4-pgsql \
    php8.4-ssh2 \
    php8.4-soap

# --- SQL Server PHP extension ---
RUN pecl channel-update pecl.php.net && \
    pecl install sqlsrv pdo_sqlsrv && \
    echo "extension=sqlsrv.so" > /etc/php/8.4/mods-available/sqlsrv.ini && \
    echo "extension=pdo_sqlsrv.so" > /etc/php/8.4/mods-available/pdo_sqlsrv.ini && \
    phpenmod sqlsrv pdo_sqlsrv

# --- Composer ---
RUN curl -sS https://getcomposer.org/installer | php && \
    mv composer.phar /usr/local/bin/composer

# --- NodeJS 20 ---
RUN curl -fsSL https://deb.nodesource.com/setup_20.x | bash - && \
    apt-get install -y nodejs

# --- Laravel çalışma dizini ---
WORKDIR /var/www

# --- Laravel dosyaları ---
COPY . /var/www

# izinler
RUN chown -R www-data:www-data /var/www

# --- Laravel dependency ---
RUN composer install --no-interaction --prefer-dist --optimize-autoloader

RUN npm install
RUN npm run build

# --- nginx ve start script ---
COPY ./.docker/start.sh /start.sh
COPY ./.docker/nginx.conf /etc/nginx/nginx.conf

EXPOSE 80

CMD ["sh", "/start.sh"]