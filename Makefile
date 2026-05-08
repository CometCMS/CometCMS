SHELL := /bin/bash

.PHONY: build ci clean dev dist lint-frontend lint-php test test-backend test-frontend web-build

PHP_HOST ?= 127.0.0.1
PHP_PORT ?= 8000
VITE_HOST ?= 127.0.0.1
VITE_PORT ?= 5173
DIST ?= dist

## Start the PHP CMS and Vite UI together.
dev:
	@printf "CometCMS dev\n  PHP:  http://$(PHP_HOST):$(PHP_PORT)/admin\n  Vite: http://$(VITE_HOST):$(VITE_PORT)\n\n"
	@php -S $(PHP_HOST):$(PHP_PORT) -d upload_max_filesize=128M -d post_max_size=128M -t cms cms/router.php & \
	php_pid=$$!; \
	npm --workspace web run dev -- --host $(VITE_HOST) --port $(VITE_PORT) & \
	vite_pid=$$!; \
	trap 'kill $$php_pid $$vite_pid 2>/dev/null' INT TERM EXIT; \
	wait -n $$php_pid $$vite_pid; \
	status=$$?; \
	kill $$php_pid $$vite_pid 2>/dev/null; \
	exit $$status

## Build a deployment-ready folder in dist/.
build:
	rm -rf $(DIST)
	mkdir -p $(DIST)
	cp cms/index.php cms/router.php cms/.htaccess $(DIST)/
	cp -R cms/app cms/config $(DIST)/
	COMET_ADMIN_OUT_DIR="$(CURDIR)/$(DIST)/admin" npm --workspace web run build
	mkdir -p $(DIST)/storage
	cp cms/storage/.htaccess $(DIST)/storage/
	for dir in sessions users roles api-tokens logs backups updates cache cache/login-throttle \
	           workspaces; do \
	    mkdir -p "$(DIST)/storage/$$dir"; \
	    touch "$(DIST)/storage/$$dir/.gitkeep"; \
	done
	@printf "Built $(DIST)/. Upload that folder's contents to your server.\n"

## Compile the Vue admin UI into dist/admin without assembling PHP files.
web-build:
	rm -rf $(DIST)/admin
	COMET_ADMIN_OUT_DIR="$(CURDIR)/$(DIST)/admin" npm --workspace web run build

## Lint PHP source and backend tests.
lint-php:
	find cms tests/php -name '*.php' -print0 | xargs -0 -n1 php -l

## Lint Vue admin source and tests.
lint-frontend:
	npm --workspace web run lint

## Run backend PHP tests.
test-backend:
	php tests/php/run.php

## Run frontend Vue/Vite unit tests.
test-frontend:
	npm --workspace web run test

## Run all tests that should pass before shipping.
test: lint-php lint-frontend test-backend test-frontend

## Full CI verification: tests plus production build.
ci: test build

## Backwards-compatible alias for the deployment build.
dist: build

## Remove generated build output.
clean:
	rm -rf $(DIST) cms/.vite-hot cms/admin
