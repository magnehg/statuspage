<?php
declare(strict_types=1);
/*
 * Kalles av crontab / en ekstern tjeneste hver time:
 *   https://<din-side>/cron.php?nokkel=<nøkkel>
 * Henter alle kildene med historikk (uansett hvor ferske dataene er) og rydder opp.
 * Venter opptil 60 sekunder hvis et besøk holder på å hente.
 * Fra kommandolinjen: php cron.php
 */
require __DIR__ . '/lib/start.php';

if (PHP_SAPI !== 'cli') {
    $hash = (string) (konfig()['cron_nokkel_hash'] ?? '');
    if ($hash === '' || !password_verify((string) ($_GET['nokkel'] ?? ''), $hash)) {
        http_response_code(403);
        exit("Feil nøkkel\n");
    }
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
}

$t0 = microtime(true);
$r = synk('cron', true, 0, 60);
printf("%s: %s (%.1f s, %d forespørsler)\n", gmdate('Y-m-d H:i:s') . ' UTC', $r['resultat'], microtime(true) - $t0, $GLOBALS['http_antall']);
foreach ($r['kilder'] ?? [] as $id => $res) {
    echo "  $id: $res\n";
}
if (($r['resultat'] ?? '') === 'opptatt') {
    http_response_code(503);
}
