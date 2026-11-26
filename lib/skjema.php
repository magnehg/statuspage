<?php
declare(strict_types=1);

/** Avkrysningsboksene for tjenestene, gruppert etter kategori (installeringen og innstillingene). */
function kildevalg_html(array $valgt): string
{
    $grupper = [];
    foreach (mulige_kilder() as $id => $k) {
        $grupper[$k['kategori'] ?? 'annet'][$id] = $k;
    }
    $kat = katalog()['kategorier'] + ['egne' => ['nb' => 'Egne kilder']];
    $valgt = array_flip($valgt);
    $ut = '';
    foreach ($kat as $kode => $tekst) {
        if (empty($grupper[$kode])) {
            continue;
        }
        uasort($grupper[$kode], fn ($a, $b) => strcmp(mb_strtolower($a['navn']), mb_strtolower($b['navn'])));
        $ut .= '<fieldset class="kildegruppe"><legend>' . h($tekst['nb'] ?? $kode) . "</legend>\n";
        foreach ($grupper[$kode] as $id => $k) {
            $ut .= '<label class="kildevalg"><input type="checkbox" name="kilder[]" value="' . h((string) $id) . '"'
                . (isset($valgt[$id]) ? ' checked' : '') . '> <span>' . h($k['navn']) . '</span> <small>'
                . h(preg_replace('~^https://|/$~', '', $k['side'])) . "</small></label>\n";
        }
        $ut .= "</fieldset>\n";
    }
    return $ut;
}

/** Gyldige kilde-id-er av det som er valgt (i rekkefølgen i mulige_kilder()), eller standardutvalget. */
function valgte_kilder(array $valgt): array
{
    $valgt = array_flip(array_map('strval', $valgt));
    $ut = array_values(array_filter(array_keys(mulige_kilder()), fn ($id) => isset($valgt[$id])));
    return $ut ?: standard_kilder();
}
