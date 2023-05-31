Start the development docker:

(remember to update README)

#Make a copy of .env.example to .env, then bring up the docker compose:
Make a copy of ./env to .env, then bring up the docker compose:

`sudo ./vendor/bin/sail php artisan migrate`

`sudo ./vendor/bin/sail up`

Run the hot module replacement:

`sudo ./vendor/bin/sail npm run dev`

Laravel Sail is just a frontend to docker compose, you can pass it commands to execute in the app container:

`./vendor/bin/sail artisan test`

`./vendor/bin/sail composer dump-autoload`

The site is available at: http://localhost:8400/

The database frontend Adminer is available at: http://localhost:8401
