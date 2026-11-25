Jeg vil gerne have, at du laver dit eget **trinkort**. Det er mit navn for en kortbunke med vejledninger trin for trin, ligesom "valgkort" er mit navn for AskUserQuestion. Når jeg beder om trinkort, skal du bruge din egen kortbunke, som beskrevet her. Gem denne vejledning, fx i din CLAUDE.md, så du kan finde den igen.

## Hvad et trinkort er

1. En side med store kort, som jeg swiper igennem. Hvert kort har ét trin og én handling, fx "Tryk på Sitemaps" eller "Skriv denne adresse".
2. Den kører som et artefakt på claude.ai, ikke som AskUserQuestion.
3. Du skifter vejledningen ud uden at udgive siden igen. Du lægger den bare i sidens database.
4. Du kan se, hvor langt jeg er, så jeg kan nøjes med at skrive "det virker ikke", og så ved du, hvilket kort jeg står på.
5. Hvert kort har feltet "Sig noget til Claude" med hurtige knapper og fri tekst. Beskeden vækker den session, der har udgivet siden. Derfor har hver session sit eget trinkort.
6. Sidste kort har knappen "Send done til Claude".

## Sådan laver du det første gang

1. Hent kilden:
   `curl -sO https://raw.githubusercontent.com/qvisty/qdigital/main/design/trin-for-trin/trin-for-trin.html`
   Gem den et sted, der bliver liggende, fx i dit repo under `design/trinkort/`, ikke kun i scratchpad.
2. Ret `<title>` øverst i filen til `Trinkort <projekt>`, fx `Trinkort Truckspotting`.
3. Udgiv filen med værktøjet `Artifact`:
   * `icon`: `"steps"`
   * `description`: en sætning om, at det er dit projekts trinkort
   * `capabilities`: `{"db": {"rules": [{"path": "", "read": "view", "write": "owner"}]}, "comments": {}}`
   `comments` er det, der gør, at mine beskeder fra kortene vækker dig.
4. Skriv adressen, du får, i din CLAUDE.md eller README sammen med navnet trinkort, så du altid bruger den samme.
5. Send adressen til sessionen "qdigital.dk hjemmeside, udgiver til Simply", så den kommer på listen over kendte trinkort i qvisty/qdigital `design/trin-for-trin/README.md`.

## Sådan lægger du en vejledning ind

Brug værktøjet `ArtifactData` med adressen på dit trinkort. Hent det med ToolSearch, hvis du ikke har det.

1. **Tjek, at det er ledigt.** `action: "get"`, `collection: "guides"`, `doc_id: "current"`. Findes dokumentet, er en vejledning stadig i gang. Spørg mig, før du erstatter den.
2. **Læg vejledningen ind.** `action: "set"` på `guides/current`. Giv `if_version`, hvis dokumentet findes.
3. **Send mig linket** og navnet på vejledningen.
4. **Følg med.** `action: "get"` på `progress/<id>` viser `index` (0 er første kort), `step` som tekst, `checked` med de afkrydsede trin og `updated`.
5. **Læs beskeder.** Mine beskeder kommer som kommentarer, der vækker dig. Skriver jeg "se besked", så læs også `messages` med `action: "list"`. Hver besked har `guide`, `step` (-1 er done knappen), `text` og `sent`.
6. **Ryd op.** Når jeg er færdig, så slet `guides/current` med `action: "delete"`, så kortbunken står tom til næste gang.

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
      "text": "Tryk på [[Luk]], hvis beskeden om ugyldig adresse stadig står der. Slet det, der står i feltet [[Tilføj et nyt sitemap]], og skriv hele adressen:",
      "copy": "https://qdigital.dk/sitemap.xml",
      "link": { "href": "https://search.google.com/search-console", "label": "Åbn Search Console" },
      "note": "qdigital.dk er oprettet som et domæne i Search Console. Derfor skal hele adressen med, ikke kun sitemap.xml."
    }
  ]
}
```

1. `id` er bogstaver, tal, bindestreg og understreg. Et nyt id giver nye afkrydsninger.
2. `part`, `parts` og `next` er kun med, når opgaven har flere dele. Så står der "Del 1 af 2" øverst, og sidste kort siger, hvad næste del hedder. Når jeg sender done, lægger du næste del ind på samme måde.
3. `[[Navn]]` vises som et knapnavn i en grøn boks.
4. `copy` giver tekst med en kopiér knap. `link` skal starte med `https://`. `note` er en lille ekstra forklaring.

## Sådan skriver du kortene

1. Ét kort er én handling. Hellere ti korte kort end tre lange.
2. Brug skærmens egne ord i `[[ ]]`, fx `[[Sitemaps]]` og `[[SEND]]`. Gæt aldrig på navne. Er du i tvivl, så skriv i `note`, at jeg skal sende et skærmbillede, hvis det ser anderledes ud.
3. Skriv på dansk, kort og direkte. Ingen tankestreger, og helst ingen semikolon.
4. Første kort åbner den rigtige side med et `link`.
5. Sidste kort tjekker, at det lykkedes, fx at en status står som Gennemført.

## Hvis siden skal ændres

Kilden ligger i qvisty/qdigital `design/trin-for-trin/trin-for-trin.html`. Forbedringer laves dér, så alle sessioner kan hente dem. Udgiv derefter din egen kopi igen til din egen adresse med `Artifact` og `url`.
