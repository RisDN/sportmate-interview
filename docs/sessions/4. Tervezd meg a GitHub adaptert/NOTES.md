itt már rárértem a backendre.

Fontosnak tartottam azt, hogy úgy tervezzem meg a backend részét, hogy ne kifejezetten GitHub specifikus legyen még ha a feladat ezt várta volna el. Szeretem tovább gondolni a problémákat, és felkészíteni a rendszereket bármilyen jövendőbeli bővítésre, ezért a backend részét úgy terveztem meg, hogy bármilyen git provider-t tudjon kezelni. Kitaláltam az alapelvet, az interface-eket majd kikértem a véleményét az ötleteimről, hogy fainnak látja-e őket.

Emiatt gyakorlatilag a backend egyáltalán nem github specifikus, hanem minden univerzális interface-eken és classokon keresztül működik. A tényleges github részt a GithubProdiver.php adja, ami implementálja a GitProvider interface-t. A gitprovider interface adja meg azt, hogy egy gitprovider-nek milyen lehetőségeket kell adnia ahhoz, hogy működjön.

Itt már konkrét példa kódokat is belefogalmaztam a promptomba, hogy mégjobban lássa a célomat.

Később arra gondoltam, hogy majd csinálok egy GitLab implementációt is csak a demonstráció kedvéért, de arra jutottam, hogy a tervezésemből át jön a lényeg, másrészt pedig kevés időm maradt.
(azért egy git source felvételekor ott hagytam a provider selection-t, hogy lássátok, hogy hogyan képzeltem volna el ezt ui szinten)

miután elmondta a véleményét az ötleteimről, rámutatott olyan dolgokra, amikre én nem gondoltam (vagy nem tudtam). Ezeket átbeszélve pedig megcsinálta az implementációt, és a teszteket is.
