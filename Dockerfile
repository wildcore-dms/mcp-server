FROM composer:2.10.3@sha256:b6dcd72ca98fa19d0e5b8476de179859704891fad06dd9e0b96833d4c430a5fc AS vendor

WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --no-scripts --no-autoloader --ignore-platform-req=ext-sockets
COPY bin/ ./bin/
COPY src/ ./src/
RUN composer dump-autoload --no-dev --classmap-authoritative --ignore-platform-req=ext-sockets


FROM ghcr.io/roadrunner-server/roadrunner:3.0.1@sha256:379a1821073abd3c02a31e2b404bd1b671ab2158d9db71ad93991b5e652b8c01 AS rr


# PHP 8.5.12+ fixes CVE-2026-109618 / CVE-2026-109619
FROM php:8.5.12-cli-alpine3.24@sha256:1ff5b45922ab30f418447c6089dbe2eba5f01e0b2742863d0327ac349305d70f

LABEL org.opencontainers.image.title="WildCore MCP Server" \
      org.opencontainers.image.description="MCP server for WildCore DMS management"

RUN apk add --no-cache --virtual .build-deps $PHPIZE_DEPS linux-headers \
    && docker-php-ext-install -j"$(nproc)" sockets \
    && apk del .build-deps \
    && addgroup -g 10001 app \
    && adduser -D -u 10001 -G app app

COPY --from=rr /usr/bin/rr /usr/local/bin/rr
COPY docker/php.ini "$PHP_INI_DIR/conf.d/zz-wildcore-mcp.ini"
COPY --from=vendor /app /app
COPY .rr.yaml /app/.rr.yaml

WORKDIR /app
USER 10001:10001

ENV MCP_HTTP_HOST=0.0.0.0 \
    MCP_HTTP_PORT=8000

EXPOSE 8000

HEALTHCHECK --interval=15s --timeout=5s --start-period=5s --retries=3 \
  CMD wget -q -O /dev/null "http://127.0.0.1:${MCP_HTTP_PORT}/health" || exit 1

# HTTP by default; for stdio: docker run -i --rm <image> php bin/wildcore-mcp
CMD ["rr", "serve", "-c", "/app/.rr.yaml"]
