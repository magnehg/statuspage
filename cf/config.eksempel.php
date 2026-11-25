<?php
// install.php lager cf/config.php automatisk. Denne filen viser alle innstillingene.
// cf/config.php skal aldri inn i git eller zip.
return [
    // Lag hashen med: php -r 'echo password_hash("din-nøkkel", PASSWORD_DEFAULT), "\n";'
    // Cron kaller da https://<din-side>/cron.php?nokkel=din-nøkkel
    'cron_nokkel_hash' => '',

    // Navnet på siden (fanen i nettleseren og overskriften før datoen vises).
    'navn' => 'Driftsstatus',
    // Linjen over overskriften. Tom = vertsnavnet siden kjører på.
    'undertittel' => '',
    // Logo (vises 76×76, kvadratisk, gjerne gjennomsiktig) og ikon i nettleserfanen.
    // Relativt til siden (f.eks. 'eget/logo.webp') eller en https-adresse. Tom = assets/logo.svg (pulsen).
    // Andre innebygde: 'assets/logoer/signal.svg' og 'assets/logoer/radar.svg'.
    'logo' => '',
    'ikon' => '',
    // User-Agent mot kildene. Ta gjerne med adressen til siden din, så leverandørene ser hvem som henter.
    // 'brukeragent' => 'Driftsstatus (https://status.eksempel.no)',

    // Hvilke tjenester som vises, i rekkefølge. Utelatt = alle.
    // Innebygde: aidn, nhn, stoe, altinn, digdir, skatt, fiks, azure, sikt.
    // 'kilder' => ['nhn', 'digdir', 'altinn', 'stoe', 'skatt', 'fiks', 'azure', 'sikt'],

    // Egne kilder. Typen 'statuspage' virker for alle sider laget med Atlassian Statuspage
    // (de har /api/v2/summary.json). Id: små bokstaver, tall, - og _, høyst 20 tegn.
    // 'egne_kilder' => [
    //     'github' => ['navn' => 'GitHub', 'type' => 'statuspage', 'side' => 'https://www.githubstatus.com/'],
    // ],

    // Lenken til kildekoden nederst på siden. Har du en egen kopi (fork), pek den hit.
    // 'kildekode' => 'https://github.com/magnehg/statuspage',

    // Hvor gamle dataene kan være før et besøk på siden henter på nytt (sekunder).
    'maks_alder' => 300,
    // Mappen for datafilene. Standard er data/ i prosjektet (sperret med .htaccess).
    // Ligger den utenfor webroten, er det enda bedre.
    // 'data_mappe' => __DIR__ . '/../data',
];
