# sportmate-interview

Laravel 13 projekt Vue 3, Inertia 3, TypeScript és Tailwind CSS 4 alappal.
A kezdőoldalon Git source-ok oldalsávja, kijelölése és hozzáadó modálja érhető el;
a forrásokat SQLite tárolja, a health endpoint: `/up`.

Az oldalsáv a REST API-ból egyszerre 10 forrást tölt be, legújabbal kezdve.
Az oldal egy évig érvényes, titkosított HttpOnly `git_sources_page` cookie-ban
marad meg. A kereső csak az aktuális oldal elemeit szűri. A kijelölés lapozáskor
megmarad; újratöltéskor a visszaállított oldal első forrása lesz kijelölve.
Az adatbázis üresen indul, nincs mock seed. A forrás létrehozásakor a backend
ellenőrzi a GitHub-fiókot, elmenti a profiladatait, és ugyanabban az SQLite
tranzakcióban queue-ba teszi az első repository-szinkront. Meglévő forrásnál
a profil alatti Sync gomb indítja vagy hiba után folytatja a munkát.

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
HTTP-kérést és több memóriát igényel. A queue ezért a `getRepositoriesPage()`
metódust használja, egyszerre legfeljebb 100 repositoryval. Külön metódus
olvassa a friss metaadatot, a nyitott PR-ok lapját és az utolsó commit idejét;
mindegyik legfeljebb egy HTTP-kérést végez. A válaszadatokat nem cache-eljük,
de a GitHub által jelzett kvótavárakozást megosztjuk a források között.

A közös readonly `RemoteRepository` mezői: `id`, `name`, `fullName`, `url` és
nullable `description`. Az azonosító providerrel együtt egyedi, például
`github:123`. A readonly `GitHubRepository` további mezői: `stars`, `forks`,
nullable `language` és `archived`. A GitHub-provider listájának elemei
`GitHubRepository` példányok; a közös source-on át kapott elemeknél
`instanceof GitHubRepository` szűkíti a
típust. Új provider a közös interface implementálásával adható hozzá; a GitHub
névszabályai és metaadatai nem részei a közös szerződésnek.

A GitHub PAT opcionális. A szerver `.env` fájljában:

```dotenv
GITHUB_PAT=
```

Nem üres értéknél minden GitHub-kérés Bearer hitelesítést használ, beleértve
a profilfelderítést, lapozást, PR- és commit-lekéréseket. Üres vagy hiányzó
értéknél az Authorization fejléc teljesen kimarad. A konfiguráció kulcsa
`services.github.pat`; a token nem kerül a böngészőbe vagy a queue payloadjába.
Hibás tokennél nincs automatikus anonim újrapróbálás. PAT mellett is kizárólag
publikus, az adott forráshoz tartozó repositorykat szinkronizálunk.

