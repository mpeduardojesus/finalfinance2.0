# PHP 8.3 + Apache + oci8 (Oracle Instant Client descarregado em build time)
FROM php:8.3-apache-bookworm

# Versão do Oracle Instant Client (compatível com Oracle Database 19c) e da extensão oci8
ARG IC_VERSION_DIR=1928000
ARG IC_VERSION=19.28.0.0.0dbru
ARG OCI8_VERSION=3.3.0

ENV ORACLE_HOME=/opt/oracle/instantclient \
    LD_LIBRARY_PATH=/opt/oracle/instantclient \
    APACHE_DOCUMENT_ROOT=/var/www/html/public

RUN apt-get update \
 && apt-get install -y --no-install-recommends libaio1 unzip curl ca-certificates \
    libzip-dev libpng-dev libjpeg-dev libfreetype6-dev \
 && rm -rf /var/lib/apt/lists/*

# Extensões exigidas pelo PhpSpreadsheet (importação do .xls do Sage no Plano de tesouraria)
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-install -j"$(nproc)" gd zip

# Composer (só em build) + definições de PHP (upload do ficheiro Sage, erros sem argumentos/conteúdo)
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-intranet.ini

# Oracle Instant Client Basic + SDK (URLs oficiais da Oracle)
RUN set -eux; \
    base="https://download.oracle.com/otn_software/linux/instantclient/${IC_VERSION_DIR}"; \
    mkdir -p /opt/oracle && cd /opt/oracle; \
    curl -fsSL -o basic.zip "${base}/instantclient-basic-linux.x64-${IC_VERSION}.zip"; \
    curl -fsSL -o sdk.zip   "${base}/instantclient-sdk-linux.x64-${IC_VERSION}.zip"; \
    unzip -q basic.zip && unzip -q sdk.zip && rm -f basic.zip sdk.zip; \
    ln -s "$(find /opt/oracle -maxdepth 1 -type d -name 'instantclient_*' | head -n1)" /opt/oracle/instantclient; \
    echo /opt/oracle/instantclient > /etc/ld.so.conf.d/oracle-instantclient.conf && ldconfig

# Extensão oci8 (PECL) para PHP 8.3
RUN set -eux; \
    echo "instantclient,/opt/oracle/instantclient" | pecl install "oci8-${OCI8_VERSION}"; \
    docker-php-ext-enable oci8; \
    php -m | grep -i oci8

# Apache: mod_rewrite + DocumentRoot em /public
RUN a2enmod rewrite headers \
 && sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
 && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf \
 && sed -i 's/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf

WORKDIR /var/www/html

# Dependências PHP (camada em cache). Não contém dados: só bibliotecas.
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --no-scripts --no-autoloader --prefer-dist

# Código da aplicação. O .dockerignore EXCLUI data/private e qualquer .xls/.xlsx/.xlsm: os dados
# confidenciais nunca entram na imagem; são montados por volume em /var/www/html/data/private.
COPY . /var/www/html
RUN composer dump-autoload --no-dev --optimize --no-interaction \
 && mkdir -p /var/www/html/data/private \
 && chown -R www-data:www-data /var/www/html/data \
 && chmod 770 /var/www/html/data/private
ENV TREASURY_DATA_DIR=/var/www/html/data/private

EXPOSE 80
HEALTHCHECK --interval=30s --timeout=5s CMD curl -fs http://localhost/api/health || exit 1
