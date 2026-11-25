<?php
declare(strict_types=1);

/**
 * De innebygde kildene. `type` avgjør hvordan de hentes og tolkes (lib/tolk.php),
 * `side` er siden man sendes til ved klikk. Hvilke som vises, velges i cf/config.php
 * (`kilder`), og egne kan legges til der (`egne_kilder`), se kilder().
 */
const ALLE_KILDER = [
    'aidn'   => ['navn' => 'AIDN',            'type' => 'statuspage', 'side' => 'https://www.aidnstatus.no/'],
    'nhn'    => ['navn' => 'Norsk helsenett', 'type' => 'statuspage', 'side' => 'https://status.nhn.no/'],
    'stoe'   => ['navn' => 'Stø (BankID)',    'type' => 'stoe',       'side' => 'https://status.stoe.no/'],
    'altinn' => ['navn' => 'Altinn',          'type' => 'altinn',     'side' => 'https://info.altinn.no/om-altinn/driftsmeldinger/'],
    'digdir' => ['navn' => 'Digdir',          'type' => 'statuspage', 'side' => 'https://status.digdir.no/'],
    'skatt'  => ['navn' => 'Skatteetaten',    'type' => 'statuspage', 'side' => 'https://status.skatteetaten.no/'],
    'fiks'   => ['navn' => 'KS Fiks',         'type' => 'statuspage', 'side' => 'https://status.fiks.ks.no/'],
    'azure'  => ['navn' => 'Azure – Norge', 'navn_en' => 'Azure – Norway', 'type' => 'azure',      'side' => 'https://azure.status.microsoft/nb-no/status'],
    'sikt'   => ['navn' => 'Sikt',            'type' => 'sikt',       'side' => 'https://www.sikt.no/driftsmeldinger'],
];

/** Typer som kan brukes i egne kilder. Statuspage-typen virker for alle sider laget med Atlassian Statuspage. */
const EGNE_KILDETYPER = ['statuspage'];

/**
 * Kildene denne installasjonen viser og henter, i rekkefølgen de står i.
 * cf/config.php:
 *   'kilder' => ['nhn', 'digdir', 'egen1'],                 // id-er fra ALLE_KILDER og egne_kilder; utelatt = alle
 *   'egne_kilder' => ['egen1' => ['navn' => 'Leverandør X', 'navn_en' => 'Provider X', 'type' => 'statuspage', 'side' => 'https://status.x.no/']],
 */
function kilder(): array
{
    static $k = null;
    if ($k !== null) {
        return $k;
    }
    $mulige = ALLE_KILDER;
    foreach ((array) innst('egne_kilder', []) as $id => $e) {
        $id = (string) $id;
        if (!preg_match('/^[a-z0-9_-]{1,20}$/', $id) || !is_array($e)
            || !in_array($e['type'] ?? '', EGNE_KILDETYPER, true)
            || !preg_match('~^https://[a-z0-9.-]+(:\d+)?(/.*)?$~i', (string) ($e['side'] ?? ''))
            || trim((string) ($e['navn'] ?? '')) === '') {
            error_log("statusside: ugyldig egen kilde «{$id}» i cf/config.php");
            continue;
        }
        $mulige[$id] = ['navn' => trim((string) $e['navn']), 'navn_en' => trim((string) ($e['navn_en'] ?? '')), 'type' => $e['type'], 'side' => (string) $e['side']];
    }
    $valgt = innst('kilder');
    if (!is_array($valgt)) {
        $valgt = array_keys($mulige);
    }
    $k = [];
    foreach ($valgt as $id) {
        if (isset($mulige[$id])) {
            $k[$id] = $mulige[$id];
        } else {
            error_log("statusside: ukjent kilde «{$id}» i cf/config.php");
        }
    }
    return $k;
}

/** Nivåene, fra best til verst. `ukjent` betyr at kilden ikke kunne hentes. */
const NIVAA = ['ok' => 0, 'melding' => 1, 'vedlikehold' => 2, 'redusert' => 3, 'delvis' => 4, 'nede' => 5];

const NIVAA_TEKST = [
    'ok' => 'I orden', 'melding' => 'Driftsmelding', 'vedlikehold' => 'Vedlikehold',
    'redusert' => 'Redusert', 'delvis' => 'Delvis nede', 'nede' => 'Nede', 'ukjent' => 'Ukjent',
];

/** Det verste av to nivåer. */
function verst(string $a, string $b): string
{
    return (NIVAA[$b] ?? -1) > (NIVAA[$a] ?? -1) ? $b : $a;
}

/** Azure: regionene vi følger (kolonneoverskriftene på statussiden). */
const AZURE_REGIONER = ['Øst-Norge', 'Norge, vest'];

/** Hvor ofte Azure-siden med regionene (ca. 7 MB) hentes når RSS-strømmen er tom. */
const AZURE_HTML_HVER = 6 * 3600;
/** … og når RSS-strømmen har en hendelse. */
const AZURE_HTML_HVER_HENDELSE = 15 * 60;
