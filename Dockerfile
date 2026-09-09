# Anamanta solar calendar - kythings.walkowiaks.com
#
# Self-contained image: nginx + php-fpm with the application code baked in, so
# `docker compose pull && docker compose up -d kythings` is the whole deployment.
# No bind mount, nothing to place or permission on the host.
#
# Serves on 8080 as an unprivileged user. The Cloudflare Tunnel points straight
# at it, exactly as it does for jellyfin, seerr, audiobookshelf and ttyd -- the
# existing nginx-proxy container is not involved and its config is not touched.
#
# Base pinned by digest, not tag. This image's `latest` currently carries PHP
# 8.5.10, and a silent bump of the PHP version underneath a calendar feed is
# exactly the kind of drift that breaks date handling without anyone noticing.
# CI re-runs the full suite against whatever this digest resolves to.
#
# To bump deliberately:
#   docker pull trafex/php-nginx:latest
#   docker inspect --format='{{index .RepoDigests 0}}' trafex/php-nginx:latest
# ...then update the digest here and let CI prove the suite still passes.
FROM trafex/php-nginx@sha256:8a82bac3c9c4853e4b0bd33edfbbb0f30d4b3546f177a35944047c3856ac72e7

USER root

# ---------------------------------------------------------------------------
# PHP configuration.
#
# display_errors MUST be off. sun.php calls date_sunrise()/date_sunset(), which
# are deprecated as of PHP 8.1; with display_errors on, PHP writes "Deprecated:"
# notices into the response body and corrupts the ICS feed. Errors still go to
# the log -- they are suppressed in output, not suppressed entirely.
#
# The timezone must be UTC. dateToCal() formats with date('Ymd\THis\Z'), which
# renders in the server's default timezone but labels the result Z, so a non-UTC
# container would silently emit wrong timestamps that still look well-formed.
#
# Both are asserted in CI (.github/workflows/ci.yml) rather than trusted.
# ---------------------------------------------------------------------------
# On PHP 8.5 this file is doing real work, not box-ticking: the base emits two
# deprecations per call site (the functions since 8.1, the SUNFUNCS_RET_STRING
# constant since 8.4), which is roughly 4,000 notices for a full-year feed.
RUN printf '%s\n' \
      'display_errors = Off' \
      'display_startup_errors = Off' \
      'log_errors = On' \
      'error_log = /dev/stderr' \
      'error_reporting = E_ALL & ~E_DEPRECATED' \
      'date.timezone = UTC' \
      'expose_php = Off' \
    > /etc/php85/conf.d/zz-kythings.ini

# The curl extension is already present in the base image and is used for the
# optional geocoding lookup on the builder page. The feed itself makes no
# external calls at all. ca-certificates is needed for the HTTPS geocode.
RUN apk add --no-cache ca-certificates && rm -rf /var/cache/apk/*

COPY --chown=nobody:nobody sun.php index.php /var/www/html/

USER nobody

EXPOSE 8080

HEALTHCHECK --interval=60s --timeout=5s --start-period=10s --retries=3 \
  CMD wget -q -O /dev/null "http://127.0.0.1:8080/sun.php?lat=0&lng=0&gmt=0&year=2026&noon" || exit 1
