# Driftsstatus

Kildekode: https://github.com/magnehg/statuspage

En enkel statusside som samler driftsstatus fra flere leverandører: AIDN, Norsk helsenett, Stø (BankID), Altinn, Digdir,
Skatteetaten, KS Fiks, Azure (Norge øst og vest) og Sikt, hentet fra leverandørenes egne statussider. Du velger selv hvilke
som vises, og kan legge til egne (alle sider laget med Atlassian Statuspage). PHP 8, ingen database og ingen biblioteker: dataene ligger som JSON-filer i `data/`.

## Kildene
| Kilde | Hentes fra |
|---|---|
| AIDN, NHN, Digdir, Skatteetaten, KS Fiks | Statuspage-API: `summary.json` (og `incidents.json` + `scheduled-maintenances.json` for historikk) |
| Stø | `status.stoe.no/api/v1/status` (udokumentert, brukes av siden selv) |
| Altinn | Driftsmeldingene ligger som data i HTML-en (Astro) |
| Azure | RSS (hendelser) + HTML-siden for regionene (7 MB, hentes hver 6. time, hvert 15. min når RSS har en hendelse) |
| Sikt | RSS: `sikt.no/driftsmeldinger/rss.xml` («Feilen er rettet» i teksten = løst) |

## Katalogen
`lib/katalog.json` har 41 norske og nordiske statussider laget med Atlassian Statuspage, i tillegg til de innebygde:
offentlig sektor (Brønnøysundregistrene, DFØ, Husbanken, MET, Tolletaten …), helse (FHI), bank og betaling (Vipps MobilePay,
Signicat …), programvare og IT (Visma, Tripletex, 24SevenOffice, Sticos, Simployer, Kahoot!, Spond, Cognite, Sanity, Pexip …),
post og logistikk (Digipost, Bring API, Entur, AutoStore …), energi (Tibber, Easee), media (NTB) og annet.
De ble funnet ved å sjekke `status.<domene>`, `driftsstatus.<domene>` og `<navn>.statuspage.io` for virksomhetene med
nettadresse i Enhetsregisteret (over 100 ansatte og offentlige virksomheter) og en liste kjente selskaper, og bare sider som
er i bruk er tatt med (ikke test- og eksempelsider). `php verktoy/sjekk-katalog.php` sjekker at de fortsatt svarer.
Mangler en side, kan den legges til i katalogen eller som egen kilde (`egne_kilder`).

## Henting
- **Cron hver time**: `cron.php?nokkel=…` henter alt, også historikken, og rydder opp (hendelser eldre enn 400 dager).
- **Når noen har siden åpen**: `api.php?a=status` henter på nytt når dataene er eldre enn 5 minutter (`maks_alder`).
  En fillås (`flock` på `data/synk.lock`) gjør at bare én forespørsel henter; alle andre får de lagrede dataene med en gang.
  Nettleseren spør igjen når dataene blir 5 minutter gamle (+ 2–22 s tilfeldig spredning), og ikke når fanen er skjult.
- Betingede forespørsler (ETag / Last-Modified) gjør at uendrede kilder svarer 304 uten innhold.

## Nytt og endret
Nye hendelser i «Pågår nå» (og hendelser som blir verre) får merket NY og blinker, og et kort som har byttet status får rammen
i den nye fargen og «Endret/Bedre · Fra … kl. …». Det står uthevet i 15 minutter fra det dukket opp på siden, eller til du klikker på det eller trykker
«Merk alt som sett». Det som skjedde mens siden var lukket, blir uthevet i 15 minutter når du åpner den. Antallet står i fanetittelen,
f.eks. «(2) Driftsstatus», og skjermlesere får det opplest (`#kunngjoring`). Med «reduser bevegelse» blinker ingenting.

## Tilpasning
Det meste endres på **`innstillinger.php`** (lenken «Innstillinger» nederst på siden): tjenestene, navnet, linjen over
overskriften, standardspråket, flaggknappen og `melding_dager`. Lagring krever nøkkelen fra installeringen. Endringene lagres i
`data/innstillinger.json` og går foran `cf/config.php`. Resten (logo, egne kilder, User-Agent) står i `cf/config.php`;
se `cf/config.eksempel.php`:

