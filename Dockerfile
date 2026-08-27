FROM php:8.2-apache

RUN docker-php-ext-install mysqli pdo pdo_mysql && docker-php-ext-enable mysqli pdo pdo_mysql

# Limites de upload de anexos (máx 60MB por arquivo)
RUN { \
        echo 'upload_max_filesize = 60M'; \
        echo 'post_max_size = 120M'; \
        echo 'memory_limit = 256M'; \
        echo 'max_file_uploads = 20'; \
        echo 'max_execution_time = 300'; \
        echo 'max_input_time = 300'; \
    } > /usr/local/etc/php/conf.d/uploads.ini
