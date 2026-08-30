#!/usr/bin/env bash
#
# Bring up the local Docker stack and leave a working site at
# http://localhost/serve — WordPress installed, the plugin activated, its two
# public pages created, and the assessment on the front page.
#
# Safe to run repeatedly: every step checks whether it has already happened, so
# a second run reinstalls nothing and loses nothing.
#
#   tools/dev-up.sh             the site, empty
#   tools/dev-up.sh --seed      the same, plus five invented profiles
#   tools/dev-up.sh --reset     destroy the database and start over
#
set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.."

SEED=0
RESET=0
for arg in "$@"; do
	case "$arg" in
		--seed)  SEED=1 ;;
		--reset) RESET=1 ;;
		-h|--help) sed -n '2,12p' "${BASH_SOURCE[0]}" | sed 's/^# \?//'; exit 0 ;;
		*) echo "Unknown option: $arg" >&2; exit 2 ;;
	esac
done

fail() { echo; echo "STOPPED: $*" >&2; exit 1; }

# Does anything at all answer at this URL? Any HTTP response counts, a 404
# included — the question is whether a server is there, not whether it likes
# the request. Skipped rather than failed when curl is absent.
site_answers() {
	command -v curl >/dev/null 2>&1 || return 0
	for _ in 1 2 3 4 5; do
		if curl -s -o /dev/null --max-time 5 "$1"; then return 0; fi
		sleep 2
	done
	return 1
}

command -v docker >/dev/null 2>&1 || fail 'Docker is not installed, or not on PATH.'
docker compose version >/dev/null 2>&1 || fail 'This needs Docker Compose v2 (it ships with Docker Desktop).'
docker info >/dev/null 2>&1 || fail 'Docker is installed but not running. Start Docker Desktop and try again.'

# .env, if present, is where a busy port 80 gets moved out of the way.
SITE_URL="${SERVE_URL:-}"
if [ -z "$SITE_URL" ] && [ -f .env ]; then
	SITE_URL="$(sed -n 's/^[[:space:]]*SERVE_URL[[:space:]]*=[[:space:]]*//p' .env | tail -n1)"
fi
SITE_URL="${SITE_URL:-http://localhost/serve}"

MAIL_PORT="${SERVE_MAIL_PORT:-}"
if [ -z "$MAIL_PORT" ] && [ -f .env ]; then
	MAIL_PORT="$(sed -n 's/^[[:space:]]*SERVE_MAIL_PORT[[:space:]]*=[[:space:]]*//p' .env | tail -n1)"
fi
MAIL_PORT="${MAIL_PORT:-8025}"

wp() { docker compose run --rm -T cli wp "$@"; }

if [ "$RESET" = 1 ]; then
	echo "== Removing the containers and their data =="
	# Only this stack's named volumes: `down -v` is scoped to the project.
	docker compose down -v
fi

echo "== Starting MariaDB, WordPress and Mailpit =="
if ! docker compose up -d db wordpress mail; then
	fail "Compose could not start. If the error mentions a port already in use,
   see docs/local-docker.md — it is one .env file to move off port 80."
fi

echo -n '   waiting for WordPress to unpack'
for _ in $(seq 1 60); do
	if docker compose exec -T wordpress test -f /var/www/html/serve/wp-config.php 2>/dev/null; then
		break
	fi
	echo -n '.'
	sleep 2
done
echo
docker compose exec -T wordpress test -f /var/www/html/serve/wp-config.php \
	|| fail 'WordPress never finished unpacking. `docker compose logs wordpress` says why.'

if wp core is-installed 2>/dev/null; then
	echo '== WordPress is already installed — leaving it alone =='
else
	echo '== Installing WordPress =='
	wp core install \
		--url="$SITE_URL" \
		--title='SERVE (local)' \
		--admin_user=admin \
		--admin_password=admin \
		--admin_email=serve-local@example.com \
		--skip-email >/dev/null
	# Pretty permalinks, so the REST routes the assessment posts to look like
	# the ones on the real site rather than ?rest_route= fallbacks.
	wp rewrite structure '/%postname%/' --hard >/dev/null
	echo '   admin / admin'
fi

# The image writes its own .htaccess on first boot with `RewriteBase /`, which
# 404s every pretty permalink under /serve. Put the right one in place before
# anything sends somebody to a page URL.
if ! docker compose exec -T wordpress grep -q 'RewriteBase /serve/' /var/www/html/serve/.htaccess 2>/dev/null; then
	echo '== Correcting the rewrite base for the subdirectory install =='
	docker compose cp docker/htaccess wordpress:/var/www/html/serve/.htaccess
	docker compose exec -T -u root wordpress chown www-data:www-data /var/www/html/serve/.htaccess
fi

echo '== Activating the plugin =='
wp plugin activate serve-dashboard >/dev/null
# Activation creates the pages but deliberately does not touch the front page,
# which is right for a real site and wrong for one you brought up to look at.
ASSESSMENT_PAGE="$(wp option get serve_dashboard_assessment_page 2>/dev/null || echo 0)"
if [ "${ASSESSMENT_PAGE:-0}" -gt 0 ]; then
	wp option update show_on_front page >/dev/null
	wp option update page_on_front "$ASSESSMENT_PAGE" >/dev/null
fi

if [ "$SEED" = 1 ]; then
	echo '== Seeding demo profiles (every name in it is invented) =='
	wp eval-file wp-content/plugins/serve-dashboard/dev/seed-demo.php
fi

# Prove the site answers before saying it does. A container created while the
# port was busy keeps the binding in its configuration and then starts without
# it — the database is on the compose network, so WP-CLI installs, activates
# and seeds perfectly happily, and the only symptom is that localhost answers
# nothing. The banner below went up over a site that was not there.
if ! site_answers "$SITE_URL/"; then
	echo '== Nothing is answering yet - recreating the web container =='
	docker compose up -d --force-recreate wordpress
	if ! site_answers "$SITE_URL/"; then
		fail "The stack is up, but nothing answers at $SITE_URL/.

   Two things to look at: 'docker compose logs wordpress' for a PHP or Apache
   error, and whether something else on this machine holds the port.
   docs/local-docker.md has the .env file that moves this stack off port 80."
	fi
fi

cat <<EOF

== Ready ==
   Assessment    $SITE_URL/
   Dashboard     $SITE_URL/wp-admin/admin.php?page=serve-dashboard
   Log in        $SITE_URL/wp-admin/  (admin / admin)
   Email         http://localhost:$MAIL_PORT  (nothing leaves your machine)

   Edits to wordpress-plugin/serve-dashboard/ are live on the next request.
   Stop with:  docker compose stop
   Start over: tools/dev-up.sh --reset
EOF
