# Trin for trin (trinkort)

Jesper kalder den **trinkort**, ligesom valgkort. Beder han om trinkort, så brug sessionens eget trinkort, eller lav et efter vejledningen herunder.

En kortbunke, som guider Jesper gennem en opgave i fx Google Search Console, Play Console eller Simply. Hvert kort har ét trin med stor tekst. Han swiper eller bladrer, krydser trin af og kan sende en besked til Claude fra hvert kort.

Skal en anden session have sit eget trinkort, så kopiér hele `KOPIER-TIL-SESSION.md` her i mappen ind i den session.

Hver session har sit eget trinkort. Så går Jespers beskeder fra kortene direkte til den session, der har lavet vejledningen, og sessionerne kan ikke overskrive hinandens vejledninger. Kilden er `trin-for-trin.html` her i mappen. Mappen `design/` udgives ikke på qdigital.dk.

Kendte trinkort:

| Session | Adresse |
|---|---|
| qdigital.dk hjemmeside, udgiver til Simply | https://claude.ai/artifact/Vv4xmeeLDoqvnjLh5jk8BT |
| TS Projekt kvalificering og udvikling (Truckspotting) | https://claude.ai/artifact/BZdkK499mQKXtWkRuLCxuU |
| OS Svensk talemodel til Hugging Face (Ordstriben) | https://claude.ai/artifact/55f7iRe1yC8QiVkT5fCzN4 |
| OS Status (Ordstriben) | https://claude.ai/artifact/XFcf1bC5BpQEu55xLLQCae |

## Sådan får en session sit eget trinkort

Første gang:

1. Hent `design/trin-for-trin/trin-for-trin.html` fra qvisty/qdigital og gem den i sessionens eget repo eller arbejdsmappe.
2. Ret `<title>` til `Trinkort <projekt>`, fx `Trinkort Truckspotting`.
3. Udgiv den med værktøjet `Artifact`, `icon: "steps"` og `capabilities: {"db": {"rules": [{"path": "", "read": "view", "write": "owner"}]}, "comments": {}}`. Det er `comments`, der gør, at Jespers beskeder vækker sessionen.
4. Skriv adressen i sessionens egen CLAUDE.md eller README, og tilføj den i tabellen ovenfor, så Jesper altid får det samme link fra den session.

Siden skal ikke udgives igen for en ny vejledning. Alt andet sker med værktøjet `ArtifactData` og sessionens egen adresse:

1. **Tjek, at den er ledig.** Læs `guides/current` med `action: "get"`. Findes den, er en vejledning stadig i gang. Spørg Jesper, før den erstattes.
2. **Læg vejledningen ind.** `action: "set"` på `guides/current` med felterne herunder. Giv `if_version`, hvis dokumentet findes.
3. **Send Jesper linket** og sig, hvad vejledningen hedder.
4. **Følg med.** `progress/<id>` viser, hvor langt han er: `index` (0 er første kort), `step` som tekst, `checked` med de afkrydsede trin og `updated`.
5. **Læs beskeder.** Beskeder fra kortene kommer som kommentarer, der vækker sessionen. Skriver Jesper "se besked", så læs også `messages` med `action: "list"`. Hver besked har `guide`, `step` (-1 er done knappen), `text` og `sent`.
6. **Ryd op.** Når han er færdig, så slet `guides/current`, så kortbunken står tom til næste gang.

## Format for guides/current

```json
{
  "id": "search-console-sitemap",
  "name": "Sitemap i Google Search Console",
  "part": 1,
  "parts": 2,
  "next": "Bing Webmaster",
  "steps": [
    {
      "title": "Skriv hele adressen",
      "text": "I feltet [[Tilføj et nyt sitemap]] skriver du:",
      "copy": "https://qdigital.dk/sitemap.xml",
      "link": { "href": "https://search.google.com/search-console", "label": "Åbn Search Console" },
      "note": "Lille ekstra forklaring under trinnet."
    }
  ]
}
```

1. `id` er bogstaver, tal, bindestreg og understreg. Et nyt id giver nye afkrydsninger.
2. `part`, `parts` og `next` er kun med, når opgaven har flere dele. Så står der "Del 1 af 2" øverst, og sidste kort siger, hvad næste del hedder.
3. `[[Navn]]` vises som et knapnavn i en grøn boks. Brug skærmens egne ord og gæt aldrig. Er man i tvivl, så skriv i `note`, at Jesper skal sende et skærmbillede.
4. `copy` giver tekst med en kopiér knap. `link` skal starte med `https://`.
5. Ét trin er én handling. Skriv kort, på dansk, uden tankestreger og helst uden semikolon.

## Hvis siden skal ændres

Ret `trin-for-trin.html` her. Hver session udgiver selv den nye version til sin egen adresse med `Artifact` og `url`, når den har brug for det. Siden har `db` med reglen read `view` og write `owner` og `comments`.
