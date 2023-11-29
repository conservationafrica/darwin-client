# Run `make` (no arguments) to get a short description of what is available
# within this `Makefile`.

help: ## shows this help
	@awk 'BEGIN {FS = ":.*?## "} /^[a-zA-Z_\-\.]+:.*?## / {printf "\033[36m%-30s\033[0m %s\n", $$1, $$2}' $(MAKEFILE_LIST)
.PHONY: help

autoload: ## Dump the composer autoloader
	composer dump-autoload --optimize
.PHONY: autoload

qa: autoload tests static-analysis deps check-code-style ## run all static quality assurance jobs

tests: ## run tests
	vendor/bin/phpunit
.PHONY: tests

deps: ## check for un-declared dependencies
	vendor/bin/composer-require-checker check
.PHONY: deps

bump: ## bump development dependencies
	composer update; composer bump -D; composer update;
.PHONY: bump

bump-all: ## bump all dependencies
	composer update; composer bump; composer update;
.PHONY: bump-all

static-analysis: ## verify code type-level soundness
	vendor/bin/psalm --no-cache
.PHONY: static-analysis

check-code-style: ## verify coding standards are respected
	vendor/bin/phpcs
.PHONY: check-code-style

fix-code-style: ## auto-fix coding standard rules, where possible
	vendor/bin/phpcbf
.PHONY: fix-code-style
