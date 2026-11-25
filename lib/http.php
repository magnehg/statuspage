<?php
declare(strict_types=1);

/** Antall forespørsler i denne synkroniseringen (til loggen). */
$GLOBALS['http_antall'] = 0;

/**
 * Henter en adresse med betinget forespørsel (ETag / Last-Modified).
 * Svarer kilden 304, gis forrige innhold fra data/http/. Innhold over $lagreMaks byte
 * lagres ikke (Azure-siden), og da sendes ingen betingelser.
 *
 * @return array{kode:int, innhold:string, endret:bool}
 */
function hent(string $url, int $lagreMaks = 2_000_000): array
{
    $navn = 'http/' . md5($url);
    $c = les_json("$navn.json");
    $body = data_mappe() . "/$navn.body";
    $harForrige = $c && is_file($body);

    $hoder = ['Accept: application/json, application/rss+xml, application/xml, text/html;q=0.8'];
    if ($harForrige) {
        if (!empty($c['etag'])) {
            $hoder[] = 'If-None-Match: ' . $c['etag'];
        }
        if (!empty($c['sist_endret'])) {
            $hoder[] = 'If-Modified-Since: ' . $c['sist_endret'];
        }
    }

    $svarHoder = [];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_USERAGENT => brukeragent(),
        CURLOPT_HTTPHEADER => $hoder,
        CURLOPT_ENCODING => '',
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_HEADERFUNCTION => function ($ch, string $linje) use (&$svarHoder): int {
            $d = explode(':', $linje, 2);
            if (count($d) === 2) {
                $svarHoder[strtolower(trim($d[0]))] = trim($d[1]);
            }
            return strlen($linje);
        },
    ]);
    $innhold = curl_exec($ch);
    $kode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $feil = curl_error($ch);
    curl_close($ch);
    $GLOBALS['http_antall']++;

    if ($innhold === false) {
        throw new RuntimeException("Fikk ikke kontakt med $url: $feil");
    }
    if ($kode === 304 && $harForrige) {
        return ['kode' => 304, 'innhold' => (string) file_get_contents($body), 'endret' => false];
    }
    if ($kode !== 200) {
        throw new RuntimeException("$url svarte HTTP $kode");
    }

    if (strlen($innhold) <= $lagreMaks && (isset($svarHoder['etag']) || isset($svarHoder['last-modified']))) {
        skriv_fil("$navn.body", $innhold);
        skriv_json("$navn.json", ['url' => $url, 'etag' => $svarHoder['etag'] ?? null, 'sist_endret' => $svarHoder['last-modified'] ?? null, 'hentet' => naa()]);
    }
    return ['kode' => 200, 'innhold' => $innhold, 'endret' => true];
}

function hent_json(string $url): array
{
    $d = json_decode(hent($url)['innhold'], true);
    if (!is_array($d)) {
        throw new RuntimeException("$url ga ikke gyldig JSON");
    }
    return $d;
}

function hent_xml(string $url): SimpleXMLElement
{
    $s = hent($url);
    $forrige = libxml_use_internal_errors(true);
    $x = simplexml_load_string($s['innhold'], SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
    libxml_use_internal_errors($forrige);
    if ($x === false) {
        throw new RuntimeException("$url ga ikke gyldig XML");
    }
    return $x;
}
