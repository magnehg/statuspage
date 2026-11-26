<?php
declare(strict_types=1);

/*
 * Hver kilde tolkes til samme form:
 * [
 *   'status'       => nivå (se NIVAA),
 *   'oppsummering' => [kode, n, m]: kort oppsummering som oversettes i nettleseren (ops.<kode> i assets/tekster.json),
 *   'komponenter'  => [['navn' => …, 'status' => nivå], …]   (bare de som ikke er ok, pluss antallet)
 *   'antall'       => antall komponenter/tjenester som følges,
 *   'hendelser'    => [hendelse, …],
 *   'observert'    => [['nokkel', 'tittel', 'niva', 'url'], …]  nåværende avvik uten egen hendelse hos kilden,
 *   'lukk_manglende' => liste over typer der en åpen hendelse som ikke lenger er med, regnes som avsluttet,
 * ]
 * En hendelse: ekstern_id, type (hendelse|vedlikehold|melding), tittel, niva, status (planlagt|pagar|lost),
 * start, slutt, planlagt_til (UTC 'Y-m-d H:i:s' eller null), url, komponenter (liste med navn), tekst.
 */

/** @param bool $full Hent også historikken (cron). */
function tolk_kilde(string $id, bool $full): array
{
    $k = kilder()[$id];
    return match ($k['type']) {
        'statuspage' => tolk_statuspage($k['side'], $full),
        'stoe'       => tolk_stoe($k['side']),
        'altinn'     => tolk_altinn($k['side']),
        'azure'      => tolk_azure($id, $k['side']),
        'sikt'       => tolk_sikt($k['side']),
    };
}

// ---------------------------------------------------------------- Statuspage (Atlassian)

const SP_KOMPONENT = [
    'operational' => 'ok', 'degraded_performance' => 'redusert', 'partial_outage' => 'delvis',
    'major_outage' => 'nede', 'under_maintenance' => 'vedlikehold',
];
const SP_IMPACT = ['none' => 'melding', 'minor' => 'redusert', 'major' => 'delvis', 'critical' => 'nede', 'maintenance' => 'vedlikehold'];

function tolk_statuspage(string $base, bool $full): array
{
    $base = rtrim($base, '/');
    $sum = hent_json("$base/api/v2/summary.json");

    $status = 'ok';
    $avvik = [];
    $antall = 0;
    foreach ($sum['components'] ?? [] as $c) {
        if (!empty($c['group'])) {
            continue; // gruppeoverskrift
        }
        $antall++;
        $n = SP_KOMPONENT[$c['status'] ?? ''] ?? 'ok';
        if ($n !== 'ok') {
            $avvik[] = ['navn' => (string) $c['name'], 'status' => $n];
            $status = verst($status, $n);
        }
    }

    $hendelser = [];
    foreach ($sum['incidents'] ?? [] as $i) {
        $h = sp_hendelse($i, false);
        $hendelser[$h['ekstern_id']] = $h;
        if ($h['status'] === 'pagar') {
            $status = verst($status, $h['niva']);
        }
    }
    foreach ($sum['scheduled_maintenances'] ?? [] as $i) {
        $h = sp_hendelse($i, true);
        $hendelser[$h['ekstern_id']] = $h;
        if ($h['status'] === 'pagar') {
            $status = verst($status, 'vedlikehold');
        }
    }

    if ($full) {
        // Historikken: de siste 50 hendelsene og vedlikeholdene, også de som er løst.
        foreach (hent_json("$base/api/v2/incidents.json")['incidents'] ?? [] as $i) {
            $h = sp_hendelse($i, false);
            $hendelser[$h['ekstern_id']] ??= $h;
        }
        foreach (hent_json("$base/api/v2/scheduled-maintenances.json")['scheduled_maintenances'] ?? [] as $i) {
            $h = sp_hendelse($i, true);
            $hendelser[$h['ekstern_id']] ??= $h;
        }
    }

    $aktive = count(array_filter($hendelser, fn ($h) => $h['status'] === 'pagar' && $h['type'] === 'hendelse'));
    $oppsummering = match (true) {
        $aktive > 0 => ['aktive_hendelser', $aktive],
        $status === 'vedlikehold' => ['vedlikehold_pagar'],
        $status !== 'ok' => ['komponenter_avvik', count($avvik), $antall],
        default => ['ingen_hendelser'],
    };

    return [
        'status' => $status,
        'oppsummering' => $oppsummering,
        'komponenter' => $avvik,
        'antall' => $antall,
        'hendelser' => array_values($hendelser),
        'observert' => [],
        // Er en åpen hendelse borte fra summary.json, er den løst. Cron retter sluttiden fra incidents.json.
        'lukk_manglende' => $full ? [] : ['hendelse', 'vedlikehold'],
    ];
}

