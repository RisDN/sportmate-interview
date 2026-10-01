# sportmate-interview

Tiszta Laravel 13 projekt Vue 3, Inertia 3, TypeScript és Tailwind CSS 4 alappal.
A kezdőoldal üres; a health endpoint: `/up`.

PHP 8.5, Composer 2 és Node.js 22.18+ szükséges.

```sh
composer setup
composer dev
```

A `composer setup` telepíti a függőségeket, előkészíti a `.env` fájlt és az SQLite
adatbázist, futtatja a migrációkat, majd elkészíti a frontend buildet.

```sh
composer ci:check
```

Az ellenőrzés frontend lintet, formázást és típusellenőrzést, valamint Pint,
Larastan és Pest ellenőrzéseket futtat.
