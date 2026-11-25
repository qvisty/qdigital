# Q Digital

Firmaets hjemmeside på https://qdigital.dk/ med engelsk version på /en/.

Siden er ren HTML og CSS uden byggetrin. Den udgives kun til Simply. GitHub Pages er slået fra.

## Udgivelse til Simply

Hver gang main ændres, sender `.github/workflows/deploy.yml` filerne til Simply.com over FTPS, i mappen `public_html/`.

Det kræver tre hemmeligheder under Settings, Secrets and variables, Actions:

1. `FTP_SERVER`
2. `FTP_USERNAME`
3. `FTP_PASSWORD`

Mangler de, springes udgivelsen over uden fejl.

## Logo og design

Mappen `design/` bliver ikke sendt til Simply og er ikke linket fra siden.

1. `design/logo/q-digital.svg` er det valgte logo til lys baggrund.
2. `design/logo/q-digital-moerk-baggrund.svg` er til mørk baggrund.
3. `design/logo/q-digital-hvid.svg` er helt hvidt, til farvede flader.
4. `design/logo/q-digital-animeret.svg` er logoet, hvor Q popper frem og resten glider ind.
5. `design/logo/ikon.svg` er ikonet, som også bruges som favicon.
6. `design/logoforslag.html` viser alle de forslag, der blev overvejet, også animeret.

Logofilerne er tegnet som stier med skriften Inter, så de ser ens ud overalt uden skrifttyper.

## Regler for indhold

Læg aldrig CPR, fuld adresse, telefonnummer, kontonumre, kortnumre eller adgangskoder på siden eller i repoet.

## Truckspotting forslag

`truckspotting/forslag/index.php` tager imod forslag til Truckspottings katalog fra appen. Den er kopieret uændret fra repoet qvisty/Truckspotting, mappen `server/`.

1. Forslagene gemmes i `truckspotting-data/forslag.csv` ved siden af `public_html`, så de ikke kan ses fra nettet.
2. Udgivelsen skriver `truckspotting-data/config.php` med postkassen noreply@qdigital.dk og hemmeligheden `FEEDBACK_SMTP_PASSWORD`. Så sendes hvert forslag som mail til kontakt@qdigital.dk.
3. `truckspotting/forslag/behandl/` er siden, hvor forslag markeres som tilføjet eller afvist. Adgangskoden ligger som hash i hemmeligheden `TRUCKSPOTTING_ADMIN_HASH`, og udgivelsen skriver den i `config.php` som `admin_hash`. Siden viser selv hashen første gang, før hemmeligheden findes. Ret ikke `config.php` på webhotellet i hånden, for udgivelsen kan skrive den igen.
4. Siden har også en JSON adgang, som kræver en nøgle i headeren `X-Api-Key`. Kun hashen af nøglen ligger i hemmeligheden `TRUCKSPOTTING_API_HASH`, og udgivelsen skriver den i `config.php` som `api_hash`. Nøglen laves på siden under "Nøgle til Claude".
5. `truckspotting/venner/index.php` er modtageren til vennekoder i Truckspotting, kopieret uændret fra qvisty/Truckspotting `server/venner/`. Udgivelsen opretter `truckspotting-data/venner/` uden for `public_html`. Scriptet laver selv `salt.txt` dér første gang.

## Ordstriben log

`ordstriben/log/index.php` tager imod en teknisk log fra Ordstribens testversioner, når brugeren selv har slået det til i appen. Loggen har kun pakkenavne og tekniske udfald, aldrig tekst fra felter eller lyd.

