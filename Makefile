# Makefile — Flowlight

# ---- Shell (strict, fail-fast)
SHELL := /bin/zsh
.SHELLFLAGS := -eu -o pipefail -c

# ---- Binaries
PHP           ?= php
COMPOSER      ?= composer
PEST          := ./vendor/bin/pest
PINT          := ./vendor/bin/pint
PHPSTAN       := ./vendor/bin/phpstan

# ---- Params (override on CLI)
TEST          ?=
PHPSTAN_PARAMS?=
CS_PARAMS     ?=

# ---- Paths
COVERAGE_DIR  := coverage-html
COVERAGE_XML  := coverage.xml

.PHONY: help install test coverage coverage-show lint static static-phpstan static-phpstan-update-baseline static-codestyle-fix static-codestyle-check ci clean

help:
	@echo "Usage: make <target>"
	@echo ""
	@echo "  install                         Install dependencies"
	@echo "  test            [TEST=...]      Run test suite (Pest). Optionally pass a path/pattern"
	@echo "  coverage        [TEST=...]      Run tests with coverage (requires Xdebug/PCOV)"
	@echo "  coverage-show                   Open the HTML coverage report"
	@echo "  lint   [CS_PARAMS=...]          Run Pint to fix style (default: all code)"
	@echo "  static                          Run static checks (PHPStan + Pint --test)"
	@echo "  static-phpstan [PHPSTAN_PARAMS=...]  Run PHPStan"
	@echo "  static-phpstan-update-baseline  Regenerate PHPStan baseline"
	@echo "  static-codestyle-fix [CS_PARAMS=...]  Run Pint to fix style"
	@echo "  static-codestyle-check [CS_PARAMS=...] Run Pint in check mode (no changes)"
	@echo "  ci                              Run lint + static + tests + coverage (fail-fast)"
	@echo "  clean                           Remove build/test artifacts"

install:
	$(COMPOSER) install --no-interaction --prefer-dist --optimize-autoloader

test:
	@if [ -n "$(TEST)" ]; then \
		$(PEST) $(TEST); \
	else \
		$(PEST); \
	fi

coverage:
	@if [ -n "$(TEST)" ]; then \
		XDEBUG_MODE=coverage $(PEST) --coverage --coverage-clover=$(COVERAGE_XML) --coverage-html=$(COVERAGE_DIR) $(TEST); \
	else \
		XDEBUG_MODE=coverage $(PEST) --coverage --coverage-clover=$(COVERAGE_XML) --coverage-html=$(COVERAGE_DIR); \
	fi

coverage-show:
	@if [ -d "$(COVERAGE_DIR)" ]; then \
		if command -v xdg-open >/dev/null 2>&1; then xdg-open $(COVERAGE_DIR)/index.html; \
		elif command -v open >/dev/null 2>&1; then open $(COVERAGE_DIR)/index.html; \
		elif command -v start >/dev/null 2>&1; then start $(COVERAGE_DIR)/index.html; \
		else echo "Open $(COVERAGE_DIR)/index.html in your browser."; fi \
	else \
		echo "No coverage report found. Run 'make coverage' first."; \
	fi

lint:
	$(PINT) $(CS_PARAMS)

static: static-phpstan static-codestyle-check

static-phpstan:
	@if [ -z "$$(find src -type f -name '*.php' 2>/dev/null)" ]; then \
		echo "PHPStan: no PHP files under src/ — skipping."; \
	else \
		$(PHPSTAN) analyse $(PHPSTAN_PARAMS); \
	fi

static-phpstan-update-baseline:
	$(MAKE) static-phpstan PHPSTAN_PARAMS="--generate-baseline"

static-codestyle-fix:
	$(PINT) $(CS_PARAMS)

static-codestyle-check:
	$(PINT) --test $(CS_PARAMS)

ci:
	@echo "Running CI (fail-fast)..."
	$(MAKE) lint && \
	$(MAKE) static && \
	$(MAKE) test && \
	$(MAKE) coverage

clean:
	rm -rf .phpunit.result.cache .pest.cache .php-cs-fixer.cache \
	       $(COVERAGE_DIR) $(COVERAGE_XML) coverage/ infection.log
