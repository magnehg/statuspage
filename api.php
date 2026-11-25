<?php
declare(strict_types=1);
/*
 * api.php?a=status     Status nå. Er dataene eldre enn maks_alder (5 min), henter denne forespørselen
 *                      dem på nytt, men bare hvis ingen andre holder på (se synk()).
 * api.php?a=tidslinje  Én rad per kilde og én celle per dag, 90 dager tilbake og 14 frem.
 */
require __DIR__ . '/lib/start.php';

const TILBAKE = 90;
const FREM = 14;

$a = (string) ($_GET['a'] ?? 'status');
$maks = max(60, (int) (konfig()['maks_alder'] ?? 300));

try {
    match ($a) {
        'status' => api_status($maks),
        'tidslinje' => api_tidslinje(),
        default => json_ut(['feil' => 'Ukjent handling'], 400),
    };
} catch (Throwable $e) {
    error_log('statusside api: ' . $e->getMessage());
    json_ut(['feil' => 'Noe gikk galt på serveren'], 500);
}

function api_status(int $maks): never
{
    $synk = synk('besok', false, $maks)['resultat'];
    $rader = les_json('kilder.json');
    $kilder = [];
    $eldst = null;
    foreach (kilder() as $id => $k) {
        $r = $rader[$id] ?? [];
        $status = $r['status'] ?? null;
        $gammel = empty($r['ok_hentet']) || strtotime($r['ok_hentet'] . ' UTC') < time() - 3 * 3600;
        if ($status === null || $gammel) {
            $status = 'ukjent';
        }
        $kilder[] = [
            'id' => $id,
            'navn' => $k['navn'],
            'navn_en' => $k['navn_en'] ?? '',
            'side' => $k['side'],
            'status' => $status,
            'oppsummering' => $r['oppsummering'] ?? null,
            'antall' => (int) ($r['antall'] ?? 0),
            'komponenter' => $r['komponenter'] ?? [],
            'feil' => $r['feil'] ?? null,
            'hentet' => iso($r['ok_hentet'] ?? null),
        ];
        if (!empty($r['hentet']) && ($eldst === null || $r['hentet'] < $eldst)) {
            $eldst = $r['hentet'];
        }
    }

    $alle = alle_hendelser();
    $aktive = array_values(array_filter($alle, fn ($h) => $h['status'] === 'pagar'));
    usort($aktive, fn ($a, $b) => [NIVAA[$b['niva']] ?? 0, $b['start']] <=> [NIVAA[$a['niva']] ?? 0, $a['start']]);
    $grense = gmdate('Y-m-d H:i:s', time() + FREM * 86400);
    $kommende = array_values(array_filter($alle, fn ($h) => $h['status'] === 'planlagt' && $h['start'] <= $grense));
    usort($kommende, fn ($a, $b) => $a['start'] <=> $b['start']);
    $kommende = array_slice($kommende, 0, 100);

    $neste = $eldst ? strtotime($eldst . ' UTC') + $maks : time();
    json_ut([
        'hentet' => iso($eldst),
        'neste' => gmdate('Y-m-d\TH:i:s\Z', max($neste, time() + 30)),
        'synk' => $synk,
        'kilder' => $kilder,
        'aktive' => array_map('hendelse_ut', $aktive),
        'kommende' => array_map('hendelse_ut', $kommende),
    ]);
}

