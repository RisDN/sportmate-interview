# GitHub repository azonosítója

- Platform: ChatGPT
- Projekt: Sportmate
- Kezdés: 2026. 10. 02. 13:18:48 (Europe/Budapest)

> Forrás: a Sportmate ChatGPT-projekt teljes, egyfordulós beszélgetése. A hivatkozások sorszámai megmaradtak; az eredeti cél-URL-eket a csevegésolvasó nem adta vissza.

## User – 2026-10-02T11:18:46.890Z

A github api ad vissza valami repository id-t, ami nem módosul akkor sem, ha a repository tulajdonos neve vagy a repository neve változik?

## Final report – 2026-10-02T11:20:34.549Z

Igen. **A GitHub REST API válaszában a repository saját, számszerű `id` mezőjét érdemes használnod.** Ez ugyanaz marad, ha átnevezik a repositoryt, megváltozik a tulajdonos felhasználó- vagy organization-neve, illetve ha a repositoryt másik tulajdonoshoz helyezik át. A GitHub is az `id` tárolását javasolja a változtatható nevek helyett. [eredeti hivatkozás 1]

### Hol találod a válaszban?

A repository lekérdezésekor és a repositorykat listázó végpontok válaszában is szerepel. Például a dokumentáció mintaválaszának egy részlete: [eredeti hivatkozás 2]

```json
{
  "id": 1296269,
  "node_id": "MDEwOlJlcG9zaXRvcnkxMjk2MjY5",
  "name": "Hello-World",
  "full_name": "octocat/Hello-World",
  "owner": {
    "login": "octocat",
    "id": 1
  }
}
```

Itt **a felső szintű `id`, azaz `1296269` a repository azonosítója**. Az `owner.id` ezzel szemben a tulajdonosé: tulajdonosváltáskor ez változik, nem a repository saját azonosítója. [eredeti hivatkozás 3]

### A te alkalmazásodban

Én egy **`github_id` mezőben, `BIGINT` típussal és egyedi indexszel** tárolnám, és szinkronizáláskor ez alapján keresném meg a meglévő rekordot. A `name`, `full_name` és `owner.login` mezőket frissíthető adatként kezelném, nem állandó azonosítóként. Ez követi a GitHub azonosítók tárolására vonatkozó ajánlását. [eredeti hivatkozás 4]

Így például egy átnevezéstől nem jön létre új repository-rekord az adatbázisodban, hanem a meglévő rekord neve frissül.

**Név nélküli lekérdezéshez** a `node_id` mezőt is elmentheted: ezzel a GraphQL API `node(id: ...)` lekérdezésén keresztül kérheted le az objektumot, a tulajdonos és a repository nevének ismerete nélkül. A REST `node_id` mezője a GraphQL `id` mezőjének felel meg. [eredeti hivatkozás 5]

Egy fontos különbség: a `node_id` formátumához a GitHub dokumentál migrációt, ezért annak szöveges alakját ne tekintsd örökre változatlannak. **A REST-alapú adatbázis-azonosításodhoz a számszerű `id` legyen az alap.** [eredeti hivatkozás 6]
