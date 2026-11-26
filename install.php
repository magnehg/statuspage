<?php
declare(strict_types=1);
/*
 * Automatisk installering. Åpne https://<din-side>/install.php, eller kjør  php install.php
 *
 * Sjekker serveren (PHP-versjon, utvidelser, skrivbare mapper, låsing, utgående HTTPS og om
 * cf/, lib/ og data/ er sperret), lager en tilfeldig nøkkel for cron.php og oppdater.php,
 * skriver cf/config.php med hashen av nøkkelen, lager datamappen og henter dataene første gang.
 * Nøkkelen vises bare én gang. Når cf/config.php finnes, gjør skriptet ingenting.
 */
require __DIR__ . '/lib/start.php';
require __DIR__ . '/lib/skjema.php';

const MIN_PHP = '8.1.0';
const DATA_STANDARD = __DIR__ . '/data';

$cli = PHP_SAPI === 'cli';
$installert = is_file(KONFIGFIL);
if (!$installert) {
    konfig([]); // tom konfigurasjon til cf/config.php er skrevet
}

/** @return list<array{navn:string, ok:bool|null, tekst:string}>  ok: true = bestått, false = stopper, null = advarsel */
function sjekker(bool $cli): array
{
    $s = [];
    $s[] = ['navn' => 'PHP ' . MIN_PHP . ' eller nyere', 'ok' => version_compare(PHP_VERSION, MIN_PHP, '>='), 'tekst' => 'PHP ' . PHP_VERSION];
    foreach (['curl', 'json', 'mbstring', 'simplexml', 'libxml'] as $u) {
        $s[] = ['navn' => "PHP-utvidelsen $u", 'ok' => extension_loaded($u), 'tekst' => extension_loaded($u) ? 'finnes' : 'mangler – be webhotellet slå den på'];
    }

    $cf = realpath(dirname(KONFIGFIL)) ?: dirname(KONFIGFIL);
    $s[] = ['navn' => 'cf/ er skrivbar (for cf/config.php)', 'ok' => is_dir($cf) && is_writable($cf), 'tekst' => $cf];

    // Datamappen: lag den, skriv, flytt (rename) og lås, slik synkroniseringen gjør.
    $ok = true;
    $tekst = DATA_STANDARD;
    try {
        if (!is_dir(DATA_STANDARD) && !@mkdir(DATA_STANDARD, 0775, true)) {
            throw new RuntimeException('kan ikke lage mappen');
        }
        $tmp = DATA_STANDARD . '/.test-' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, 'ok') !== 2 || !@rename($tmp, $tmp . '.b')) {
            throw new RuntimeException('kan ikke skrive eller flytte filer');
        }
        $f = fopen($tmp . '.b', 'c');
        $laast = $f && flock($f, LOCK_EX | LOCK_NB);
        if ($f) {
            flock($f, LOCK_UN);
            fclose($f);
        }
        @unlink($tmp . '.b');
        if (!$laast) {
            throw new RuntimeException('fillås (flock) virker ikke');
        }
    } catch (Throwable $e) {
        $ok = false;
        $tekst .= ': ' . $e->getMessage();
    }
    $s[] = ['navn' => 'data/ kan lages, skrives og låses', 'ok' => $ok, 'tekst' => $tekst];

    // Utgående HTTPS mot en av kildene.
    $ok = null;
    $tekst = 'ikke testet (curl mangler)';
    if (extension_loaded('curl')) {
        $ch = curl_init('https://status.digdir.no/api/v2/summary.json');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_NOBODY => true, CURLOPT_USERAGENT => 'Driftsstatus-installering']);
        curl_exec($ch);
        $kode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $feil = curl_error($ch);
        curl_close($ch);
        $ok = $kode >= 200 && $kode < 400 ? true : null;
        $tekst = $ok ? "status.digdir.no svarte HTTP $kode" : 'fikk ikke kontakt (' . ($feil ?: "HTTP $kode") . '); siden virker ikke uten utgående HTTPS';
    }
    $s[] = ['navn' => 'Serveren når kildene (utgående HTTPS)', 'ok' => $ok, 'tekst' => $tekst];

    // Er de interne mappene sperret? Prøv å hente en fil i cf/ over HTTP.
    if (!$cli && extension_loaded('curl') && !empty($_SERVER['HTTP_HOST'])) {
        $url = basis_url() . 'cf/config.eksempel.php';
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5]);
        curl_exec($ch);
        $kode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        $s[] = [
            'navn' => 'cf/, lib/ og data/ er sperret for nettlesere',
            'ok' => $kode === 0 ? null : ($kode >= 400 ? true : null),
            'tekst' => $kode === 0 ? 'kunne ikke teste' : ($kode >= 400 ? "cf/ svarer HTTP $kode" : "cf/ svarer HTTP $kode – serveren bruker ikke .htaccess; sperr cf/, lib/ og data/ på annen måte, eller legg data_mappe utenfor webroten"),
        ];
    }
    return $s;
}

