# Endringer

## 0.003 – 25.11.2026
- Katalog med 41 norske og nordiske statussider laget med Atlassian Statuspage (`lib/katalog.json`), gruppert
  (offentlig sektor, helse, bank og betaling, programvare og IT, post og logistikk, energi, media, annet).
  Funnet ved å sjekke `status.<domene>` for virksomheter i Enhetsregisteret og kjente selskaper, og kontrollert.
- Installeringen lar deg velge tjenestene (med søk); de innebygde er forhåndsvalgt. `php install.php --kilder=nhn,visma`.
- `php verktoy/sjekk-katalog.php` kontrollerer at alle sidene i katalogen fortsatt svarer.
- Uten `kilder` i config vises de innebygde (ikke hele katalogen).

## 0.002 – 25.11.2026
- Automatisk installering (`install.php`): sjekker PHP, utvidelser, skrivbare mapper, fillås, utgående HTTPS og sperrede mapper,
  lager nøkkel og `cf/config.php`, lager `data/` og henter alt første gang. Uten `cf/config.php` sendes man dit.
- Tre standardlogoer i `assets/logoer/` (puls er standard).

## 0.001 – 25.11.2026
- Første versjon: status nå og tidslinje, ni kilder, JSON-filer, fillås, cron, tre temaer, norsk og engelsk, egne kilder, MIT.
