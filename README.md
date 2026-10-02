# sportmate-interview

Laravel 13 projekt Vue 3, Inertia 3, TypeScript és Tailwind CSS 4 alappal.
A kezdőoldalon Git source-ok kereshető oldalsávja, kijelölése és hozzáadó modálja
próbálható ki GitHub mock adatokkal; a health endpoint: `/up`.

A source-ok és a kijelölés csak az aktuális oldal memóriájában élnek: újratöltéskor
visszaállnak a mock adatok, nincs böngészőtárhely, szerveroldali mentés vagy Git API
hívás. A providerek Vue-komponenst fogadó `GitSourceProvider` interface-t követnek
(`resources/js/types/git-source.ts`). Az angol feliratok a
`resources/js/locales/en.ts` szótárban bővíthetők; a típusos `t()` segéd
(`resources/js/lib/translate.ts`) kulcsokkal és behelyettesíthető paraméterekkel
adja vissza őket.

A jobb felső sarok kerek ikongombja kattintásra világos és sötét téma között vált.
Az első választásig a böngésző témáját követi; a választás egy évig érvényes
`theme` cookie-ba kerül.
A szerver ebből állítja be a HTML témáját és az Inertia propot, így a mentett
megjelenés már az első kirajzoláskor érvényes.

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