function sp_hendelse(array $i, bool $vedlikehold): array
{
    $st = (string) ($i['status'] ?? '');
    if ($vedlikehold) {
        $status = match ($st) {
            'scheduled' => 'planlagt',
            'in_progress', 'verifying' => 'pagar',
            default => 'lost',
        };
        $start = utc($i['scheduled_for'] ?? null) ?? utc($i['started_at'] ?? null);
        $slutt = $status === 'lost' ? (utc($i['resolved_at'] ?? null) ?? utc($i['scheduled_until'] ?? null)) : null;
        $niva = 'vedlikehold';
        // Noen vedlikehold blir stående som «scheduled» etter at tiden er ute.
        $til = utc($i['scheduled_until'] ?? null);
        if ($status === 'planlagt' && $til !== null && $til < naa()) {
            $status = 'lost';
            $slutt = $til;
        }
    } else {
        $status = in_array($st, ['resolved', 'postmortem', 'completed'], true) ? 'lost' : 'pagar';
        $start = utc($i['started_at'] ?? null) ?? utc($i['created_at'] ?? null);
        $slutt = $status === 'lost' ? (utc($i['resolved_at'] ?? null) ?? utc($i['updated_at'] ?? null)) : null;
        $niva = SP_IMPACT[$i['impact'] ?? 'none'] ?? 'melding';
    }
    $siste = $i['incident_updates'][0]['body'] ?? '';
    return [
        'ekstern_id' => (string) $i['id'],
        'type' => $vedlikehold ? 'vedlikehold' : 'hendelse',
        'tittel' => (string) $i['name'],
        'niva' => $niva,
        'status' => $status,
        'start' => $start,
        'slutt' => $slutt,
        'planlagt_til' => $vedlikehold ? utc($i['scheduled_until'] ?? null) : null,
        'url' => (string) ($i['shortlink'] ?? ''),
        'komponenter' => array_values(array_map(fn ($c) => (string) $c['name'], $i['components'] ?? [])),
        'tekst' => mb_substr(trim((string) $siste), 0, 1000),
    ];
}

// ---------------------------------------------------------------- Stø (BankID)

function tolk_stoe(string $side): array
{
    $liste = hent_json(rtrim($side, '/') . '/api/v1/status');
    $status = 'ok';
    $avvik = [];
    $obs = [];
    foreach ($liste as $c) {
        $n = SP_KOMPONENT[$c['status'] ?? ''] ?? 'ok';
        if ($n !== 'ok') {
            $avvik[] = ['navn' => (string) $c['name'], 'status' => $n];
            $obs[] = ['nokkel' => (string) $c['id'], 'tittel' => (string) $c['name'], 'niva' => $n, 'url' => $side];
            $status = verst($status, $n);
        }
    }
    return [
        'status' => $status,
        'oppsummering' => $avvik ? ['tjenester_berort', count($avvik), count($liste)] : ['alle_virker'],
        'komponenter' => $avvik,
        'antall' => count($liste),
        'hendelser' => [],
        'observert' => $obs,
        'lukk_manglende' => [],
    ];
}

// ---------------------------------------------------------------- Altinn (driftsmeldinger i HTML)

/**
 * Siden er bygd med Astro: dataene ligger HTML-kodet i attributtet props="…" på en astro-island,
 * serialisert som [type, verdi] (0 = verdi/objekt, 1 = liste).
 */
