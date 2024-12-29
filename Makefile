init: docker-down docker-pull docker-build docker-up init-app

docker-up:
	docker compose up -d

docker-down:
	docker compose down --remove-orphans

docker-pull:
	docker compose pull

docker-build:
	docker compose build --pull

init-app: composer-install generate-app-key symlink-storage migrate

composer-install:
	docker compose run --rm composer install

generate-app-key:
	docker compose run --rm artisan key:generate

symlink-storage:
	docker compose run --rm artisan storage:link

migrate:
	docker compose run --rm artisan october:migrate

demo-data:
	docker compose run --rm artisan poster:import --force

list-php-extensions:
	docker compose run --rm php php -r "print_r(get_loaded_extensions());"

#backend-migrations:
#	docker compose run --rm artisan migrate --seed


