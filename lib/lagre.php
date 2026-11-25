<?php
declare(strict_types=1);

/** Fri lagringsplass per kilde (f.eks. Azure-regionene og når de ble hentet). */
function kilde_data(string $id): array
{
    return les_json('kilder.json')[$id]['data'] ?? [];
}

function kilde_data_sett(string $id, array $endring): void
{
    $k = les_json('kilder.json');
    $k[$id]['data'] = array_merge($k[$id]['data'] ?? [], $endring);
    skriv_json('kilder.json', $k);
}

/** Lagrer resultatet fra tolk_kilde(): status, hendelser, observerte avvik og statusloggen. */
function lagre_resultat(string $id, array $r): void
{
    $naa = naa();

    $fil = kildefil('hendelser', $id);
    $alle = les_json($fil);
    $sett = [];
    foreach ($r['hendelser'] as $h) {
        $eid = mb_substr($h['ekstern_id'], 0, 190);
        $sett[$eid] = true;
        $gml = $alle[$eid] ?? null;
        $alle[$eid] = [
            'id' => $gml['id'] ?? hendelse_id($id, $eid),
            'kilde' => $id,
            'ekstern_id' => $eid,
            'type' => $h['type'],
            'tittel' => mb_substr($h['tittel'], 0, 255),
            'niva' => $h['niva'],
            'status' => $h['status'],
            'start' => $h['start'] ?? $gml['start'] ?? $naa,
            'slutt' => $h['slutt'] ?? ($h['status'] === 'lost' ? ($gml['slutt'] ?? null) : null),
            'planlagt_til' => $h['planlagt_til'],
            'url' => mb_substr($h['url'], 0, 500),
            'komponenter' => $h['komponenter'],
            'tekst' => $h['tekst'],
            'opprettet' => $gml['opprettet'] ?? $naa,
            'endret' => ($gml && $gml['status'] === $h['status'] && $gml['tittel'] === $h['tittel']) ? $gml['endret'] : $naa,
        ];
    }

    // Åpne hendelser som ikke lenger er med i kilden, er over.
    foreach ($alle as $eid => $h) {
        if ($h['status'] === 'pagar' && in_array($h['type'], $r['lukk_manglende'], true) && !isset($sett[$eid])) {
            $alle[$eid]['status'] = 'lost';
            $alle[$eid]['slutt'] ??= $naa;
            $alle[$eid]['endret'] = $naa;
        }
    }

    $alle = lagre_observert($id, $alle, $r['observert'], $naa);
    skriv_json($fil, $alle);
    lagre_statuslogg($id, $r['status'], $naa);

    // Til slutt, så data_alder() ikke regner kilden som fersk før alt er lagret.
    $k = les_json('kilder.json');
    $k[$id] = array_merge($k[$id] ?? [], [
        'status' => $r['status'],
        'oppsummering' => $r['oppsummering'],
        'komponenter' => $r['komponenter'],
        'antall' => $r['antall'],
        'hentet' => $naa,
        'ok_hentet' => $naa,
        'feil' => null,
    ]);
    skriv_json('kilder.json', $k);
}

function hendelse_id(string $kilde, string $eid): string
{
    return substr(sha1($kilde . "\0" . $eid), 0, 12);
}

/**
 * Avvik kilden ikke har egne hendelser for (Stø, Azure-regionene): en hendelse åpnes første gang
 * vi ser avviket og lukkes første gang det er borte. Varigheten blir da så nøyaktig som hentingene.
 */
function lagre_observert(string $id, array $alle, array $obs, string $naa): array
{
    $apne = [];
    foreach ($alle as $eid => $h) {
        if ($h['type'] === 'observert' && $h['status'] === 'pagar') {
            $apne[preg_replace('/@\d+$/', '', (string) $eid)] = $eid;
        }
    }
    $na = [];
    foreach ($obs as $o) {
        $na[$o['nokkel']] = $o;
    }
    foreach ($apne as $nokkel => $eid) {
        if (!isset($na[$nokkel])) {
            $alle[$eid]['status'] = 'lost';
            $alle[$eid]['slutt'] = $naa;
            $alle[$eid]['endret'] = $naa;
        } else {
            $alle[$eid]['niva'] = verst($alle[$eid]['niva'], $na[$nokkel]['niva']);
        }
    }
    foreach ($na as $nokkel => $o) {
        if (!isset($apne[$nokkel])) {
            $eid = mb_substr((string) $nokkel, 0, 170) . '@' . time();
            $alle[$eid] = [
                'id' => hendelse_id($id, $eid), 'kilde' => $id, 'ekstern_id' => $eid, 'type' => 'observert',
                'tittel' => mb_substr($o['tittel'], 0, 255), 'niva' => $o['niva'], 'status' => 'pagar',
                'start' => $naa, 'slutt' => null, 'planlagt_til' => null, 'url' => $o['url'],
                'komponenter' => [], 'tekst' => null, 'opprettet' => $naa, 'endret' => $naa,
            ];
        }
    }
    return $alle;
}

/** Statusloggen er sammenhengende perioder per kilde (grunnlaget for oppetid). */
function lagre_statuslogg(string $id, string $status, string $naa): void
{
    $fil = kildefil('statuslogg', $id);
    $logg = les_json($fil);
    $siste = count($logg) - 1;
    if ($siste >= 0 && $logg[$siste]['til'] === null) {
        if ($logg[$siste]['status'] === $status) {
            return;
        }
        $logg[$siste]['til'] = $naa;
    }
    $logg[] = ['status' => $status, 'fra' => $naa, 'til' => null];
    skriv_json($fil, $logg);
}

function lagre_feil(string $id, string $melding): void
{
    $k = les_json('kilder.json');
    $k[$id]['hentet'] = naa();
    $k[$id]['feil'] = mb_substr($melding, 0, 500);
    skriv_json('kilder.json', $k);
}

function lagre_synk(array $rad): void
{
    $logg = les_json('synk.json');
    $logg[] = $rad;
    skriv_json('synk.json', array_slice($logg, -500));
}

/** Sletter historikk eldre enn 400 dager. */
function opprydding(): void
{
    $grense = gmdate('Y-m-d H:i:s', time() - 400 * 86400);
    foreach (array_keys(kilder()) as $id) {
        $fil = kildefil('hendelser', $id);
        $alle = les_json($fil);
        $for = count($alle);
        $alle = array_filter($alle, fn ($h) => !($h['status'] === 'lost' && ($h['slutt'] ?? $h['start']) < $grense));
        if (count($alle) !== $for) {
            skriv_json($fil, $alle);
        }
        $lfil = kildefil('statuslogg', $id);
        $logg = les_json($lfil);
        $ny = array_values(array_filter($logg, fn ($p) => $p['til'] === null || $p['til'] >= $grense));
        if (count($ny) !== count($logg)) {
            skriv_json($lfil, $ny);
        }
    }
}