| Innstilling | Hva |
|---|---|
| `navn`, `undertittel` | Navnet på siden og linjen over overskriften (standard: vertsnavnet) |
| `logo`, `ikon` | Logo (76×76) og ikon i fanen, relativt til siden eller https. Standard: `assets/logo.svg` (pulsen). Innebygde alternativer: `assets/logoer/signal.svg`, `assets/logoer/radar.svg`. Egne filer kan ligge i `eget/` (ikke i git) |
| `kilder` | Hvilke tjenester som vises, i rekkefølge: `['nhn', 'digdir', 'visma']`. Velges under installeringen. Utelatt = de innebygde |
| `egne_kilder` | Egne Statuspage-sider: `'github' => ['navn' => 'GitHub', 'type' => 'statuspage', 'side' => 'https://www.githubstatus.com/']` |
| `sprak` | Grensesnittet på `nb` (standard) eller `en` |
| `sprak_valg` | Flaggknappen som lar den som besøker siden bytte språk (standard på) |
| `kildekode` | Lenken til kildekoden nederst på siden (standard: dette repoet) |
| `melding_dager` | Hvor mange dager en alvorlig driftsmelding (Altinn) regnes som avvik, standard 7. Etterpå er kortet grønt og viser datoen |
| `brukeragent` | User-Agent mot kildene; ta gjerne med adressen til siden din |

**Språk:** grensesnittet finnes på norsk bokmål og engelsk (`assets/tekster.json`, samme fil brukes av PHP og nettleseren).
Selve meldingene fra leverandørene oversettes ikke: titler og tekster står som leverandørene skriver dem, oftest på norsk,
og er merket `lang="nb"` når grensesnittet er engelsk. Nytt språk: legg til en blokk i `tekster.json` med samme nøkler.

## Installering
1. Legg filene på et (under)domene. PHP 8.1 eller nyere med curl, json, mbstring og simplexml.
2. Åpne `https://<din-side>/` – du sendes til `install.php` – og trykk **Installer**. (Eller kjør `php install.php`.)
   Installeringen sjekker PHP-versjonen og utvidelsene, at `cf/` og `data/` er skrivbare og at fillåsing virker,
   at serveren når kildene over HTTPS og at `cf/`, `lib/` og `data/` er sperret for nettlesere.
   Så lager den en tilfeldig nøkkel, skriver `cf/config.php` (bare hashen av nøkkelen), lager `data/` og henter alt første gang.
3. Skriv ned nøkkelen (den vises bare én gang) og sett opp cron hver time, f.eks. 7 minutter over:
   `7 * * * * curl -fsS "https://<din-side>/cron.php?nokkel=<nøkkelen>"` – eller `php /sti/til/cron.php` uten nøkkel.

Når `cf/config.php` finnes, gjør `install.php` ingenting. Ny nøkkel: slett `cf/config.php` og installer på nytt.
Ved oppdatering av koden: kjør `oppdater.php?nokkel=<nøkkelen>` (eller `php oppdater.php`).

`cf/config.php` skal aldri inn i git eller zip.

## Filene
- `innstillinger.php` – endre tjenester, navn, språk m.m. (krever nøkkelen).
- `install.php` – automatisk installering (sjekker, valg av tjenester, nøkkel, `cf/config.php`, `data/`, første henting).
- `lib/katalog.json`, `verktoy/sjekk-katalog.php` – katalogen over statussider og kontrollen av den.
- `index.php`, `assets/` – siden (fanene Status nå og Tidslinje, fire fargetemaer: Mørk, Varm, Blågrønn; ikonknapper).
- `api.php` – `a=status` og `a=tidslinje` (90 dager tilbake, 14 frem).
- `lib/kilder.php` (kildene, nivåene), `lib/tolk.php` (én tolker per type), `lib/lagre.php`, `lib/synk.php` (låsen), `lib/http.php`.
- `lib/lager.php` – filene: `data/kilder.json`, `data/hendelser/<kilde>.json`, `data/statuslogg/<kilde>.json`, `data/synk.json`
  og `data/http/` (ETag og forrige svar). Filene skrives til en midlertidig fil og flyttes på plass, så de aldri leses halvskrevne.
  Alle tider er UTC i filene og vises i norsk tid. `data/` er sperret med `.htaccess` og skal ikke i git eller zip.

## Personvern
Ingen informasjonskapsler. I nettleseren lagres bare temavalget (`st_tema`, localStorage), språkvalget (`st_sprak`, localStorage), hva du har sett (`st_sett`, localStorage: id-ene og nivåene på hendelsene og statusen per leverandør) og valgt fane (`st_fane`, sessionStorage).
Ingen skrifter eller skript fra andre nettsteder.

## Lisens
MIT, se `LICENSE`. Leverandørenes navn og data tilhører leverandørene; siden bare henter det de publiserer offentlig.