A GitHub alapértelmezett [REST-kerete](https://docs.github.com/en/rest/using-the-rest-api/rate-limits-for-the-rest-api)
anonim kéréseknél 60 kérés/óra/IP, PAT-tal 5000 kérés/óra/felhasználó.
A lapozási kérések külön számítanak; másodlagos korlátok is léteznek.
A kapcsolat timeoutja 3 másodperc, az egyes kéréseké 10 másodperc,
a rögzített API-verzió `2026-03-10`.

A hibák az `App\Git\Exceptions\GitProviderException` családba tartoznak:
`SourceNotFoundException` jelzi a 404-et, `RateLimitException` a kérési limitet
(nullable `retryAt` időponttal), `InvalidResponseException` a hibás választ.
`AuthenticationException` jelzi a hibás PAT-ot, `AccessDeniedException`
a kvótakorláttól különálló hozzáférési hibát.
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
- `GET /api/git-sources/{gitSource}`: az aktuális profil és sync állapot.
- `POST /api/git-sources/{gitSource}/sync`: `202` és `{ "data": ... }`;
  elindítja vagy sikertelen futás után folytatja a szinkront. Aktív futásnál
  új job nélkül visszaadja az aktuális állapotot.
- `GET /api/git-sources/{gitSource}/repositories?page=2`: tízelemű, SQL-szinten
  lapozott `data` és `meta`. Rendezés: kis/nagybetűtől független név, majd
  external ID. A listázás és a profil olvasása nem hívja a GitHubot.

A nyilvános időbélyegek Unix-másodpercek vagy `null`. A kliens a last sync
és last commit dátumát `new Date(timestamp * 1000).toDateString()` segítségével
formázza. A GitSource további mezői: `sync_status`, `last_sync_error_code`,
`last_sync_error_at`, `sync_retry_at`, `sync_revision`. A belső checkpoint és
futásazonosító nem része az API-nak.

A `StoreGitSourceRequest` közös szabályait a frontend 400 ms késleltetésű
Precognition-kéréssel használja; nincs második JavaScript-regex vagy kliensoldali
duplikációellenőrzés. A Precognition nem ment és nem hív GitHubot. A tényleges
létrehozás egyetlen account-lookupot végez. A provider a szigorú, mai GitHub
névszintaxist ellenőrzi; régi és enterprise-névkivételeket nem fogad el.

A `422` válasz mezőhibáiban fordítási kulcsok vannak. Működési hibánál `code`
érkezik: rate limit `429`, hibás provider-válasz `502`, elérhetetlenség `503`,
hibás PAT vagy tiltott hozzáférés `502`, váratlan szerverhiba `500`.
A frontend a kulcsokat az angol szótárból jeleníti meg; tartós sync hiba a
profil alatt látható időponttal. Ismeretlen hiba szövege `Unknown issue occurred.`
Nyers provider-válasz, stack trace és token nem kerül a felületre. A szervernapló
forrás- és futásazonosítót, hibakódot és biztonságos technikai kontextust őriz.

## Repositoryk és folytatható szinkron

Az `App\Models\RemoteRepository` Eloquent modell különbözik az azonos rövid
nevű provider DTO-tól. Primary key az external ID, például `github:123`.
A modell a tulajdonos GitSource-ot, nevet, leírást, fő nyelvet, archived állapotot,
stars/forks és külön nyitott issue/PR-számot tárolja. `getUrl()` a provider
prefixéből, a GitSource `account` mezőjéből és a repository nevéből építkezik.

A `last_committed_at` a default branch legfelső commitjának committer-időpontja,
nem a repository `updated_at` vagy `pushed_at` értéke. Üres repositorynál `null`.
Az új repository létrejön; az ismert ID mezői frissülnek, átnevezéskor is.
Eltűnt vagy priváttá vált repository korábbi helyi rekordja megmarad.
Futás közbeni átnevezésnél a régi URL átirányítását nem követjük: az elemet
átugorjuk, a következő teljes szinkron az új névvel frissíti. Repository-404
után külön kérés ellenőrzi, hogy maga a GitSource továbbra is létezik.
A teljesen feldolgozott repositoryk fokozatosan jelennek meg a profil alatt;
az archiváltak sárgás keretet és szöveges jelölést kapnak.

A sync állapotai: `idle`, `queued`, `syncing`, `waiting`, `succeeded`, `failed`.
A checkpoint az SQLite adatbázisban marad, ezért kvótavárakozás vagy worker
újraindítása után nem kell a teljes forrást újrakezdeni. Forrásonként egy futás
aktív; az írásokat lock és revízióellenőrzés védi. A `last_synced_at` csak
teljes siker után frissül, és csak ekkor törlődik a legutóbbi hiba.

Rate limitnél a job késleltetve visszakerül a queue-ba. Átmeneti hibánál
lépésenként legfeljebb három próbálkozás történik, 10 és 60 másodperces
késleltetéssel. A kvótavárakozás nem fogyasztja ezt a keretet. A kézi Sync gomb
a mentett ponttól folytatja a sikertelen futást. Nincs rendszeres automatikus
időzítés.

`composer dev` a web/Vite folyamatok mellett a queue listenert is indítja,
60 másodperces külső process-timeouttal Windows alatt is. Külön worker:

```sh
php artisan queue:listen --tries=0 --timeout=60
```

A job timeoutja 60 másodperc, az overlap lock 75, a database queue
`retry_after` értéke 90 másodperc. A szándékos folytatások miatt korlátlan
attempt engedélyezett; külön hibaszámláló és crash-védelem állítja meg a
hibás futásokat. A queue és az alkalmazás ugyanazt az SQLite-kapcsolatot
használja az atomikus indításhoz; a tartós `database` cache szükséges a
folyamatok közötti lockhoz és crash-védelemhez.

Token vagy más szerverkonfiguráció változtatása után a konfigurációcache-t
frissíteni kell (`php artisan config:clear` fejlesztésben, `config:cache`
cache-elt telepítésnél), a tartós queue workereket pedig újra kell indítani.
A mentett checkpoint eközben megmarad.

A Wayfinder route-függvényeit a Vite és a `types:check` előtti lépés generálja;
a generált könyvtárakat nem kell verziókezelni. Meglévő telepítés frissítésekor
a függőségek telepítése után futtasd a migrációkat és a frontend buildet.

A provider tesztjei hálózat nélkül, az API tesztjei külön SQLite memória-DB-vel
futnak:

```sh
php artisan test --compact tests/Feature/Git
php artisan test --compact --filter=GitSources
```
