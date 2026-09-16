# App container for the Registry2 Docker dev environment (issue #2116 spike).
#
# LOCAL DEVELOPMENT ONLY. This image pairs with docker-compose.yml, which bind
# mounts the checkout over /var/www/html. The application code is deliberately
# NOT copied in: the whole point of the stack is that host edits are live, and
# a baked-in copy would only be shadowed by the mount while making the image
# huge and the build dependent on a clean tree.
#
# PHP 8.4 matches confirmed production (8.4.25) and the composer.json platform
# pin (8.4.0). It is also a hard floor for the test suite: PHPUnit 12 requires
# PHP >= 8.3, so an 8.2 container could not run `composer test:full` at all.
FROM php:8.4-apache

ARG DEBIAN_FRONTEND=noninteractive

# Composer itself — the base image doesn't ship it. Copied from the official
# composer image rather than curl|bash'd, so the binary is pinned to a known
# build and the layer doesn't need curl/gnupg pulled in just for this.
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Host user identity, matched by the Apache worker below. Overridable at build
# time (`--build-arg APP_UID=...`) so this image is not permanently welded to
# one developer's Mac, but the defaults are the current host: jimboone (501),
# staff (20).
ARG APP_UID=501
ARG APP_GID=20

# Build-time headers for the PHP extensions compiled below: libzip-dev for
# ext-zip, the jpeg/png/freetype trio for ext-gd (see docker-php-ext-configure
# below). curl and mbstring are already compiled into php:8.4-apache (see the
# "NOT built here" note below), so their dev headers (libcurl4-openssl-dev,
# libonig-dev) are not needed — confirmed via `php -m` rather than assumed;
# left out rather than kept "just in case."
#
# --no-install-recommends keeps the layer from pulling in a documentation and
# X11 tail that a headless PHP container has no use for.
#
# default-mysql-client is not a build header — it's the actual `mysql` CLI
# binary, needed at runtime by scripts/provision-schema.sh and
# scripts/refresh-local-db.sh (both shell out to `mysql`/`mysqldump` rather
# than using PHP's PDO), and by developers who want an interactive shell into
# the `db` service without leaving the container.
RUN apt-get update && apt-get install -y --no-install-recommends \
      libjpeg62-turbo-dev \
      libpng-dev \
      libfreetype6-dev \
      libzip-dev \
      default-mysql-client \
 && rm -rf /var/lib/apt/lists/*

# Extensions.
#
# Built here because the base image does NOT ship them: pdo_mysql, mysqli, gd,
# zip, exif. (UserSpice uses PDO via $db; mysqli is included because parts of
# the UserSpice/plugin surface still reach for it directly. exif was found
# missing only by running the app — CarImageProcessor::decodeAndProcessImages()
# calls exif_imagetype() directly, and neither composer.json/composer.lock nor
# a grep of the runtime code caught it during initial extension enumeration,
# since Composer has no ext-exif dependency declaration to enumerate from.)
#
# NOT built here, because php:8.4-apache already compiles them in and a second
# `docker-php-ext-install` would fail the build on a duplicate module:
#   curl, mbstring, openssl, fileinfo, json, libxml, dom, xml, xmlwriter,
#   ctype, tokenizer, phar, pcre, iconv
# That set covers the runtime requirements AND the PHPUnit 12 dev requirements
# identified in the plan. Verify after any base-image bump with:
#   docker compose exec app php -m
RUN docker-php-ext-configure gd --with-jpeg --with-freetype \
 && docker-php-ext-install -j"$(nproc)" \
      pdo_mysql \
      mysqli \
      gd \
      zip \
      exif

# The registry relies on .htaccess for rewrites (pretty URLs, error pages)
# and response headers (mod_headers) — both discovered by running the stack
# and hitting a 500 with "Invalid command 'Header'" / rewrites not applying.
RUN a2enmod rewrite headers

# Base Apache config ignores .htaccess entirely (AllowOverride None) unless
# a vhost/directory block says otherwise. Same failure mode as the missing
# mod_headers above: .htaccess is present but silently not honored without
# this.
RUN { \
      echo '<Directory /var/www/html>'; \
      echo '    AllowOverride All'; \
      echo '</Directory>'; \
    } > /etc/apache2/conf-available/registry2-allow-override.conf \
 && a2enconf registry2-allow-override

# Dev php.ini. The php:apache image intentionally installs no php.ini, but it
# does read every *.ini in conf.d — this is the supported drop-in point.
COPY php.ini-overrides.ini /usr/local/etc/php/conf.d/zz-elanregistry-dev.ini

# --- Host UID/GID matching --------------------------------------------------
#
# /var/www/html is a bind mount of the host checkout, whose files are owned by
# jimboone:staff (501:20). Apache's worker processes must write into it —
# userimages/, usersc/cache, users/logs, .phpunit.cache, .phpstan-cache — and
# files Apache creates must stay editable from the host afterwards. With the
# stock www-data (33:33) both directions break, and the usual workarounds
# (chmod 777 the tree, or chown it to 33 and lose host ownership) are worse.
#
# Renumbering www-data rather than creating a new user is the more maintainable
# of the two options: apache2.conf's `User ${APACHE_RUN_USER}` /
# `Group ${APACHE_RUN_GROUP}` directives, the envvars file, and every
# image-provided path already owned by www-data all keep working untouched. A
# freshly created user would require editing apache2.conf AND re-chowning those
# paths, i.e. more image-specific surface to keep in sync across base bumps.
#
# GID 20 on Debian is `dialout`, which DOES collide — so the existing group is
# renumbered out of the way first (nothing in this image uses dialout) before
# www-data's group takes 20. UID 501 is unused on Debian bookworm, but
# `getent` guards both sides so a future base image that does use either ID
# gets it silently renumbered out of the way too (same as the dialout case),
# rather than the build failing outright or two accounts silently sharing an
# ID.
#
# This is local-dev-only: the `9`-prefix scheme for displaced accounts
# (groupmod -g 9"${APP_GID}") is string concatenation, not arithmetic, so it
# only reliably avoids collisions for small IDs like this Mac's 501/20 — a
# Linux host with APP_GID=1000 would produce 91000, still valid but not by
# design. Fine for a spike; worth a real allocation strategy if this pattern
# sees wider use.
RUN set -eux; \
    if getent group "${APP_GID}" >/dev/null; then \
      existing_group="$(getent group "${APP_GID}" | cut -d: -f1)"; \
      if [ "$existing_group" != "www-data" ]; then \
        groupmod -g 9"${APP_GID}" "$existing_group"; \
      fi; \
    fi; \
    if getent passwd "${APP_UID}" >/dev/null; then \
      existing_user="$(getent passwd "${APP_UID}" | cut -d: -f1)"; \
      if [ "$existing_user" != "www-data" ]; then \
        usermod -u 9"${APP_UID}" "$existing_user"; \
      fi; \
    fi; \
    groupmod -g "${APP_GID}" www-data; \
    usermod -u "${APP_UID}" -g "${APP_GID}" www-data; \
    chown -R www-data:www-data /var/www

# The container still STARTS as root — apache2-foreground needs that to bind
# port 80 and read its config — then drops each worker to www-data, which is
# now 501:20. That is the process that actually serves requests and touches the
# bind mount, so this is what matters. Do not add a top-level `USER www-data`:
# it breaks Apache's startup without improving the permission story.

WORKDIR /var/www/html

EXPOSE 80