function tolk_altinn(string $side): array
{
    $html = hent($side)['innhold'];
    $artikler = [];
    if (preg_match_all('/\sprops="([^"]*OperationalMessageArticlePage[^"]*)"/', $html, $m)) {
        foreach ($m[1] as $attr) {
            $data = json_decode(html_entity_decode($attr, ENT_QUOTES | ENT_HTML5, 'UTF-8'), true);
            if (is_array($data)) {
                altinn_finn_artikler(astro_verdi([0, $data]), $artikler);
            }
        }
    }
    if (!$artikler && !str_contains($html, 'OperationalMessageArchivePage')) {
        throw new RuntimeException('Fant ikke driftsmeldingene i Altinn-siden (har siden endret seg?)');
    }

    $base = 'https://info.altinn.no';
    $status = 'ok';
    $hendelser = [];
    // En alvorlig melding (danger/warning) teller som avvik bare de første dagene etter at den sist ble endret
    // (melding_dager, standard 7). Altinn lar meldinger bli stående lenge etter at problemet er over.
    $grense = gmdate('Y-m-d H:i:s', time() - melding_dager() * 86400);
    foreach ($artikler as $a) {
        $variant = (string) ($a['colorVariant'] ?? 'info');
        $endret = utc($a['lastChangedDateString'] ?? null);
        $alvorlig = in_array($variant, ['danger', 'warning'], true) && ($endret === null || $endret >= $grense);
        $niva = $alvorlig ? 'redusert' : 'melding';
        if ($alvorlig) {
            $status = verst($status, 'redusert');
        }
        $tekst = '';
        foreach ($a['mainBodyRichText']['items'] ?? [] as $it) {
            $tekst .= ' ' . ($it['html'] ?? '');
        }
        $url = (string) ($a['url'] ?? '');
        $hendelser[] = [
            'ekstern_id' => $url !== '' ? $url : md5((string) $a['pageName']),
            'type' => 'melding',
            'tittel' => trim((string) $a['pageName']),
            'niva' => $niva,
            'status' => 'pagar',
            'start' => utc($a['lastChangedDateString'] ?? null),
            'slutt' => null,
            'planlagt_til' => null,
            'url' => $url !== '' ? $base . $url : $side,
            'komponenter' => [],
            'tekst' => mb_substr(trim(html_entity_decode(strip_tags($tekst), ENT_QUOTES | ENT_HTML5, 'UTF-8')), 0, 1000),
        ];
    }
    $antall = count(array_filter($hendelser, fn ($h) => $h['niva'] !== 'melding'));
    return [
        'status' => $status,
        'oppsummering' => $antall ? ['driftsmeldinger', $antall] : ['ingen_driftsmeldinger'],
        'komponenter' => [],
        'antall' => 0,
        'hendelser' => $hendelser,
        'observert' => [],
        // En melding som er fjernet fra siden, regnes som avsluttet.
        'lukk_manglende' => ['melding'],
    ];
}

/** Pakker ut Astros serialisering. */
function astro_verdi(mixed $v): mixed
{
    if (!is_array($v) || count($v) !== 2 || !is_int($v[0] ?? null)) {
        return $v;
    }
    [$type, $verdi] = $v;
    if ($type === 1 && is_array($verdi)) {
        return array_map('astro_verdi', $verdi);
    }
    if ($type === 0 && is_array($verdi) && !array_is_list($verdi)) {
        $ut = [];
        foreach ($verdi as $n => $x) {
            $ut[$n] = astro_verdi($x);
        }
        return $ut;
    }
    return $verdi;
}

function altinn_finn_artikler(mixed $v, array &$ut): void
{
    if (!is_array($v)) {
        return;
    }
    if (($v['componentName'] ?? null) === 'OperationalMessageArticlePage' && isset($v['pageName'])) {
        $ut[(string) ($v['url'] ?? $v['pageName'])] = $v;
        return;
    }
    foreach ($v as $x) {
        altinn_finn_artikler($x, $ut);
    }
}

// ---------------------------------------------------------------- Azure (RSS + regionene i HTML)

