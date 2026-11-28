# Anmodning om gennemgang i Search Console

Sendes under Sikkerhed og manuelle handlinger, Sikkerhedsproblemer, knappen ANMOD OM GENNEMGANG. Skrevet 28. november 2026.

```
qdigital.dk is the website of Q Digital, a sole proprietorship in Denmark (CVR 46418840). It presents our own Android apps and their privacy policies. The site is static HTML with a few small PHP endpoints that receive anonymous feedback and statistics from our own apps. It has no login forms for visitors, no downloads other than links to Google Play, and no third party scripts or ads.

Search Console lists "Deceptive pages" with no example URLs, so we reviewed the whole domain:

1. DNS had a wildcard record (*.qdigital.dk) that served our front page with a valid certificate on any made up subdomain. No subdomain was in use. We removed the wildcard on 28 November 2026, so only qdigital.dk and www.qdigital.dk now resolve to the website.
2. All content is deployed automatically from our Git repository, and we reviewed the pages and scripts in it.
3. The web hosting provider found no infected files, unknown subdomains or suspicious redirects (support ticket 862829).
4. The web server logs for the last week show no unusual pages being served. Blocked traffic is vulnerability scanners looking for files we do not have.

We believe the wildcard subdomains were the most likely reason for the warning. Please review the site again.
```