function api_tidslinje(): never
{
    $oslo = new DateTimeZone(TIDSSONE);
    $utcSone = new DateTimeZone('UTC');
    $idag = new DateTimeImmutable('today', $oslo);
    $forste = $idag->modify('-' . TILBAKE . ' days');
    $siste = $idag->modify('+' . (FREM + 1) . ' days'); // eksklusiv

    $fraUtc = $forste->setTimezone($utcSone)->format('Y-m-d H:i:s');
    $tilUtc = $siste->setTimezone($utcSone)->format('Y-m-d H:i:s');
    $naa = naa();

    // Alt som overlapper perioden. Sluttiden: kjent slutt, ellers nå (pågår), ellers planlagt slutt.
    $treff = array_filter(alle_hendelser(), function ($h) use ($tilUtc, $fraUtc, $naa) {
        $slutt = $h['slutt'] ?? ($h['status'] === 'pagar' ? $naa : ($h['planlagt_til'] ?? $h['start']));
        return $h['start'] < $tilUtc && $slutt >= $fraUtc;
    });
    usort($treff, fn ($a, $b) => $a['start'] <=> $b['start']);

    $dager = [];
    for ($d = $forste; $d < $siste; $d = $d->modify('+1 day')) {
        $dager[] = $d->format('Y-m-d');
    }
    $indeks = array_flip($dager);
    $celler = [];
    foreach (array_keys(kilder()) as $id) {
        $celler[$id] = array_fill(0, count($dager), null);
    }
    $hendelser = [];
    foreach ($treff as $h) {
        if (!isset($celler[$h['kilde']])) {
            continue;
        }
        $start = new DateTimeImmutable($h['start'], $utcSone);
        $sluttTekst = $h['slutt'] ?? ($h['status'] === 'pagar' ? $naa : ($h['planlagt_til'] ?? $h['start']));
        $slutt = new DateTimeImmutable($sluttTekst, $utcSone);
        // Rene informasjonsmeldinger (som Altinns faste svindelvarsel) markeres bare på dagen de kom.
        if ($h['type'] === 'melding' && $h['niva'] === 'melding') {
            $slutt = $start;
        }
        $d = $start->setTimezone($oslo)->setTime(0, 0);
        $sisteDag = $slutt->setTimezone($oslo)->setTime(0, 0);
        $med = false;
        for (; $d <= $sisteDag; $d = $d->modify('+1 day')) {
            $i = $indeks[$d->format('Y-m-d')] ?? null;
            if ($i === null) {
                continue;
            }
            $c = $celler[$h['kilde']][$i] ?? ['niva' => 'ok', 'ider' => []];
            $c['niva'] = verst($c['niva'], $h['niva']);
            $c['ider'][] = $h['id'];
            $celler[$h['kilde']][$i] = $c;
            $med = true;
        }
        if ($med) {
            $hendelser[$h['id']] = hendelse_ut($h);
        }
    }

    $rader = [];
    foreach (kilder() as $id => $k) {
        $feilDager = 0;
        foreach ($celler[$id] as $i => $c) {
            if ($c && $i <= TILBAKE && in_array($c['niva'], ['redusert', 'delvis', 'nede'], true)) {
                $feilDager++;
            }
        }
        $rader[] = ['id' => $id, 'navn' => $k['navn'], 'navn_en' => $k['navn_en'] ?? '', 'side' => $k['side'], 'celler' => $celler[$id], 'feil_dager' => $feilDager];
    }

    json_ut([
        'idag' => $idag->format('Y-m-d'),
        'dager' => $dager,
        'rader' => $rader,
        'hendelser' => (object) $hendelser,
    ], 200, 60);
}

function hendelse_ut(array $h): array
{
    return [
        'id' => $h['id'],
        'kilde' => $h['kilde'],
        'kilde_navn' => kilder()[$h['kilde']]['navn'] ?? $h['kilde'],
        'kilde_navn_en' => kilder()[$h['kilde']]['navn_en'] ?? '',
        'type' => $h['type'],
        'tittel' => $h['tittel'],
        'niva' => $h['niva'],
        'status' => $h['status'],
        'start' => iso($h['start']),
        'slutt' => iso($h['slutt']),
        'planlagt_til' => iso($h['planlagt_til']),
        'url' => $h['url'] !== '' ? $h['url'] : (kilder()[$h['kilde']]['side'] ?? ''),
        'komponenter' => $h['komponenter'] ?? [],
    ];
}
