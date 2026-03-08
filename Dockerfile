FROM php:8.2-apache

RUN apt-get update && apt-get install -y \
        tesseract-ocr \
        tesseract-ocr-msa \
        tesseract-ocr-eng \
        libcurl4-openssl-dev \
    && docker-php-ext-install curl \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

COPY . /var/www/html/
RUN chmod 1777 /tmp

EXPOSE 80