1. `POST` med JSON `{"install","version","lines"}` gemmer i `ordstriben-data/log/` uden for `public_html`, én fil pr. install id. Højst 64 KB, 500 linjer og 300 tegn pr. linje. Linjerne skal have formen `ÅÅÅÅ-MM-DD tt:mm:ss|EVENT|...`. Svar 204.
2. `DELETE ?install=<uuid>` sletter alt for det install id. Svar 204.
3. `GET ?since=<ISO tid>` kræver headeren `X-Api-Key`. Kun sha256 af nøglen ligger i hemmeligheden `ORDSTRIBEN_LOG_API_HASH`, og udgivelsen skriver den i `ordstriben-data/config.php`.
4. Data ældre end 30 dage slettes. Grænser pr. afsender tælles med en saltet hash, og IP adresser gemmes aldrig. Findes filen `ordstriben-data/log/lukket`, svarer alle kald 503.
5. `tests/ordstriben-log.sh` tester det hele lokalt med PHP. Kør den før hver ændring.

## SEO

qdigital.dk har `robots.txt`, `sitemap.xml`, `llms.txt`, `og-image.png` (1200 x 630), `logo-512.png`, `404.html` og `.htaccess`, som sender www videre til adressen uden www og `index.html` til mappen. Tilføjes en ny offentlig side, så skal den også i `sitemap.xml` og gerne i `llms.txt`. Lukkede adresser som modtagere til apps står under Disallow i `robots.txt`. Google Search Console er bekræftet med filen `google4100578d9f47f678.html`, som ikke må slettes.

### Tjekliste, når et andet produkt går live

Noteret 25. november 2026. Ret disse ting, når produktet er færdigt og udgives:

1. **censorfordeling.dk**: canonical og `og:image` peger på `staging.censorfordeling.dk`. De skal pege på `censorfordeling.dk`. Der mangler `sitemap.xml` og strukturerede data, og beskrivelsen er kort.
2. **mitcv.com**: siden sender videre til `www.mitcv.com`, men canonical og sitemap bruger `mitcv.com` uden www. Vælg én adresse alle steder. `og:image` mangler, og `/llms.txt` giver en almindelig side med `lang="llms.txt"` i stedet for en 404 eller en rigtig fil.
3. **Rattid**: der mangler canonical og `sitemap.xml`, og `og:image` er et lånt pegasus billede fra Wikimedia. Vælg den endelige adresse, fx rattid.mitcv.com eller et eget domæne.
4. For alle: tilføj domænet i Google Search Console og Bing Webmaster Tools, send sitemap ind, og tillad AI crawlere i `robots.txt` på de offentlige sider.

## Trin for trin

Trinkort er kortbunken til vejledninger trin for trin. Hver session har sit eget. Denne sessions ligger på https://claude.ai/artifact/Vv4xmeeLDoqvnjLh5jk8BT. Kilde og vejledning til andre sessioner står i `design/trin-for-trin/README.md`. Jesper vil gerne have vejledninger i den form.

## Ordstriben statistik

`ordstriben/stats/index.php` tager imod brugsstatistik fra Ordstriben, når brugeren selv har sagt ja. Kun tal: ingen tekst, ingen lyd og ingen navne på apps.

1. `POST` med JSON `{"user","version","events"}` gemmer i `ordstriben-data/stats/` uden for `public_html`, én fil pr. bruger id. Højst 64 KB og 500 hændelser pr. kald. Hver hændelse har præcis felterne `t`, `kind`, `lang`, `engine`, `ok`, `error`, `audio_ms`, `wait_ms`, `words`, `chars`, `mode` og `fallback`. Den samme hændelse tæller kun én gang. Svar 204.
2. `DELETE ?user=<uuid>` sletter alt for det bruger id. Svar 204.
3. `GET ?summary` giver de samlede tal til dashboardet `ordstriben/tal/`. Et tal med færre end 5 brugere bag gives som null. Tallene regnes højst hvert 5. minut.
4. `GET` med headeren `X-Api-Key` giver de rå data. Det er samme nøgle som loggen, `ORDSTRIBEN_LOG_API_HASH`.
5. Data ældre end 13 måneder slettes. IP adresser gemmes aldrig. Findes filen `ordstriben-data/stats/lukket`, svarer alle kald 503.
6. `tests/ordstriben-stats.sh` tester det hele lokalt med PHP.
