# Trin for trin (trinkort)

Jesper kalder den **trinkort**, ligesom valgkort. Beder han om trinkort, så brug den her.

En kortbunke, som guider Jesper gennem en opgave i fx Google Search Console, Play Console eller Simply. Hvert kort har ét trin med stor tekst. Han swiper eller bladrer, krydser trin af og kan sende en besked til Claude fra hvert kort.

Siden ligger fast på **https://claude.ai/artifact/Vv4xmeeLDoqvnjLh5jk8BT** og er privat for Jesper. Brug altid den adresse, så han har ét sted at gå hen. Kilden er `trin-for-trin.html` her i mappen. Mappen `design/` udgives ikke på qdigital.dk.

## Sådan bruger en session den

Alle Claude sessioner på Jespers konto kan bruge siden med værktøjet `ArtifactData` og adressen ovenfor. Siden skal ikke udgives igen for en ny vejledning.

1. **Tjek, at den er ledig.** Læs `guides/current` med `action: "get"`. Findes den, er en anden vejledning i gang. Spørg Jesper, før den erstattes.
2. **Læg vejledningen ind.** `action: "set"` på `guides/current` med felterne herunder. Giv `if_version`, hvis dokumentet findes.
3. **Send Jesper linket** og sig, hvad vejledningen hedder.
4. **Følg med.** `progress/<id>` viser, hvor langt han er: `index` (0 er første kort), `step` som tekst, `checked` med de afkrydsede trin og `updated`.
5. **Læs beskeder.** Skriver Jesper "se besked", så læs `messages` med `action: "list"`. Hver besked har `guide`, `step` (-1 er done knappen), `text` og `sent`. Lykkes det, kommer beskeden også som en kommentar, der vækker den session, der udgav siden.
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

Ret `trin-for-trin.html` her, og udgiv den til samme adresse med `Artifact` og `url`. Gem ændringen her i repoet bagefter. Siden har `db` med reglen read `view` og write `owner` og `comments`.
