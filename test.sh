#!/bin/env bash

set -eu

USER_ID=$(id -u)
GROUP_ID=$(id -g)
export USER_ID
export GROUP_ID

trap "docker compose down" EXIT

docker compose run --rm composer install --ignore-platform-reqs
docker compose run --rm composer dump-autoload

docker compose up -d --wait wiremock freshrss

docker compose exec -w /var/www/FreshRSS freshrss ./cli/prepare.php

docker compose exec -w /var/www/FreshRSS freshrss \
	./cli/do-install.php \
	--default-user admin \
	--auth-type none \
	--environment development \
	--db-type sqlite

docker compose exec -w /var/www/FreshRSS freshrss ./cli/create-user.php --user admin

docker compose exec -w /var/www/FreshRSS/extensions/FreshRSS-GitHubDiff freshrss \
	./vendor/bin/phpunit tests
