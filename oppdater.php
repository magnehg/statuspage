<?php
declare(strict_types=1);
/*
 * Sjekker at datamappen finnes og er skrivbar, og oppgraderer datafilene ved behov.
 * Kan kjøres flere ganger.
 *   https://<din-side>/oppdater.php?nokkel=<cron-nøkkelen>   eller   php oppdater.php
 */
require __DIR__ . '/lib/start.php';

if (PHP_SAPI !== 'cli') {
    $hash = (string) (konfig()['cron_nokkel_hash'] ?? '');
    if ($hash === '' || !password_verify((string) ($_GET['nokkel'] ?? ''), $hash)) {
        http_response_code(403);
        exit("Feil nøkkel\n");
    }
    header('Content-Type: text/plain; charset=utf-8');
}

$mappe = data_mappe();
if (!is_writable($mappe)) {
    http_response_code(500);
    exit("Datamappen $mappe er ikke skrivbar\n");
}
$meta = les_json('meta.json');
$forrige = (int) ($meta['dataversjon'] ?? 0);
// Senere endringer i filformatet: if ($forrige < 2) { … }
skriv_json('meta.json', ['dataversjon' => DATAVERSJON, 'versjon' => VERSJON, 'oppdatert' => naa()]);
echo "Datamappe: $mappe\nDataversjon $forrige → " . DATAVERSJON . ' (versjon ' . VERSJON . ")\n";
