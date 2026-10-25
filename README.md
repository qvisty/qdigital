# Q Digital

Firmaets hjemmeside på https://qdigital.dk/ med engelsk version på /en/.

Siden er ren HTML og CSS uden byggetrin. Den udgives også med GitHub Pages på https://qvisty.github.io/qdigital/

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
