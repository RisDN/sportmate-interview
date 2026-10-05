# Halaszd a validációt mentésig

- Platform: Codex
- Projekt: Sportmate
- Kezdés: 2026. 10. 02. 12:32:11 (Europe/Budapest)

> Szűrt beszélgetés: felhasználói üzenetek, kérdések és válaszok, minden feladat záróválasza.

## User – 2026-10-02T10:35:36.073Z

A New Source modal-ban, van egy input, ahova a username-t vagy az org name-t kell beírni.



Ha erről az inputról elveszik a focus vagy letelik a debounce, akkor mindig egy új lekérdezést indít, és alul egy pillanatra felvillan a loading icon, emiatt ugrik egyet a UI.



Két lehetséges opciót látok:

- Vagy kiszedjük a Loader-t, és csak akkor jelenítünk meg a helyén bármit is, ha hiba van.
- Vagy az egész validációt csak akkor futtatjuk le, amikor a Create Source gombot megnyomják.



Én az utóbbit javaslom, te mit gondolsz?


## Final report – 2026-10-02T10:36:43.727Z

Ris, egyetértek: **validáció csak Create Source-ra vagy Enteres beküldéskor**.

A [jelenlegi komponensben](C:/Users/Ris/Documents/GitHub/sportmate-interview/resources/js/components/git-sources/CreateGitSourceDialog.vue:60) a debounce és a blur külön kérést indít. A loader elrejtése az ugrálást megoldaná, a felesleges kéréseket meghagyná.

Így alakítanám:

- Gépeléskor és blur esetén nincs kérés.
- Beküldés közben loader csak a gombban, dupla beküldés tiltva.
- Hibánál üzenet az input alatt, fókusz vissza az inputra.

A backend mentéskor már minden szükséges ellenőrzést elvégez; a GitHub-fiók létezését most is csak ekkor vizsgálja. Backendmódosítás nem kell.

Hátrány: a formátumhibát később jelzi. Ennél az egymezős űrlapnál ez szerintem vállalható.

## User – 2026-10-02T10:37:09.490Z

Rendben. Implementáld a módosítást.


## Final report – 2026-10-02T10:38:40.444Z

Ris, kész. A [New Source modal](C:/Users/Ris/Documents/GitHub/sportmate-interview/resources/js/components/git-sources/CreateGitSourceDialog.vue) csak beküldéskor validál.

- Debounce, blur-kérés és mező alatti loader eltávolítva.
- Mentési loader, dupla beküldés tiltása és hibakezelés megmaradt.
- Gépelés törli a korábbi mezőhibát.

Ellenőrzés sikeres: lint, formázás, TypeScript és 36 backendteszt.

