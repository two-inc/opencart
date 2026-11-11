# Local dev shop: the official PHP Apache image, the PHP extensions the
# OpenCart installer checks for, and the upload/ tree of a pinned,
# checksum-verified upstream OpenCart release in /usr/src/opencart.
# start.sh installs it with cli_install.php on the first boot.
ARG PHP_IMAGE=php:8.4-apache
FROM ${PHP_IMAGE}

ARG OPENCART_VERSION=4.1.0.4
# sha256 of the upstream release zip. Upstream publishes no checksums, so a
# new pin is hashed from the release asset and reviewed in the PR.
ARG OPENCART_SHA256=cdef537f5a0a7e1b4ddbe16f589dd2e80b10150a09bfbe93ce76ef2d5c3218c0

SHELL ["/bin/bash", "-o", "pipefail", "-c"]

RUN apt-get update \
 && apt-get install -y --no-install-recommends \
      libfreetype6-dev libjpeg62-turbo-dev libpng-dev libzip-dev unzip \
 && docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-install -j"$(nproc)" gd zip mysqli \
 && a2enmod rewrite \
 && rm -rf /var/lib/apt/lists/*

RUN curl -fsSL -o /tmp/opencart.zip \
      "https://github.com/opencart/opencart/releases/download/${OPENCART_VERSION}/opencart-${OPENCART_VERSION}.zip" \
 && echo "${OPENCART_SHA256}  /tmp/opencart.zip" | sha256sum -c - \
 && unzip -q /tmp/opencart.zip 'upload/*' -d /tmp/opencart \
 && mv /tmp/opencart/upload /usr/src/opencart \
 && rm -rf /tmp/opencart /tmp/opencart.zip

COPY start.sh /usr/local/bin/two-opencart-start
CMD ["two-opencart-start"]
