Ebben a beszélgetésben terveztem meg a repository-k adatmodelljét

gyorsan beleütköztem abba a problémába, hogy a github api personal access token nélkül nagyon gyorsan ratelimitel, ezért úgy csináltam meg, hogy opcionálisan elhelyezhető egy GITHUB_PAT env változó. Ha ez jelen van, akkor minden api felé irányuló kéréshez csatolja a headerben a tokent, így növelve a ratelimitet.
