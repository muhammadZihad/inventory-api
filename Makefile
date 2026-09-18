DOCKER_COMPOSE=docker compose
APP=$(DOCKER_COMPOSE) exec app
ROOT_APP=$(DOCKER_COMPOSE) exec -u root app

.PHONY: setup build up down restart shell migrate fresh seed test lint docs queue scheduler logs passport fix-permissions assets

# The .env file is copied before the containers start, because it is bind
# mounted into them rather than baked into the image.
setup:
	cp -n .env.example .env || true
	$(MAKE) build
	$(MAKE) up
	$(ROOT_APP) git config --global --add safe.directory /var/www/html
	$(ROOT_APP) mkdir -p /var/www/.composer/cache
	$(ROOT_APP) composer install
	$(ROOT_APP) php artisan key:generate
	$(ROOT_APP) php artisan migrate --force
	$(ROOT_APP) php artisan passport:keys --force
	$(ROOT_APP) php artisan passport:client --personal --name="Order Inventory Personal Access Client" --no-interaction
	$(ROOT_APP) php artisan db:seed
	$(ROOT_APP) php artisan scribe:generate
	$(MAKE) assets
	$(ROOT_APP) chmod -R a+rwX storage bootstrap/cache vendor
	$(ROOT_APP) chown www-data:www-data storage/oauth-*.key || true
	$(ROOT_APP) chmod 660 storage/oauth-*.key || true

build:
	$(DOCKER_COMPOSE) build

up:
	$(DOCKER_COMPOSE) up -d

down:
	$(DOCKER_COMPOSE) down

restart: down up

shell:
	$(APP) bash

migrate:
	$(APP) php artisan migrate

fresh:
	$(APP) php artisan migrate:fresh --seed
	$(APP) php artisan passport:client --personal --name="Order Inventory Personal Access Client" --no-interaction

seed:
	$(APP) php artisan db:seed

# The PHP image has no Node, so the console is built on the host and served
# from public/build through the bind mount.
assets:
	npm install
	npm run build

test:
	$(APP) php artisan test

lint:
	$(APP) ./vendor/bin/pint --test

docs:
	$(APP) php artisan scribe:generate

queue:
	$(APP) php artisan queue:work redis --sleep=1 --tries=3 --timeout=90

scheduler:
	$(APP) php artisan schedule:work

passport:
	$(ROOT_APP) php artisan passport:keys --force
	$(ROOT_APP) chown www-data:www-data storage/oauth-*.key || true
	$(ROOT_APP) chmod 660 storage/oauth-*.key || true
	$(APP) php artisan passport:client --personal --name="Order Inventory Personal Access Client" --no-interaction

fix-permissions:
	$(ROOT_APP) git config --global --add safe.directory /var/www/html
	$(ROOT_APP) chmod -R a+rwX storage bootstrap/cache vendor
	$(ROOT_APP) chown www-data:www-data storage/oauth-*.key || true
	$(ROOT_APP) chmod 660 storage/oauth-*.key || true

logs:
	$(DOCKER_COMPOSE) logs -f
