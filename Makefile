SHELL := /bin/bash

COMPOSE := docker compose

.PHONY: up down restart logs ps exec occ build watch fix-perms

up:
	$(COMPOSE) up -d

down:
	$(COMPOSE) down

restart:
	$(COMPOSE) down && $(COMPOSE) up -d

logs:
	$(COMPOSE) logs -f --tail=200

ps:
	$(COMPOSE) ps

exec:
	$(COMPOSE) exec nextcloud bash || true

# Run an OCC command, e.g. make occ cmd="app:list"
occ:
	$(COMPOSE) exec -u www-data nextcloud php occ $(cmd)

build:
	npm install && npm run build

watch:
	npm install && npm run watch

# Fix folder permissions inside container
fix-perms:
	$(COMPOSE) exec -u root nextcloud bash -lc "chown -R www-data:www-data /var/www/html/apps /var/www/html/custom_apps /var/www/html/config /var/www/html/data && ls -ld /var/www/html/apps /var/www/html/custom_apps /var/www/html/config /var/www/html/data"
