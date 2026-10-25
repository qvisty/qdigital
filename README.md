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

## Regler for indhold

Læg aldrig CPR, fuld adresse, telefonnummer, kontonumre, kortnumre eller adgangskoder på siden eller i repoet.
