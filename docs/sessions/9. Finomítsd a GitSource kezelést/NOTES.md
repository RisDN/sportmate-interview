pár fine-tuning igényem volt, ezeket részleteztem neki

A bal oldali navbarban lévő keresőt kötöttem a rest api-hoz.

Terveztettem vele egy törlés funkciót is. Itt fontosan tartottam, hogy egy gitsource törlése esetén ne töröljön ki instant mindent szinkron, hanem jelöljük meg törlésre, és majd a háttérben egy background job szépen kitörli az adott gitsource-t és az összes hozzátartozó repository-t. Egy törlésre flagelt gitsource-t kihagyja a sync vagy leállítja a syncet ha épp folyamatban van, és újat nem enged törölni. Ez a google organization-jének tesztelésekor volt nagyon jó, mert nekik majdnem 3000 repositoryjuk van

ezen felül volt egy érdekes hiba, hogy egy szinkron alatt lévő gitsource-t megnyitva folyamatosan amikor frissült lekérdezte a paginationben megnyitott oldal tartalmát, még akkor is ha azon belül nem volt változás.
