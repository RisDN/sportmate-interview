# sportmate-interview

Laravel 13 projekt Vue 3, Inertia 3, TypeScript és Tailwind CSS 4 alappal.
A kezdőoldalon Git source-ok oldalsávja, kijelölése és hozzáadó modálja érhető el;
a forrásokat SQLite tárolja, a health endpoint: `/up`.

Az oldalsáv a REST API-ból egyszerre 10 forrást tölt be, legújabbal kezdve.
Az oldal egy évig érvényes, titkosított HttpOnly `git_sources_page` cookie-ban
marad meg. A kereső csak az aktuális oldal elemeit szűri. A kijelölés lapozáskor
megmarad; újratöltéskor a visszaállított oldal első forrása lesz kijelölve.
Az adatbázis üresen indul, nincs mock seed. A forrás létrehozásakor a backend
ellenőrzi a GitHub-fiókot, és elmenti a profiladatait. A repository-k szinkronja
még nincs bekötve, ezért a `last_synced_at` kezdetben `null`.

A szerializálható `GitSource` típust a `resources/js/types/git-source.ts`
definiálja; a provider ikonja külön frontend registryben található.
Az angol feliratok és hibaüzenetek a
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
$provider->getKey();        // 'github'
$provider->isValidAccountName('laravel'); // true, HTTP-kérés nélkül
$source->getName();         // 'laravel'
$source->getDisplayName();  // megjelenített név, hiányában a kanonikus account
$source->getRemoteId();     // tartós upstream azonosító
$source->getUrl();          // profil URL
$source->getAvatarUrl();    // profilkép URL vagy null
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

## GitSource REST API

A JSON-végpontok ugyanazon origin alatt, a Laravel `web` middleware és
CSRF-védelem mellett érhetők el. A forráslista globális; nincs felhasználói
tulajdon vagy új bejelentkezési folyamat.

- `GET /api/git-sources?page=2`: `data` lista és `meta` objektum
  (`current_page`, `last_page`, `per_page`, `total`). Oldalparaméter nélkül a
  mentett cookie érvényesül. A lekérés SQL-szinten lapozott.
- `POST /api/git-sources`: `{ "provider": "github", "account": "laravel" }`;
  siker esetén `201` és `{ "data": ... }`. A metadata a provider válaszából jön.
  Account és upstream ID alapján adatbázisszintű egyediség véd a duplikációtól.

A `StoreGitSourceRequest` közös szabályait a frontend 400 ms késleltetésű
Precognition-kéréssel használja; nincs második JavaScript-regex vagy kliensoldali
duplikációellenőrzés. A Precognition nem ment és nem hív GitHubot. A tényleges
létrehozás egyetlen account-lookupot végez. A provider a szigorú, mai GitHub
névszintaxist ellenőrzi; régi és enterprise-névkivételeket nem fogad el.

A `422` válasz mezőhibáiban fordítási kulcsok vannak. Működési hibánál `code`
érkezik: rate limit `429`, hibás provider-válasz `502`, elérhetetlenség `503`,
váratlan szerverhiba `500`. A frontend a kulcsokat az angol szótárból jeleníti
meg toastként, validáció esetén mezőhibaként is.

A Wayfinder route-függvényeit a Vite és a `types:check` előtti lépés generálja;
a generált könyvtárakat nem kell verziókezelni. Meglévő telepítés frissítésekor
a függőségek telepítése után futtasd a migrációkat és a frontend buildet.

A provider tesztjei hálózat nélkül, az API tesztjei külön SQLite memória-DB-vel
futnak:

```sh
php artisan test --compact tests/Feature/Git
php artisan test --compact --filter=GitSources
```