function basis_url(): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    $vert = preg_replace('/[^a-z0-9.\-:\[\]]/i', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    $mappe = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
    return ($https ? 'https' : 'http') . "://$vert$mappe/";
}

/** Skriver cf/config.php med hashen av en ny nøkkel. Gir nøkkelen. */
function installer(array $kilder): array
{
    $nokkel = bin2hex(random_bytes(16));
    $hash = password_hash($nokkel, PASSWORD_DEFAULT);
    $vert = PHP_SAPI === 'cli' ? '' : preg_replace('/[^a-z0-9.\-]/i', '', (string) ($_SERVER['HTTP_HOST'] ?? ''));
    $ua = 'Driftsstatus/' . VERSJON . ($vert !== '' ? " (https://$vert)" : '');
    $innhold = "<?php\n// Laget av install.php " . gmdate('Y-m-d H:i') . " UTC. Endre fritt; se cf/config.eksempel.php for alle innstillingene.\n"
        . "// Denne filen skal ikke i git eller zip.\nreturn [\n"
        . "    // Hashen av nøkkelen til cron.php og oppdater.php. Ny nøkkel: slett denne filen og kjør install.php igjen,\n"
        . "    // eller lag en hash med  php -r 'echo password_hash(\"ny-nøkkel\", PASSWORD_DEFAULT);'\n"
        . "    'cron_nokkel_hash' => " . var_export($hash, true) . ",\n\n"
        . "    'navn' => 'Driftsstatus',\n"
        . "    'undertittel' => '',\n"
        . "    'logo' => '',\n"
        . "    'ikon' => '',\n"
        . "    'brukeragent' => " . var_export($ua, true) . ",\n"
        . "    'sprak' => 'nb',\n"
        . "    'sprak_valg' => true,\n"
        . "    'maks_alder' => 300,\n"
        . "    // Tjenestene som vises, i rekkefølge. Alle som kan velges: ALLE_KILDER i lib/kilder.php og lib/katalog.json.\n"
        . "    'kilder' => " . var_export($kilder, true) . ",\n"
        . "];\n";
    // Skriv bare hvis filen ikke finnes (x), så to samtidige installeringer ikke overskriver hverandre.
    $f = @fopen(KONFIGFIL, 'x');
    if ($f === false) {
        throw new RuntimeException('cf/config.php finnes allerede eller kan ikke skrives');
    }
    fwrite($f, $innhold);
    fclose($f);
    @chmod(KONFIGFIL, 0640);
    konfig(require KONFIGFIL);

    $mappe = data_mappe();
    skriv_json('meta.json', ['dataversjon' => DATAVERSJON, 'versjon' => VERSJON, 'installert' => naa()]);
    $synk = synk('cron', true, 0, 30);
    return ['nokkel' => $nokkel, 'mappe' => $mappe, 'synk' => $synk];
}

// ------------------------------------------------------------------ kommandolinjen
if ($cli) {
    if ($installert) {
        echo "Allerede installert (cf/config.php finnes). Ny nøkkel: slett cf/config.php og kjør på nytt.\n";
        exit(0);
    }
    $stopp = false;
    foreach (sjekker(true) as $s) {
        printf("%s %s – %s\n", $s['ok'] === true ? '[ok]  ' : ($s['ok'] === false ? '[FEIL]' : '[obs] '), $s['navn'], $s['tekst']);
        $stopp = $stopp || $s['ok'] === false;
    }
    if ($stopp) {
        echo "\nRett feilene over og kjør igjen.\n";
        exit(1);
    }
    $valgt = standard_kilder();
    foreach ($argv as $a) {
        if (str_starts_with($a, '--kilder=')) {
            $valgt = valgte_kilder(explode(',', substr($a, 9)));
        }
    }
    $r = installer($valgt);
    echo "\nInstallert. Datamappe: {$r['mappe']}\nFørste henting: {$r['synk']['resultat']}\n";
    foreach ($r['synk']['kilder'] ?? [] as $id => $res) {
        echo "  $id: $res\n";
    }
    echo "\nNøkkel (vises bare nå): {$r['nokkel']}\n";
    echo "Cron hver time:  7 * * * * curl -fsS \"https://<din-side>/cron.php?nokkel={$r['nokkel']}\"\n";
    echo "eller:           7 * * * * php " . __DIR__ . "/cron.php\n";
    exit(0);
}

