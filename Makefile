# jtrader — Docker helper targets
# Usage: `make <target>`. Most targets run inside the `app` container.

DC := docker compose
EXEC := $(DC) exec -u app app

# Pass host UID/GID into the build so bind-mounted files stay owned by you.
export UID := $(shell id -u)
export GID := $(shell id -g)

.DEFAULT_GOAL := help

.PHONY: help
help: ## Show this help
	@grep -hE '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | \
		awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-16s\033[0m %s\n", $$1, $$2}'

.PHONY: build
build: ## Build the app image
	$(DC) build

.PHONY: up
up: ## Start the full stack (app, web, db, redis, queue, scheduler)
	$(DC) up -d

.PHONY: dev
dev: ## Start the stack + Vite dev server (HMR)
	$(DC) --profile dev up -d

.PHONY: down
down: ## Stop and remove containers
	$(DC) down

.PHONY: restart
restart: down up ## Restart the stack

.PHONY: logs
logs: ## Tail logs from all services
	$(DC) logs -f --tail=100

.PHONY: sh
sh: ## Open a shell in the app container
	$(EXEC) bash

.PHONY: composer
composer: ## Run composer (e.g. `make composer c="require foo/bar"`)
	$(EXEC) composer $(c)

.PHONY: artisan
artisan: ## Run artisan (e.g. `make artisan c="migrate"`)
	$(EXEC) php artisan $(c)

.PHONY: migrate
migrate: ## Run database migrations
	$(EXEC) php artisan migrate

.PHONY: fresh
fresh: ## Drop + re-run all migrations and seeders
	$(EXEC) php artisan migrate:fresh --seed

.PHONY: test
test: ## Run the test suite
	$(EXEC) php artisan test
