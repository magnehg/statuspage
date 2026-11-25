<?php
declare(strict_types=1);
require __DIR__ . '/lib/start.php';
$v = rawurlencode(VERSJON);
$logo = side_logo();
$ikon = side_ikon();
$sprak = side_sprak();
$antall = count(kilder());
header('Cache-Control: no-cache');
$svg = 'viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"';
?><!doctype html>
<html lang="<?= $sprak ?>" data-sprak="<?= $sprak ?>" data-tema="mork">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h(side_navn()) ?></title>
<meta name="description" content="<?= h(side_navn() . ': ' . implode(', ', array_map('kilde_navn', kilder()))) ?>">
<link rel="icon" href="<?= h($ikon) ?>">
<link rel="stylesheet" href="assets/app.css?v=<?= $v ?>">
<script src="assets/tema.js?v=<?= $v ?>"></script>
<script src="assets/app.js?v=<?= $v ?>" defer></script>
<script type="application/json" id="tekster"><?= json_encode(tekster(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
</head>
<body>
<div class="side">
  <header class="topp">
    <a class="merke" href="./">
      <img src="<?= h($logo) ?>" alt="" width="76" height="76">
      <span>
        <span class="overtittel"><?= h(side_undertittel()) ?> · <span data-t="leverandorer" data-n="<?= $antall ?>"><?= h(tt('leverandorer', ['n' => $antall])) ?></span></span>
        <h1 id="overskrift"><?= h(side_navn()) ?></h1>
      </span>
    </a>
    <div class="verktoy">
      <div class="faner" role="tablist" aria-label="<?= h(tt('visning')) ?>" data-t-aria="visning">
        <button type="button" role="tab" id="fane-na" aria-controls="panel-na" aria-selected="true">
          <svg <?= $svg ?>><path d="M3 12h4l3-8 4 16 3-8h4"/></svg><span data-t="fane_na"><?= h(tt('fane_na')) ?></span></button>
        <button type="button" role="tab" id="fane-tid" aria-controls="panel-tid" aria-selected="false" tabindex="-1">
          <svg <?= $svg ?>><rect x="3" y="4" width="18" height="17" rx="2"/><path d="M3 9h18M8 2v4M16 2v4M12 13v3l2 1"/></svg><span data-t="fane_tid"><?= h(tt('fane_tid')) ?></span></button>
      </div>
      <div class="temaer" role="group" aria-label="<?= h(tt('fargetema')) ?>" data-t-aria="fargetema">
        <button type="button" data-tema-valg="mork" aria-pressed="false" aria-label="<?= h(tt('tema_mork')) ?>" data-t-aria="tema_mork" title="<?= h(tt('tema_mork_kort')) ?>" data-t-title="tema_mork_kort"><svg <?= $svg ?>><path d="M20 14.5A8 8 0 1 1 9.5 4a6.5 6.5 0 0 0 10.5 10.5z"/></svg></button>
        <button type="button" data-tema-valg="varm" aria-pressed="false" aria-label="<?= h(tt('tema_varm')) ?>" data-t-aria="tema_varm" title="<?= h(tt('tema_varm_kort')) ?>" data-t-title="tema_varm_kort"><svg <?= $svg ?>><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg></button>
        <button type="button" data-tema-valg="blagronn" aria-pressed="false" aria-label="<?= h(tt('tema_blagronn')) ?>" data-t-aria="tema_blagronn" title="<?= h(tt('tema_blagronn_kort')) ?>" data-t-title="tema_blagronn_kort"><svg <?= $svg ?>><path d="M2 9c2.5-2 5-2 7.5 0s5 2 7.5 0 3.5-1.5 5-1"/><path d="M2 15c2.5-2 5-2 7.5 0s5 2 7.5 0 3.5-1.5 5-1"/></svg></button>
<?php if (sprak_valg()): ?>
        <button type="button" id="sprakKnapp" class="sprak" data-til="<?= $sprak === 'nb' ? 'en' : 'nb' ?>" aria-label="<?= h(tt('sprak_knapp')) ?>" data-t-aria="sprak_knapp" title="<?= h(tt('sprak_knapp')) ?>" data-t-title="sprak_knapp">
          <svg class="flagg-en" viewBox="0 0 60 40" aria-hidden="true"><clipPath id="fe"><rect width="60" height="40" rx="6"/></clipPath><g clip-path="url(#fe)"><rect width="60" height="40" fill="#012169"/><path d="M0 0l60 40M60 0L0 40" stroke="#fff" stroke-width="8"/><path d="M0 0l60 40M60 0L0 40" stroke="#C8102E" stroke-width="3"/><path d="M30 0v40M0 20h60" stroke="#fff" stroke-width="12"/><path d="M30 0v40M0 20h60" stroke="#C8102E" stroke-width="7"/></g></svg>
          <svg class="flagg-nb" viewBox="0 0 60 40" aria-hidden="true"><clipPath id="fn"><rect width="60" height="40" rx="6"/></clipPath><g clip-path="url(#fn)"><rect width="60" height="40" fill="#BA0C2F"/><path d="M22 0v40M0 20h60" stroke="#fff" stroke-width="10"/><path d="M22 0v40M0 20h60" stroke="#00205B" stroke-width="5"/></g></svg>
        </button>
<?php endif; ?>
      </div>
    </div>
  </header>

  <p class="skjult" id="kunngjoring" role="status" aria-live="polite"></p>
  <div class="oppsummering" id="oppsummering">
    <span class="hentet" id="hentet" data-t="henter_status"><?= h(tt('henter_status')) ?></span>
  </div>

  <main>
    <section id="panel-na" role="tabpanel" aria-labelledby="fane-na">
      <div class="kort" id="kort"></div>
      <div class="seksjon">
        <h2 class="liten" data-t="planlagt_14"><?= h(tt('planlagt_14')) ?></h2>
        <ul class="liste" id="kommende"></ul>
      </div>
    </section>

    <section id="panel-tid" role="tabpanel" aria-labelledby="fane-tid" hidden>
      <div class="stabel">
        <ul class="forklaring">
<?php foreach (['nede', 'delvis', 'redusert', 'vedlikehold', 'melding'] as $n): ?>
          <li><i class="f-<?= $n ?>"></i><span data-t="niva.<?= $n ?>"><?= h(tt("niva.$n")) ?></span></li>
<?php endforeach; ?>
        </ul>
        <div class="kalenderramme" id="kalenderramme"><p class="tom luft" data-t="henter_tidslinje"><?= h(tt('henter_tidslinje')) ?></p></div>
        <div class="dagdetaljer" id="dagdetaljer" hidden></div>
        <p class="notis" data-t="tid_notis"><?= h(tt('tid_notis')) ?></p>
      </div>
    </section>
  </main>

  <noscript><p><?= h(tt('noscript')) ?> <?php
    $l = [];
    foreach (kilder() as $k) {
        $l[] = '<a href="' . h($k['side']) . '">' . h(kilde_navn($k)) . '</a>';
    }
    echo implode(', ', $l);
  ?>.</p></noscript>

  <footer class="bunn">
    <span data-t="bunn"><?= h(tt('bunn')) ?></span>
    <span data-t="versjon" data-v="<?= h(VERSJON) ?>"><?= h(tt('versjon', ['v' => VERSJON])) ?></span>
    <span class="kildekode"><a href="<?= h(kildekode_url()) ?>" rel="noopener"><svg viewBox="0 0 16 16" aria-hidden="true"><path fill="currentColor" d="M8 0a8 8 0 0 0-2.53 15.59c.4.07.55-.17.55-.38v-1.33c-2.23.49-2.7-1.07-2.7-1.07-.36-.92-.89-1.17-.89-1.17-.73-.5.06-.49.06-.49.8.06 1.23.83 1.23.83.72 1.22 1.87.87 2.33.66.07-.52.28-.87.5-1.07-1.78-.2-3.65-.89-3.65-3.96 0-.88.31-1.6.83-2.16-.08-.2-.36-1.02.08-2.12 0 0 .67-.22 2.2.82a7.6 7.6 0 0 1 4 0c1.53-1.04 2.2-.82 2.2-.82.44 1.1.16 1.92.08 2.12.52.56.83 1.28.83 2.16 0 3.08-1.87 3.76-3.66 3.95.29.25.54.73.54 1.48v2.2c0 .21.15.46.55.38A8 8 0 0 0 8 0z"/></svg><span data-t="kildekode"><?= h(tt('kildekode')) ?></span></a></span>
  </footer>
</div>
</body>
</html>
