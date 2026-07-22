# SelfRecover demo — image officielle
# Build : docker build -t selfrecover:dev .
# Run   : docker run -p 8080:8080 selfrecover:dev
# Usage : http://localhost:8080
#
# Stack : PHP 8.2 CLI + SQLite (pdo_sqlite, sqlite3). Zéro dépendance externe.
# Licence : AGPL-3.0-or-later

FROM php:8.2-cli-alpine

LABEL org.opencontainers.image.title="SelfRecover"
LABEL org.opencontainers.image.description="Zero-email account recovery protocol — split knowledge + HMAC per domain (AGPL-3.0-or-later)"
LABEL org.opencontainers.image.source="https://github.com/Pierroons/my-self"
LABEL org.opencontainers.image.licenses="AGPL-3.0-or-later"
LABEL org.opencontainers.image.documentation="https://github.com/Pierroons/my-self/tree/main/bi-self/selfrecover"
LABEL org.opencontainers.image.url="https://your-instance.example/selfrecover/"

# pdo_sqlite est inclus dans php:8.2-cli, sqlite3 CLI utile pour init schéma
RUN apk add --no-cache sqlite

WORKDIR /app
COPY . /app

# DB init (sera recréée à chaque démarrage si SELFRECOVER_FRESH_DB=1)
RUN sqlite3 /app/selfrecover.sqlite < /app/schema.sql && chmod 666 /app/selfrecover.sqlite

# Créer un user non-root et lui attribuer /app
RUN addgroup -S selfrecover && adduser -S -G selfrecover -h /app selfrecover \
    && chown -R selfrecover:selfrecover /app

EXPOSE 8080

USER selfrecover

# Le router.php mappe /api/* vers api/index.php
CMD ["sh", "-c", "if [ \"$SELFRECOVER_FRESH_DB\" = \"1\" ]; then rm -f /app/selfrecover.sqlite && sqlite3 /app/selfrecover.sqlite < /app/schema.sql; fi; php -S 0.0.0.0:8080 -t /app /app/router.php"]
