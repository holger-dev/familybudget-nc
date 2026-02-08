SHELL := /bin/bash

COMPOSE := docker compose

APP_NAME := $(shell sed -n 's:.*<id>\\(.*\\)</id>.*:\\1:p' appinfo/info.xml | head -n1)
BUILD_DIR := $(CURDIR)/build
APPSTORE_SIGN_DIR := $(BUILD_DIR)/sign
APPSTORE_ARCHIVE_DIR := $(BUILD_DIR)/artifacts/appstore
CERT_DIR := $(HOME)/.nextcloud/certificates

.PHONY: up down restart logs ps exec occ build watch fix-perms appstore

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

appstore:
	rm -rf $(APPSTORE_SIGN_DIR) $(APPSTORE_ARCHIVE_DIR)
	mkdir -p $(APPSTORE_SIGN_DIR) $(APPSTORE_ARCHIVE_DIR)
	rsync -a --delete \
		--exclude ".git" \
		--exclude ".github" \
		--exclude "node_modules" \
		--exclude "tests" \
		--exclude "docs" \
		--exclude "scripts" \
		--exclude "build" \
		--exclude "*.map" \
		--exclude "docker*" \
		--exclude "Dockerfile*" \
		--exclude "Makefile" \
		--exclude "package*.json" \
		--exclude "composer.*" \
		--exclude "babel.config.js" \
		--exclude "webpack.config.js" \
		--exclude ".idea" \
		--exclude ".vscode" \
		./ $(APPSTORE_SIGN_DIR)/$(APP_NAME)/
	mkdir -p $(CERT_DIR)
	php ./bin/tools/file_from_env.php "app_private_key" "$(CERT_DIR)/$(APP_NAME).key"
	php ./bin/tools/file_from_env.php "app_public_crt" "$(CERT_DIR)/$(APP_NAME).crt"
	php ../../occ integrity:sign-app \
		--privateKey="$(CERT_DIR)/$(APP_NAME).key" \
		--certificate="$(CERT_DIR)/$(APP_NAME).crt" \
		--path="$(APPSTORE_SIGN_DIR)/$(APP_NAME)"
	tar -czf $(APPSTORE_ARCHIVE_DIR)/$(APP_NAME).tar.gz -C $(APPSTORE_SIGN_DIR) $(APP_NAME)