function tolk_azure(string $id, string $side): array
{
    $rss = hent_xml('https://rssfeed.azure.status.microsoft/nb-no/status/feed/');
    $hendelser = [];
    foreach ($rss->channel->item ?? [] as $it) {
        $tittel = trim((string) $it->title);
        $hendelser[] = [
            'ekstern_id' => (string) ($it->guid ?: $it->link ?: md5($tittel)),
            'type' => 'hendelse',
            'tittel' => $tittel,
            'niva' => 'redusert',
            'status' => 'pagar',
            'start' => utc((string) $it->pubDate),
            'slutt' => null,
            'planlagt_til' => null,
            'url' => (string) ($it->link ?: $side),
            'komponenter' => [],
            'tekst' => mb_substr(trim(strip_tags((string) $it->description)), 0, 1000),
        ];
    }

    // Regionene hentes sjeldent (siden er stor), oftere når RSS-strømmen har en hendelse.
    $forrige = kilde_data($id);
    $hver = $hendelser ? AZURE_HTML_HVER_HENDELSE : AZURE_HTML_HVER;
    $regioner = $forrige['regioner'] ?? null;
    if ($regioner === null || time() - (int) ($forrige['regioner_hentet'] ?? 0) >= $hver) {
        $regioner = azure_regioner(hent($side, 0)['innhold']);
        kilde_data_sett($id, ['regioner' => $regioner, 'regioner_hentet' => time()]);
    }

    $status = $hendelser ? 'redusert' : 'ok';
    $avvik = [];
    $obs = [];
    $antall = 0;
    foreach ($regioner as $region => $tjenester) {
        foreach ($tjenester as $tjeneste => $n) {
            $antall++;
            if ($n !== 'ok') {
                $avvik[] = ['navn' => "$tjeneste ($region)", 'status' => $n];
                $obs[] = ['nokkel' => "$region|$tjeneste", 'tittel' => "$tjeneste ($region)", 'niva' => $n, 'url' => $side];
                $status = verst($status, $n);
            }
        }
    }
    return [
        'status' => $status,
        'oppsummering' => match (true) {
            (bool) $avvik => ['azure_avvik', count($avvik)],
            (bool) $hendelser => ['azure_hendelser', count($hendelser)],
            default => ['azure_ok'],
        },
        'komponenter' => $avvik,
        'antall' => count($regioner),
        'hendelser' => $hendelser,
        'observert' => $obs,
        'lukk_manglende' => ['hendelse'],
    ];
}

/**
 * Leser tabellen for Europa: kolonnene for de norske regionene og ikonet i hver celle.
 * @return array<string, array<string, string>> region => [tjeneste => nivå]; tjenester som ikke finnes i regionen, er utelatt.
 */
