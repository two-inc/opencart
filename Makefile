# ==============================================================================
# Two for OpenCart 4: development environment and checks
# ==============================================================================

# Local settings live in .env only (copy .env.example). Values there override
# the defaults below, and docker compose reads the same file.
-include .env

COMPOSE      := docker compose
CONTAINER    := two-opencart
PORT         ?= 1238
URL          := http://localhost:$(PORT)/
ADMIN_USER   ?= admin
ADMIN_EMAIL  ?= exampleuser@two.inc
ADMIN_PASSWD ?= examplepassword123
export PORT ADMIN_USER ADMIN_EMAIL ADMIN_PASSWD

# Internal Two developers (an @two.inc gcloud account) point at staging,
# everyone else at sandbox. TWO_INTERNAL_ENVIRONMENTS offers the staging
# environment in the admin, so it follows the same split.
TWO_ENV                   := $(shell gcloud config get-value account 2>/dev/null | grep -q '@two\.inc$$' && echo staging || echo sandbox)
TWO_ENVIRONMENT           ?= $(TWO_ENV)
TWO_INTERNAL_ENVIRONMENTS ?= $(if $(filter staging,$(TWO_ENV)),1,)
TWO_API_KEY               ?=
export TWO_ENVIRONMENT TWO_INTERNAL_ENVIRONMENTS

# Static analysis pins. Re-derive a checksum from the upstream release asset
# when bumping a version, never copy one from elsewhere.
PHPSTAN_VERSION       := 2.2.5
PHPSTAN_SHA256        := 1b2f03384ebcfd67053b06b69cbc0b9f62bf239349f69eaf723649409789e2e6
# The supported floor: PHPStan checks the adapter against these sources.
OPENCART_FLOOR        := 4.1.0.0
OPENCART_FLOOR_SHA256 := 5d9883de4e37852dbb71c83b8833b9aa4419bc3e71d808e62e38522b2fd1b294
CACHE                 := .cache

# E2E shop (tests/e2e/compose.yml): its own compose project and ports, so it
# runs beside the `make install` shop. One leg per OpenCart version in CI.
E2E_PROJECT         ?= two-opencart-e2e
E2E_PORT            ?= 1240
E2E_OPENCART        ?= 4.1.0.4
E2E_OPENCART_SHA256 ?= cdef537f5a0a7e1b4ddbe16f589dd2e80b10150a09bfbe93ce76ef2d5c3218c0
E2E_PHP             ?= 8.4
TWO_API_STUB_PORT   ?= 8099
# Empty runs against the stub. A sandbox API key runs against Two's sandbox.
E2E_SANDBOX_API_KEY ?=
E2E_COMPOSE         := docker compose -f tests/e2e/compose.yml $(if $(E2E_SANDBOX_API_KEY),,--profile stub)
export E2E_PROJECT E2E_PORT E2E_OPENCART E2E_OPENCART_SHA256 E2E_PHP TWO_API_STUB_PORT E2E_SANDBOX_API_KEY

.PHONY: help install configure smoke run stop clean logs test test-js lint phpstan archive patch minor major e2e e2e-up e2e-test e2e-down

.DEFAULT_GOAL := help

## Show this help
help:
	@awk '/^## /{desc=substr($$0,4)} /^[a-zA-Z_-]+:/{if(desc){printf "  \033[36m%-12s\033[0m %s\n",$$1,desc; desc=""}}' $(MAKEFILE_LIST)

## Build and start a fresh OpenCart shop with the extension installed and enabled
install: clean
	$(COMPOSE) up -d --build
	@echo "Waiting for the OpenCart install (cli_install.php)..."
	@for i in $$(seq 1 90); do \
		docker exec $(CONTAINER) grep -q "define('DB_DATABASE'" /var/www/html/config.php 2>/dev/null && exit 0; \
		sleep 2; \
	done; echo "OpenCart did not install, see: make logs"; exit 1
	$(MAKE) configure
	@echo ""
	@echo "========================================="
	@echo " OpenCart store: $(URL)"
	@echo " Admin panel:    $(URL)admin/"
	@echo " Credentials:    $(ADMIN_USER) / $(ADMIN_PASSWD)"
	@echo " Two settings:   Extensions > Extensions > Payments > Two"
	@echo "========================================="

