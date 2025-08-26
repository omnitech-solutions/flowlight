# Makefile — Flowlight
#
# Quick Start (day-to-day)
#   make test                      # run tests (all) — add TEST=… to filter
#   make lint                      # fix code style (Pint)
#   make static                    # PHPStan + Pint (check mode)
#   make ci                        # lint + static + tests + coverage, then prompt for PHPStan on tests
#
# Maintenance
#   make static-phpstan-update-baseline         # refresh PHPStan baseline for src/
#   make static-phpstan-update-baseline-tests   # refresh PHPStan baseline for tests/
#   make coverage-show                           # open HTML coverage report
#   make clean                                   # remove build/test artifacts
#
# Shell configuration ensures strict, fail-fast behavior.

# ---- Shell (strict, fail-fast)
SHELL := /bin/zsh
.SHELLFLAGS := -eu -o pipefail -c

# ---- Binaries
PHP           ?= php           # PHP binary (overridable)
COMPOSER      ?= composer      # Composer binary
PEST          := ./vendor/bin/pest    # Pest test runner
PINT          := ./vendor/bin/pint    # Pint code style
PHPSTAN       := ./vendor/bin/phpstan # PHPStan static analysis
TEST_ENV      := .env.testing

# ---- Params (override on CLI)
TEST             ?=              # Optional: restrict test suite to a path/pattern
PHP_MEMORY_LIMIT ?= 2048M        # Memory limit for PHPStan
PHPSTAN_PARAMS   ?=              # Extra params for PHPStan
CS_PARAMS        ?=              # Extra params for Pint

# ---- Paths
COVERAGE_DIR  := coverage-html   # Coverage HTML output
COVERAGE_XML  := coverage.xml    # Coverage XML output (e.g. for CI tools)

.PHONY: help install test coverage coverage-show lint static static-phpstan \
        static-phpstan-update-baseline static-codestyle-fix static-codestyle-check \
        ci clean

# Show available targets
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

# Install PHP dependencies
install:
	$(COMPOSER) install --no-interaction --prefer-dist --optimize-autoloader

# Run tests (optionally restricted via TEST=...)
test:
	@if [ -n "$(TEST)" ]; then \
		[ -f $(TEST_ENV) ] && source $(TEST_ENV); \
		$(PEST) $(TEST); \
	else \
		[ -f $(TEST_ENV) ] && source $(TEST_ENV); \
		$(PEST); \
	fi

coverage:
	@if [ -n "$(TEST)" ]; then \
		[ -f $(TEST_ENV) ] && source $(TEST_ENV); \
		XDEBUG_MODE=coverage $(PEST) --coverage --coverage-clover=$(COVERAGE_XML) --coverage-html=$(COVERAGE_DIR) $(TEST); \
	else \
		[ -f $(TEST_ENV) ] && source $(TEST_ENV); \
		XDEBUG_MODE=coverage $(PEST) --coverage --coverage-clover=$(COVERAGE_XML) --coverage-html=$(COVERAGE_DIR); \
	fi

# Open the HTML coverage report (platform-aware)
coverage-show:
	@if [ -d "$(COVERAGE_DIR)" ]; then \
		if command -v xdg-open >/dev/null 2>&1; then xdg-open $(COVERAGE_DIR)/index.html; \
		elif command -v open >/dev/null 2>&1; then open $(COVERAGE_DIR)/index.html; \
		elif command -v start >/dev/null 2>&1; then start $(COVERAGE_DIR)/index.html; \
		else echo "Open $(COVERAGE_DIR)/index.html in your browser."; fi \
	else \
		echo "No coverage report found. Run 'make coverage' first."; \
	fi

# Fix code style using Pint
lint:
	$(PINT) $(CS_PARAMS)

# Composite target: PHPStan + code style check
static: static-phpstan static-codestyle-check

# Run PHPStan on src/ (skips if no PHP files)
static-phpstan:
	@if [ -z "$$(find src -type f -name '*.php' 2>/dev/null)" ]; then \
		echo "PHPStan: no PHP files under src/ — skipping."; \
	else \
		$(PHP) -d memory_limit=$(PHP_MEMORY_LIMIT) $(PHPSTAN) analyse $(PHPSTAN_PARAMS); \
	fi

# Run PHPStan on tests/ (separate config)
static-phpstan-tests:
	@if [ -z "$$(find tests -type f -name '*.php' 2>/dev/null)" ]; then \
		echo "PHPStan (tests): no PHP files under tests/ — skipping."; \
	else \
		$(PHP) -d memory_limit=$(PHP_MEMORY_LIMIT) $(PHPSTAN) analyse -c phpstan.tests.neon.dist $(PHPSTAN_TESTS_PARAMS); \
	fi

# Update PHPStan baseline for src/
static-phpstan-update-baseline:
	$(MAKE) static-phpstan PHPSTAN_PARAMS="--generate-baseline"

# Update PHPStan baseline for tests/
static-phpstan-update-baseline-tests:
	$(MAKE) static-phpstan-tests PHPSTAN_TESTS_PARAMS="--generate-baseline"

# Auto-fix code style
static-codestyle-fix:
	$(PINT) $(CS_PARAMS)

# Check code style without fixing
static-codestyle-check:
	$(PINT) --test $(CS_PARAMS)

# Continuous Integration workflow (fail-fast)
# Runs lint, static checks, tests, and coverage.
# Interactive prompt at the end:
#   y/Y → run PHPStan on tests
#   n/N or ENTER/anything else → skip
ci:
	@echo "Running CI (fail-fast)..."
	$(MAKE) lint && \
	$(MAKE) static && \
	$(MAKE) test && \
	$(MAKE) coverage
	@if [ -t 0 ]; then \
		if read -q "?Run PHPStan on tests? [y/N]: " ; then \
			echo ; $(MAKE) static-phpstan-tests ; \
		else \
			echo ; echo "Skipping static-phpstan-tests" ; \
		fi ; \
	else \
		echo "Non-interactive shell; skipping static-phpstan-tests" ; \
	fi

# Clean build/test artifacts
clean:
	rm -rf .phpunit.result.cache .pest.cache .php-cs-fixer.cache \
	       $(COVERAGE_DIR) $(COVERAGE_XML) coverage/ infection.log