// ------------------------------------------------------------------ nettleseren
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');
$resultat = null;
$feil = null;
$sjekk = $installert ? [] : sjekker(false);
$kan = !$installert && !array_filter($sjekk, fn ($s) => $s['ok'] === false);
if ($kan && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    try {
        $resultat = installer(valgte_kilder((array) ($_POST['kilder'] ?? [])));
        $installert = true;
    } catch (Throwable $e) {
        $feil = $e->getMessage();
    }
}
$base = basis_url();
?><!doctype html>
<html lang="nb" data-tema="mork">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Installering – Driftsstatus</title>
<link rel="stylesheet" href="assets/app.css?v=<?= rawurlencode(VERSJON) ?>">
<script src="assets/tema.js?v=<?= rawurlencode(VERSJON) ?>"></script>
</head>
<body>
<div class="side installer">
  <header class="topp">
    <span class="merke"><img src="assets/logo.svg" alt="" width="76" height="76"><span><span class="overtittel">Driftsstatus <?= h(VERSJON) ?></span><h1>Installering</h1></span></span>
  </header>
<?php if ($resultat): ?>
  <section class="boks ok">
    <h2>Ferdig installert</h2>
    <p>Datamappen er <code><?= h($resultat['mappe']) ?></code>, og den første hentingen gikk <?= $resultat['synk']['resultat'] === 'hentet' ? 'gjennom' : 'ikke (' . h($resultat['synk']['resultat']) . ')' ?>.</p>
    <ul class="sjekker">
<?php foreach ($resultat['synk']['kilder'] ?? [] as $id => $res): ?>
      <li class="<?= str_starts_with($res, 'ok') ? 'bestatt' : 'advarsel' ?>"><strong><?= h(kilder()[$id]['navn'] ?? $id) ?></strong> <?= h($res) ?></li>
<?php endforeach; ?>
    </ul>
  </section>
  <section class="boks">
    <h2>Nøkkelen – skriv den ned nå</h2>
    <p>Den vises bare denne ene gangen. Bare hashen er lagret i <code>cf/config.php</code>. Nøkkelen brukes til <code>cron.php</code> og <code>oppdater.php</code>.</p>
    <p class="nokkel"><code><?= h($resultat['nokkel']) ?></code></p>
    <h3>Cron hver time</h3>
    <pre><code>7 * * * * curl -fsS "<?= h($base) ?>cron.php?nokkel=<?= h($resultat['nokkel']) ?>"</code></pre>
    <p>Har du tilgang til crontab på serveren, kan du også bruke <code>php <?= h(__DIR__) ?>/cron.php</code> uten nøkkel.</p>
    <p>Navn, logo, språk og hvilke tjenester som vises, endrer du i <code>cf/config.php</code> (se <code>cf/config.eksempel.php</code>).</p>
    <p><a class="knapp" href="./">Gå til statussiden</a></p>
  </section>
<?php elseif ($installert): ?>
  <section class="boks">
    <h2>Allerede installert</h2>
    <p><code>cf/config.php</code> finnes, så installeringen gjør ingenting. Vil du ha en ny nøkkel, sletter du <code>cf/config.php</code> og åpner denne siden igjen.</p>
    <p><a class="knapp" href="./">Gå til statussiden</a></p>
  </section>
<?php else: ?>
  <section class="boks">
    <h2>Sjekk av serveren</h2>
    <ul class="sjekker">
<?php foreach ($sjekk as $s): ?>
      <li class="<?= $s['ok'] === true ? 'bestatt' : ($s['ok'] === false ? 'feilet' : 'advarsel') ?>"><strong><?= h($s['navn']) ?></strong> <?= h($s['tekst']) ?></li>
<?php endforeach; ?>
    </ul>
<?php if ($feil): ?>
    <p class="feiltekst"><?= h($feil) ?></p>
<?php endif; ?>
<?php if ($kan): ?>
    <p>Installeringen lager en tilfeldig nøkkel, skriver <code>cf/config.php</code>, lager datamappen og henter status fra alle kildene første gang (10–20 sekunder).</p>
    <form method="post" class="installskjema">
      <h2>Hvilke tjenester skal vises?</h2>
      <p>De innebygde er valgt. Du kan endre utvalget senere i <code>cf/config.php</code> (<code>kilder</code>).</p>
      <p class="velgverktoy"><label class="skjult" for="kildeSok">Søk</label><input type="search" id="kildeSok" placeholder="Søk etter tjeneste …" autocomplete="off"> <span id="antallValgt" aria-live="polite"></span></p>
<?= kildevalg_html(standard_kilder()) ?>
      <p><button class="knapp" type="submit">Installer</button></p>
    </form>
    <script src="assets/install.js?v=<?= rawurlencode(VERSJON) ?>" defer></script>
<?php else: ?>
    <p class="feiltekst">Rett det som er merket med rødt, og last siden på nytt.</p>
<?php endif; ?>
  </section>
<?php endif; ?>
</div>
</body>
</html>
