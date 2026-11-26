<?php
declare(strict_types=1);
/*
 * Innstillinger: hvilke tjenester som vises, navnet på siden, språk og hvor lenge en driftsmelding
 * regnes som avvik. Endringene lagres i data/innstillinger.json og går foran cf/config.php.
 * Krever nøkkelen fra installeringen ved hver lagring (ingen innlogging, ingen informasjonskapsler).
 */
require __DIR__ . '/lib/start.php';
require __DIR__ . '/lib/skjema.php';

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

$melding = null;
$feil = null;
$hentet = null;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!riktig_nokkel((string) ($_POST['nokkel'] ?? ''))) {
        sleep(2); // gjør gjetting tregt
        $feil = 'Feil nøkkel. Ingenting er lagret.';
    } else {
        $for = array_keys(kilder());
        $ny = [
            'kilder' => valgte_kilder((array) ($_POST['kilder'] ?? [])),
            'navn' => mb_substr(trim((string) ($_POST['navn'] ?? '')), 0, 80),
            'undertittel' => mb_substr(trim((string) ($_POST['undertittel'] ?? '')), 0, 80),
            'sprak' => in_array($_POST['sprak'] ?? '', ['nb', 'en'], true) ? $_POST['sprak'] : 'nb',
            'sprak_valg' => !empty($_POST['sprak_valg']),
            'melding_dager' => max(1, min(90, (int) ($_POST['melding_dager'] ?? 7))),
        ];
        skriv_json('innstillinger.json', $ny + ['endret' => naa()]);
        // Les innstillingene på nytt i denne forespørselen.
        header('Location: innstillinger.php?lagret=' . (array_diff($ny['kilder'], $for) ? 'ny' : '1'), true, 303);
        exit;
    }
}

if (isset($_GET['lagret'])) {
    $melding = 'Lagret.';
    if ($_GET['lagret'] === 'ny') {
        // Nye tjenester har ingen data ennå: hent med en gang (venter høyst 30 sekunder på låsen).
        $hentet = synk('besok', false, 0, 30);
        $melding = 'Lagret, og de nye tjenestene er hentet.';
    }
}

$valgt = array_keys(kilder());
$navn = (string) innst('navn', '');
?><!doctype html>
<html lang="nb" data-tema="mork">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Innstillinger – <?= h(side_navn()) ?></title>
<link rel="icon" href="<?= h(side_ikon()) ?>">
<link rel="stylesheet" href="assets/app.css?v=<?= rawurlencode(VERSJON) ?>">
<script src="assets/tema.js?v=<?= rawurlencode(VERSJON) ?>"></script>
<script src="assets/install.js?v=<?= rawurlencode(VERSJON) ?>" defer></script>
</head>
<body>
<div class="side installer">
  <header class="topp">
    <a class="merke" href="./"><img src="<?= h(side_logo()) ?>" alt="" width="76" height="76"><span><span class="overtittel"><?= h(side_navn()) ?> <?= h(VERSJON) ?></span><h1>Innstillinger</h1></span></a>
  </header>
<?php if ($melding): ?>
  <section class="boks ok" role="status">
    <h2><?= h($melding) ?></h2>
<?php if ($hentet && !empty($hentet['kilder'])): ?>
    <ul class="sjekker">
<?php foreach ($hentet['kilder'] as $id => $res): ?>
      <li class="<?= str_starts_with($res, 'ok') ? 'bestatt' : 'advarsel' ?>"><strong><?= h(kilder()[$id]['navn'] ?? $id) ?></strong> <?= h($res) ?></li>
<?php endforeach; ?>
    </ul>
<?php endif; ?>
    <p><a class="knapp" href="./">Gå til statussiden</a></p>
  </section>
<?php endif; ?>
<?php if ($feil): ?>
  <p class="boks feiltekst" role="alert"><?= h($feil) ?></p>
<?php endif; ?>
  <form method="post" class="boks innstillingsskjema">
    <h2>Siden</h2>
    <div class="felter">
      <label>Navn<input type="text" name="navn" maxlength="80" value="<?= h($navn) ?>" placeholder="Driftsstatus"></label>
      <label>Linjen over overskriften<input type="text" name="undertittel" maxlength="80" value="<?= h((string) innst('undertittel', '')) ?>" placeholder="Tom = vertsnavnet"></label>
      <label>Standardspråk
        <select name="sprak"><option value="nb"<?= side_sprak() === 'nb' ? ' selected' : '' ?>>Norsk bokmål</option><option value="en"<?= side_sprak() === 'en' ? ' selected' : '' ?>>English</option></select></label>
      <label>Driftsmeldinger regnes som avvik i (dager)<input type="number" name="melding_dager" min="1" max="90" value="<?= melding_dager() ?>"></label>
    </div>
    <label class="avkryss"><input type="checkbox" name="sprak_valg" value="1"<?= sprak_valg() ? ' checked' : '' ?>> Vis flaggknappen så besøkende kan bytte språk</label>
    <p class="notis">Driftsmeldinger (Altinn) som er eldre enn dette, vises som grønne, med datoen for den siste meldingen.</p>

    <h2>Tjenester</h2>
    <p class="velgverktoy"><label class="skjult" for="kildeSok">Søk</label><input type="search" id="kildeSok" placeholder="Søk etter tjeneste …" autocomplete="off"> <span id="antallValgt" aria-live="polite"></span></p>
<?= kildevalg_html($valgt) ?>
    <p class="notis">Egne kilder og logo settes i <code>cf/config.php</code>.</p>

    <h2>Lagre</h2>
    <div class="felter">
      <label>Nøkkel fra installeringen<input type="password" name="nokkel" required autocomplete="current-password"></label>
    </div>
    <p><button class="knapp" type="submit">Lagre</button></p>
  </form>
</div>
</body>
</html>