## Install and enable the extension in the running shop (idempotent): make configure TWO_API_KEY=xxx
configure:
	docker exec -u www-data \
		-e OPENCART_DIR=/var/www/html \
		-e TWO_ENVIRONMENT=$(TWO_ENVIRONMENT) \
		-e TWO_API_KEY=$(TWO_API_KEY) \
		$(CONTAINER) php /var/www/html/extension/two/dev/configure.php

## Smoke-test the running shop: admin pages, save, install, configure.php, error log
smoke:
	dev/ci/smoke.sh

## Start the existing shop containers
run:
	$(COMPOSE) start
	@echo " OpenCart store: $(URL)"

## Stop the shop containers
stop:
	$(COMPOSE) stop

## Remove the shop containers and their volumes
clean:
	$(COMPOSE) down -v --remove-orphans

## Follow the shop logs
logs:
	$(COMPOSE) logs -f

## Run the offline PHP test suite
test:
	php tests/run.php

## Run the Jest suite over the browser scripts
test-js:
	npm ci --no-audit --no-fund
	npm run test:js

## Syntax-check every PHP file
lint:
	@git ls-files -z '*.php' | xargs -0 -n1 php -l > /dev/null && echo "lint: ok"

## Run PHPStan: everything against the OpenCart floor sources, the core with no OpenCart symbols, then the tests
phpstan: $(CACHE)/phpstan-$(PHPSTAN_VERSION).phar
	dev/ci/fetch-opencart.sh $(OPENCART_FLOOR) $(OPENCART_FLOOR_SHA256) $(CACHE)/opencart
	php $(CACHE)/phpstan-$(PHPSTAN_VERSION).phar analyse --configuration=phpstan.neon --no-progress --memory-limit=1G
	php $(CACHE)/phpstan-$(PHPSTAN_VERSION).phar analyse --configuration=phpstan-core.neon --no-progress --memory-limit=1G
	php $(CACHE)/phpstan-$(PHPSTAN_VERSION).phar analyse --configuration=phpstan-tests.neon --no-progress --memory-limit=1G

$(CACHE)/phpstan-$(PHPSTAN_VERSION).phar:
	@mkdir -p $(CACHE)
	curl -fsSL -o $@.tmp "https://github.com/phpstan/phpstan/releases/download/$(PHPSTAN_VERSION)/phpstan.phar"
	echo "$(PHPSTAN_SHA256)  $@.tmp" | sha256sum -c --quiet -
	mv $@.tmp $@

## Start the E2E shop and the Two API stub (or Two's sandbox with E2E_SANDBOX_API_KEY): make e2e-up E2E_OPENCART=4.1.0.0 E2E_OPENCART_SHA256=...
e2e-up:
	tests/e2e/bin/tls.sh
	$(E2E_COMPOSE) up -d --build --wait db
	$(E2E_COMPOSE) up -d --build
	tests/e2e/bin/prepare.sh

## Run the Playwright suite against the E2E shop (make e2e-up first)
e2e-test:
	cd tests/e2e && npm ci --no-audit --no-fund && npx playwright test

## Start the E2E shop, run the suite, take the shop down
e2e:
	$(MAKE) e2e-up
	$(MAKE) e2e-test; status=$$?; $(MAKE) e2e-down; exit $$status

## Remove the E2E shop, the stub and their data
e2e-down:
	$(E2E_COMPOSE) --profile stub down -v --remove-orphans

## Build dist/two.ocmod.zip from HEAD
archive:
	dev/package.sh

## Bump the patch version (install.json), commit, no tag or push
patch:
	bumpver update --patch

## Bump the minor version
minor:
	bumpver update --minor

## Bump the major version
major:
	bumpver update --major