function azure_regioner(string $html): array
{
    $start = strpos($html, '<table data-zone-name="europe"');
    if ($start === false) {
        throw new RuntimeException('Fant ikke Europa-tabellen på Azure-siden');
    }
    $slutt = strpos($html, '</table>', $start);
    $tabell = substr($html, $start, $slutt - $start);

    $hode = substr($tabell, 0, (int) strpos($tabell, '</thead>'));
    preg_match_all('/<th[^>]*>\s*<span>\s*([^<]+?)\s*<\/span>/u', $hode, $m);
    $kolonner = array_map(fn ($x) => html_entity_decode($x, ENT_QUOTES | ENT_HTML5, 'UTF-8'), $m[1]);
    $plass = [];
    foreach (AZURE_REGIONER as $r) {
        $i = array_search($r, $kolonner, true);
        if ($i === false) {
            throw new RuntimeException("Fant ikke kolonnen «{$r}» på Azure-siden");
        }
        $plass[$r] = $i; // kolonne 0 er tjenestenavnet
    }

    $ut = array_fill_keys(AZURE_REGIONER, []);
    preg_match_all('/<tr(?![^>]*status-category)[^>]*>(.*?)<\/tr>/s', substr($tabell, strlen($hode)), $rader);
    foreach ($rader[1] as $rad) {
        preg_match_all('/<td[^>]*>(.*?)<\/td>/s', $rad, $celler);
        $celler = $celler[1];
        if (count($celler) < 2) {
            continue;
        }
        $navn = trim(html_entity_decode(strip_tags($celler[0]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        foreach ($plass as $region => $i) {
            $celle = $celler[$i] ?? '';
            $niva = match (true) {
                str_contains($celle, '#svg-check') => 'ok',
                str_contains($celle, '#svg-health-error') => 'nede',
                str_contains($celle, '#svg-health-warning') => 'redusert',
                str_contains($celle, '#svg-health-information') => 'melding',
                default => null, // ikke tilgjengelig i regionen
            };
            if ($niva !== null && $navn !== '') {
                $ut[$region][$navn] = $niva;
            }
        }
    }
    return $ut;
}

// ---------------------------------------------------------------- Sikt (RSS)

const SIKT_RETTET = '/(feilen er (nå )?rettet|er (nå )?(løst|rettet)|fungerer (nå )?(som )?normalt|kjører (nå )?(som )?normalt|er (nå )?tilgjengelig igjen|er oppe igjen)/iu';

function tolk_sikt(string $side): array
{
    $rss = hent_xml('https://www.sikt.no/driftsmeldinger/rss.xml');
    $hendelser = [];
    $status = 'ok';
    foreach ($rss->channel->item ?? [] as $it) {
        $tittel = trim((string) $it->title);
        $tekst = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string) $it->description), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        $start = utc((string) $it->pubDate);
        $rettet = (bool) preg_match(SIKT_RETTET, $tekst);
        // Uten «rettet» og eldre enn tre døgn regnes meldingen som avsluttet, men uten kjent sluttid.
        $gammel = $start !== null && strtotime($start . ' UTC') < time() - 3 * 86400;
        $statusH = ($rettet || $gammel) ? 'lost' : 'pagar';
        $niva = match (true) {
            (bool) preg_match('/planlagt|vedlikehold|oppgradering/iu', $tittel) => 'vedlikehold',
            (bool) preg_match('/utilgjengelig|nede|stengt|ute av drift/iu', $tittel) => 'delvis',
            (bool) preg_match('/problem|treg|ustabil|feil/iu', $tittel) => 'redusert',
            default => 'melding',
        };
        if ($statusH === 'pagar') {
            $status = verst($status, $niva);
        }
        $hendelser[] = [
            'ekstern_id' => (string) ($it->guid ?: $it->link),
            'type' => $niva === 'vedlikehold' ? 'vedlikehold' : 'melding',
            'tittel' => $tittel,
            'niva' => $niva,
            'status' => $statusH,
            'start' => $start,
            'slutt' => $rettet ? sikt_rettet_tid($tekst, (string) $it->pubDate) : null,
            'planlagt_til' => null,
            'url' => (string) ($it->link ?: $side),
            'komponenter' => [],
            'tekst' => mb_substr($tekst, 0, 1000),
        ];
    }
    $aktive = count(array_filter($hendelser, fn ($h) => $h['status'] === 'pagar'));
    return [
        'status' => $status,
        'oppsummering' => $aktive ? ['aktive_meldinger', $aktive] : ['ingen_aktive_meldinger'],
        'komponenter' => [],
        'antall' => 0,
        'hendelser' => $hendelser,
        'observert' => [],
        'lukk_manglende' => [],
    ];
}

/** «10:55 Feilen er rettet …» → klokkeslettet på publiseringsdagen (norsk tid), som UTC. */
function sikt_rettet_tid(string $tekst, string $publisert): ?string
{
    if (!preg_match('/(\d{1,2})[:.](\d{2})\s+(?:\S+\s+){0,8}?\S*(?:rettet|løst|normalt|igjen)/iu', $tekst, $m)) {
        return null;
    }
    try {
        $dag = (new DateTimeImmutable($publisert))->setTimezone(new DateTimeZone(TIDSSONE));
    } catch (Exception) {
        return null;
    }
    $t = $dag->setTime((int) $m[1], (int) $m[2]);
    if ($t < $dag) {
        $t = $t->modify('+1 day');
    }
    return $t->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}
