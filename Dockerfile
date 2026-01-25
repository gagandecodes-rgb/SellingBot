# Dockerfile (PHP + Apache) for your Telegram webhook bot (index.php)
# Works great for hosting platforms that accept Docker (including many “bot hosts”).

FROM php:8.2-apache

# Install PostgreSQL PDO driver (required for Supabase Postgres)
RUN apt-get update && apt-get install -y --no-install-recommends \
    libpq-dev \
  && docker-php-ext-install pdo pdo_pgsql \
  && rm -rf /var/lib/apt/lists/*

# Apache: allow .htaccess if you ever need it (optional but fine)
RUN a2enmod rewrite

# Copy your bot file into the Apache web root
COPY index.php /var/www/html/index.php

# (Optional) Security: don’t leak Apache version info
RUN sed -i 's/ServerTokens OS/ServerTokens Prod/g' /etc/apache2/conf-available/security.conf || true \
 && sed -i 's/ServerSignature On/ServerSignature Off/g' /etc/apache2/conf-available/security.conf || true

# Expose HTTP port
EXPOSE 80

# Healthcheck (optional)
HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
  CMD curl -fsS http://localhost/index.php >/dev/null || exit 1
