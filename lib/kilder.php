<?php
declare(strict_types=1);

/**
 * De innebygde kildene med egne tolkere (og standardutvalget). `type` avgjør hvordan de hentes og tolkes
 * (lib/tolk.php), `side` er siden man sendes til ved klikk. I tillegg kommer katalogen (lib/katalog.json,
 * norske Statuspage-sider) og egne kilder fra cf/config.php. Hvilke som vises, velges i `kilder`.
 */
const ALLE_KILDER = [
    'aidn'   => ['navn' => 'AIDN',            'kategori' => 'helse', 'type' => 'statuspage', 'side' => 'https://www.aidnstatus.no/'],
    'nhn'    => ['navn' => 'Norsk helsenett', 'kategori' => 'helse', 'type' => 'statuspage', 'side' => 'https://status.nhn.no/'],
    'stoe'   => ['navn' => 'Stø (BankID)',    'kategori' => 'bank', 'type' => 'stoe',       'side' => 'https://status.stoe.no/'],
    'altinn' => ['navn' => 'Altinn',          'kategori' => 'offentlig', 'type' => 'altinn',     'side' => 'https://info.altinn.no/om-altinn/driftsmeldinger/'],
    'digdir' => ['navn' => 'Digdir',          'kategori' => 'offentlig', 'type' => 'statuspage', 'side' => 'https://status.digdir.no/'],
    'skatt'  => ['navn' => 'Skatteetaten',    'kategori' => 'offentlig', 'type' => 'statuspage', 'side' => 'https://status.skatteetaten.no/'],
    'fiks'   => ['navn' => 'KS Fiks',         'kategori' => 'offentlig', 'type' => 'statuspage', 'side' => 'https://status.fiks.ks.no/'],
    'azure'  => ['navn' => 'Azure – Norge', 'navn_en' => 'Azure – Norway', 'kategori' => 'programvare', 'type' => 'azure',      'side' => 'https://azure.status.microsoft/nb-no/status'],
    'sikt'   => ['navn' => 'Sikt',            'kategori' => 'offentlig', 'type' => 'sikt',       'side' => 'https://www.sikt.no/driftsmeldinger'],
];

/** Typer som kan brukes i egne kilder. Statuspage-typen virker for alle sider laget med Atlassian Statuspage. */
const EGNE_KILDETYPER = ['statuspage'];

/**
 * Kildene denne installasjonen viser og henter, i rekkefølgen de står i.
 * cf/config.php:
 *   'kilder' => ['nhn', 'digdir', 'visma', 'egen1'],        // id-er fra ALLE_KILDER, katalogen og egne_kilder; utelatt = de innebygde
 *   'egne_kilder' => ['egen1' => ['navn' => 'Leverandør X', 'navn_en' => 'Provider X', 'type' => 'statuspage', 'side' => 'https://status.x.no/']],
 */
/** Katalogen: [kategorier, kilder] fra lib/katalog.json. */
function katalog(): array
{
    static $k = null;
    return $k ??= json_decode((string) @file_get_contents(__DIR__ . '/katalog.json'), true) ?: ['kategorier' => [], 'kilder' => []];
}

/** Alle kilder som kan velges: de innebygde, katalogen og egne fra cf/config.php. */
function mulige_kilder(): array
{
    static $m = null;
    if ($m !== null) {
        return $m;
    }
    $mulige = ALLE_KILDER;
    foreach (katalog()['kilder'] as $e) {
        if (!isset($mulige[$e['id']]) && ($e['type'] ?? '') === 'statuspage') {
            $mulige[$e['id']] = ['navn' => $e['navn'], 'kategori' => $e['kategori'] ?? 'annet', 'type' => 'statuspage', 'side' => $e['side']];
        }
    }
    foreach ((array) innst('egne_kilder', []) as $id => $e) {
        $id = (string) $id;
        if (!preg_match('/^[a-z0-9_-]{1,20}$/', $id) || !is_array($e)
            || !in_array($e['type'] ?? '', EGNE_KILDETYPER, true)
            || !preg_match('~^https://[a-z0-9.-]+(:\d+)?(/.*)?$~i', (string) ($e['side'] ?? ''))
            || trim((string) ($e['navn'] ?? '')) === '') {
            error_log("statusside: ugyldig egen kilde «{$id}» i cf/config.php");
            continue;
        }
        $mulige[$id] = ['navn' => trim((string) $e['navn']), 'navn_en' => trim((string) ($e['navn_en'] ?? '')), 'kategori' => 'egne', 'type' => $e['type'], 'side' => (string) $e['side']];
    }
    return $m = $mulige;
}

/** Standardutvalget når `kilder` ikke er satt: de innebygde og egne kilder. */
function standard_kilder(): array
{
    return array_merge(array_keys(ALLE_KILDER), array_map('strval', array_keys((array) innst('egne_kilder', []))));
}

function kilder(): array
{
    static $k = null;
    if ($k !== null) {
        return $k;
    }
    $mulige = mulige_kilder();
    $valgt = innst('kilder');
    if (!is_array($valgt)) {
        $valgt = standard_kilder();
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
