# sportmate-interview

Laravel 13 projekt Vue 3, Inertia 3, TypeScript és Tailwind CSS 4 alappal.
A kezdőoldalon Git source-ok kereshető oldalsávja, kijelölése és hozzáadó modálja
próbálható ki GitHub mock adatokkal; a health endpoint: `/up`.

A source-ok és a kijelölés csak az aktuális oldal memóriájában élnek: újratöltéskor
visszaállnak a mock adatok, nincs böngészőtárhely vagy szerveroldali mentés, és a
felület nem indít Git API-hívást. A frontend providerek Vue-komponenst fogadó
`GitSourceProvider` interface-t követnek
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

## Szerveroldali Git-providerek

Az `App\Git\GitProvider` szinkron, csak olvasó szerződését a
`App\Git\GitHub\GitHubProvider` valósítja meg. A Laravel konténer a közös
interface-hez ezt az implementációt rendeli. Alkalmazáskódban a providert
konstruktorparaméterként lehet injektálni; az alábbi példa Tinkerben is futtatható:

```php
use App\Git\GitProvider;

$provider = app(GitProvider::class);
$source = $provider->getSource('laravel');

$provider->getName();       // 'GitHub'
$source->getName();         // 'laravel'
$source->getAccountType();  // AccountType::Organization, újabb HTTP-kérés nélkül
$repositories = $source->getRepositories(); // list<RemoteRepository>
```

A `getSource()` felderíti a fiókot a távoli API-ból; a provider
`getAccountType(string $name)` metódusa önálló felderítésre használható.
A source a létrehozó providerpéldányhoz kötött. A repository-lekérés a fiók saját
publikus repository-jainak teljes listáját adja vissza, forkokkal és archivált
elemekkel együtt, az összes API-oldal bevárása után. Nagy fióknál ez több
HTTP-kérést és több memóriát igényel. Minden új lekérés friss adatot kér;
nincs cache vagy automatikus újrapróbálás.

A közös readonly `RemoteRepository` mezői: `id`, `name`, `fullName`, `url` és
nullable `description`. Az azonosító providerrel együtt egyedi, például
`github:123`. A readonly `GitHubRepository` további mezői: `stars`, `forks`,
nullable `language` és `archived`. GitHub-specifikus kódban a konkrét
`GitHubProvider::getRepositories()` visszatérési típusa `list<GitHubRepository>`;
a közös source-on át kapott elemeknél `instanceof GitHubRepository` szűkíti a
típust. Új provider a közös interface implementálásával adható hozzá; a GitHub
névszabályai és metaadatai nem részei a közös szerződésnek.

A GitHub-hívások nem használnak PAT-ot vagy Authorization fejlécet. A publikus
[fiókfelderítés](https://docs.github.com/en/rest/users/users#get-a-user) és
[repository-listázás](https://docs.github.com/en/rest/repos/repos) hitelesítés
nélkül is elérhető. A GitHub alapértelmezett
[kerete 60 kérés/óra/IP](https://docs.github.com/en/rest/using-the-rest-api/rate-limits-for-the-rest-api);
a lapozási kérések külön számítanak. A kapcsolat timeoutja 3 másodperc, az egyes
kéréseké 10 másodperc, a rögzített API-verzió `2026-03-10`.

A hibák az `App\Git\Exceptions\GitProviderException` családba tartoznak:
`SourceNotFoundException` jelzi a 404-et, `RateLimitException` a kérési limitet
(nullable `retryAt` időponttal), `InvalidResponseException` a hibás választ.
A HTTP-hibaválaszok státuszát a `statusCode` mező őrzi meg. Hiba esetén nem kapunk
üres vagy részleges repository-listát. Hibás fióknév vagy más providerpéldányhoz
tartozó source esetén `InvalidArgumentException` keletkezik.

A szerveroldali réteghez nincs külön HTTP-végpont, adatbázistábla vagy
frontend-bekötés. Tesztjei hálózat és adatbázis nélkül futnak:

```sh
php artisan test --compact tests/Feature/Git
```
