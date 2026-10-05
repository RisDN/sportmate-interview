# Ai használat doksi

# Modellek

- GPT 6.1 Sol Ultra 1.5x speed
- GPT 6 Astra Ultra 1.5x speed

# Mcp-k

- Deepwiki https://docs.devin.ai/work-with-devin/deepwiki-mcp -> arra van, hogy 3rd party publikus repositorykból tudjon kérdezni, így nem képzel be nem létező methodokat, és a source of truth tehát a kód alapján a valós repositorykban található kód alapján válaszolja meg neki egy másik agent a kérdését.

# Skillek:

- caveman https://www.skills.sh/juliusbrussee/caveman/caveman
- shadcn https://www.skills.sh/shadcn-ui/ui/shadcn
- .agents/skills mappában lévők, amik jöttek a laravellel
- apple-design https://www.skills.sh/emilkowalski/skills/apple-design
- taste-skill https://www.tasteskill.dev/
- "ris skill" -> ez egy saját skill, annyit tesz, hogy minden üzenetét az agentnek "Ris"-el kell kezdeni. Ez azért van, hogy lássam, mikor kezdi elveszteni a kontextust, és kezdi elfelejteni az instrukciókat és szabályokat. (ris én vagyok)

Fontosnak tartottam, hogy az agentek ne tudják pontosan a feladat végcélját, hogy a konkrét technikai megtervezés része az én feladatom maradhasson, hogy ne tudjanak puskázni vagy ilyesmi, ezért az első dolog az volt, hogy az AGENTS.md fájlban írtam egy olyan szabályt, hogy a TASK.md-t (amiben ügyebár az eredeti feladat van leírva) nem olvashatják be, keresésnél pedig filterelniük kell azt a fájlt hogy soha ne jusson be a kontextusukba. Így a projektet az én elképzeléseim szerint bontottam fel, és a konkrét technikai megvalósítás részleteit is én terveztem meg, az agentek pedig csak a tényleges implementációra és az én ötleteim felülvizsgálására fókuszáltak. Adtak egyébként jó, meg rossz ötleteket is egyaránt de ez annak is köszönhető, hogy pontosan nem tudták, hogy mi a végcél.

# Session-ök

Egy open source tool segítségével exportáltam az összes local codex session-ömet, hogy pontosan lássátok, hogy milyen promptokat írtam, mit csinált az agent, és mit hogyan terveztem meg, milyen döntéseket hoztam és miért.
Repo-ja: https://github.com/Ann-Diana/codex-project-chat-exporter

A docs/session mappában van az összes csevegés, időrendben beszámozva. Mindegyikhez írtam egy rövid leírást, hogy pontosan ott mi volt a cél, milyen anomáliák történtek és mi járt a fejemben. Ezeket a NOTES.md fájlokban találjátok. Ahol "Local file not found" van, az azt jelenti, hogy az exporter adatvédelmi okokból nem rakta oda az elérési utat, sem a fájlt.

Kértem mindig, hogy tegyen fel nekem kérdéseket az adott feladattal kapcsolatosan, mielőtt bármit is tervezne/implementálna. Ezt általában mindig így szoktam, így nagyon sok fölösleges kört spóroltam meg. Addig addig kell kérdeznie, amig 100%-ig biztos abban, hogy pontosan mit hogyan akarok.

# Chat Research-ök

research arról, hogy van e olyan open source tool, amivel exportálhatok local codex csevegéseket:
https://chatgpt.com/share/6ac369aa-edf0-83ed-9127-236834104d3f

Research arról, hogy a github apival lehetséges e github app / webhook beállítása nélkül eventekre feliratkozni,
hogy a sync csak tényleges érdemi változás esetén történjen meg és akkor is csak azt frissítenénk ami ténylegesen változott. https://chatgpt.com/share/6abe5889-8f98-83eb-ba93-7fadda000269

research arról, hogy mit érdemes azonositásra használni egy repository esetében:
https://chatgpt.com/share/6abf939f-4364-83eb-a60a-d4109e824f11
