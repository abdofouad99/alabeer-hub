# استخدم PHP 8.1 مع Apache
FROM php:8.1-apache

# تثبيت extensions مطلوبة
RUN apt-get update && apt-get install -y \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libzip-dev \
    unzip \
    git \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install gd pdo pdo_mysql zip curl

# تفعيل mod_rewrite لـ Apache
RUN a2enmod rewrite

# إعداد مجلد العمل
WORKDIR /var/www/html

# نسخ ملفات المشروع
COPY . .

# إعداد الأذونات
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html \
    && mkdir -p logs cache \
    && chmod 755 logs cache

# تعريض المنفذ 80
EXPOSE 80

# الأمر الافتراضي
CMD ["apache2-foreground"]