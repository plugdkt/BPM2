FROM php:8.2-apache

# ext-pdo_mysql + ext-curl จำเป็นตามสเปก (ดู spec.md ข้อ 4)
# ext-gd + ext-zip เพิ่มเข้ามาเพราะ PhpSpreadsheet (Export Excel ข้อ 4/7) ต้องใช้ทั้งคู่ — ไม่ได้ระบุไว้ในสเปกเดิม เจอตอน composer install จริง
RUN apt-get update && apt-get install -y --no-install-recommends \
        libcurl4-openssl-dev \
        libpng-dev libjpeg62-turbo-dev libfreetype6-dev \
        libzip-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo_mysql curl gd zip opcache \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# เปิด OPcache — ไม่งั้นทุก request ต้อง compile ไฟล์ PHP ใหม่หมด (bootstrap.php + lib ทั้งหมด + class
# ของ PhpSpreadsheet/Dompdf) ช้าขึ้นมากโดยเฉพาะบน Windows ที่ bind mount ทำให้ filesystem access ช้าอยู่แล้ว
# validate_timestamps=1 (ค่า default) ไว้ให้แก้โค้ดแล้วเห็นผลทันทีโดยไม่ต้อง rebuild — เหมาะกับ dev เท่านั้น
RUN { \
        echo 'opcache.enable=1'; \
        echo 'opcache.memory_consumption=128'; \
        echo 'opcache.max_accelerated_files=10000'; \
        echo 'opcache.validate_timestamps=1'; \
        echo 'opcache.revalidate_freq=0'; \
    } > /usr/local/etc/php/conf.d/opcache-dev.ini

# จำลอง physical path แบบเดียวกับที่ IIS ใช้จริง (ชี้ที่ public/ เท่านั้น ไม่ใช่ root ของ repo — ดู spec.md ข้อ 10)
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf
RUN a2enmod rewrite
RUN echo "ServerName localhost" >> /etc/apache2/apache2.conf

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY docker/entrypoint.sh /usr/local/bin/bpm-entrypoint.sh
RUN chmod +x /usr/local/bin/bpm-entrypoint.sh

WORKDIR /var/www/html

ENTRYPOINT ["bpm-entrypoint.sh"]
CMD ["apache2-foreground"]
