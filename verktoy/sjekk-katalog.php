<?php
declare(strict_types=1);
/*
 * Sjekker at alle sidene i lib/katalog.json fortsatt svarer som Atlassian Statuspage.
 *   php verktoy/sjekk-katalog.php
 * Skriver én linje per side og en oppsummering; avslutter med kode 1 hvis noen ikke svarer.
 */
if (PHP_SAPI !== 'cli') {
    exit;
}
$k = json_decode((string) file_get_contents(__DIR__ . '/../lib/katalog.json'), true);
$doede = 0;
foreach ($k['kilder'] as $e) {
    $ch = curl_init(rtrim($e['side'], '/') . '/api/v2/summary.json');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_FOLLOWLOCATION => true, CURLOPT_USERAGENT => 'Driftsstatus-katalogsjekk']);
    $svar = curl_exec($ch);
    $kode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $d = is_string($svar) ? json_decode($svar, true) : null;
    $ok = $kode === 200 && isset($d['page']['id']);
    $antall = $ok ? count(array_filter($d['components'] ?? [], fn ($c) => empty($c['group']))) : 0;
    printf("%s %-14s %-28s %s\n", $ok ? 'ok  ' : 'DØD ', $e['id'], $e['navn'], $ok ? "$antall komponenter" : "HTTP $kode");
    $doede += $ok ? 0 : 1;
}
printf("\n%d av %d svarer.\n", count($k['kilder']) - $doede, count($k['kilder']));
exit($doede ? 1 : 0);
