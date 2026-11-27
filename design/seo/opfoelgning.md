# SEO opfølgning for qdigital.dk

Mappen `design/` udgives ikke på qdigital.dk. Sessionen "qdigital.dk hjemmeside, udgiver til Simply" fører filen.

Budget: 0 kr. i ekstra drift. SEO Autopilot (qvisty/seo-autopilot) er et andet spor og bygges ikke her.

## Hvad der tjekkes, og hvordan

| Emne | Hvordan | Selv eller Jesper |
|---|---|---|
| Safe Browsing | `transparencyreport.google.com/transparencyreport/api/v3/safebrowsing/status?site=qdigital.dk`. Andet tal i svaret: 1 er rent, 3 er "nogle sider er usikre". Femte felt true betyder social engineering. | Selv |
| Search Console mails | Gmail: `from:sc-noreply@google.com qdigital` | Selv |
| SEO analyse og udgivelse | GitHub Actions, workflows "SEO audit" og "Udgiv til Simply" | Selv |
| Sitemap, canonical, beskrivelser, links | `python3 tests/seo_audit.py` | Selv |
| Webserverens log | Simply MCP, `get-webserver-requests` (gemmer kun cirka 7 dage) | Selv |
| Indeksering, sikkerhedsrapport, effektivitet | Search Console. Ingen adgang herfra | Jesper sender skærmbilleder eller tal |

Gæt aldrig på tal fra Search Console. Spørg efter et skærmbillede.

## Udgangspunkt 28. november 2026

| Nøgletal | Udgangspunkt |
|---|---:|
| Eksterne backlinks i Search Console | 0 |
| Interne links | 7 |
| Indekserede sider | 6 |
| Organiske klik i den viste periode | 3 |
| Eksponeringer for viste søgeord | 52 |
| Fund i SEO analysen | 0 |
| Adresser i sitemap | 10 |

Sammenlign altid samme længde periode, fx 28 dage mod de forrige 28 dage.

## Afventer hos Google

1. Validering af canonical rettelsen, startet 27. november.
2. Indeksering af ordstriben/, ordstriben/privatliv/, ordstriben/test/ og truckspotting/media/. Anmod ikke igen, før der er gået mindst to uger.
3. Gamle www adresser, som skal erstattes af qdigital.dk.

## Sikkerhedsmarkeringen "Vildledende sider"

Holdes adskilt fra den almindelige SEO.

Fundet 28. november 2026:

1. Safe Browsing siger status 3 og social engineering for qdigital.dk og alle stier, sidst opdateret 5. november 2026 kl. 05.29 UTC. www.qdigital.dk har status 1.
2. Markeringen er ældre end testersiden, Truckspotting forslag og behandl siden. Den 4. november var siden kun forsiden, Crowns og api/feedback.php.
3. Der er ingen mail fra Google om sikkerhedsproblemet i Gmail. Kun mails om indeksering.
4. Wayback Machine har intet om qdigital.dk før 25. oktober 2026. Intet tyder på en tidligere ejer.
5. DNS har et wildcard: `*.qdigital.dk` peger på webhotellet, som viser forsiden på enhver underadresse med eget certifikat, fx abc123.qdigital.dk. Det kan misbruges i links som "login-bank.qdigital.dk", som så viser en ægte side. Simply har ingen underdomæner sat op.
6. `*.srv.qdigital.dk` peger på en Tailscale adresse (hjemmeserveren). Den kan ikke nås udefra.
7. Simplys firewall blokerede 1662 af 3321 forespørgsler 21. til 28. november. Det er mest scannere, der leder efter .env filer. Googlebot får 200.
8. Simply sag 862829 er åben og har ikke fundet noget.

Næste skridt:

1. Jesper sender et skærmbillede af Search Console, Sikkerhed og manuelle handlinger, Sikkerhedsproblemer, med detaljerne foldet ud, også eksempler på adresser og datoen for første registrering.
2. Overvej at fjerne wildcard posten `*` i DNS. Kun Jesper må ændre DNS. Behold `www` og `*.srv`.
3. Når årsagen er fundet og rettet, eller alt er tjekket uden fund, skrives anmodningen om gennemgang i Search Console. Teksten skal sige, hvad der er tjekket og rettet.

## Plan

Fast tjek hver tirsdag formiddag (scheduled task i sessionen). Første gang 1. december 2026. Ekstra fokus omkring 12. december (validering) og 29. december (første samlede måling).

Rapport ved hvert tjek:

1. Samlet status, grøn, gul eller rød.
2. Ændringer siden sidst.
3. Nye problemer.
4. Løste problemer.
5. Nøgletal mod udgangspunktet.
6. Anbefalinger i prioriteret rækkefølge.
7. Næste konkrete handling. Kan sessionen selv gøre den, så gør den det.

## Log

| Dato | Status | Kort |
|---|---|---|
| 28. november 2026 | 🔴 sikkerhed, 🟢 teknisk SEO | Plan sat op. Safe Browsing status 3 fra 5. november. Wildcard DNS fundet. Venter på skærmbillede af sikkerhedsrapporten. |
