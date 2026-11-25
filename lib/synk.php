<?php
declare(strict_types=1);

/**
 * Synkroniserer alle kildene, men bare én gang om gangen.
 *
 * En fillås (flock på data/synk.lock) sørger for at bare én forespørsel henter. Er 500 inne på
 * siden når dataene blir eldre enn 5 minutter, henter den første, mens de andre straks får
 * de lagrede dataene. Etter låsen sjekkes alderen på nytt, så den som har ventet, ikke
 * henter det samme en gang til.
 *
 * @param string $utlost    'cron' eller 'besok'
 * @param bool   $full      Hent også historikken (Statuspage incidents.json), rydd opp.
 * @param int    $maksAlder Hent bare når de eldste dataene er eldre enn dette (sekunder). 0 = alltid.
 * @param int    $vent      Sekunder å vente på låsen (0 for besøk).
 * @return array{resultat:string, kilder?:array}
 */
function synk(string $utlost, bool $full, int $maksAlder, int $vent = 0): array
{
    if ($maksAlder > 0 && data_alder() < $maksAlder) {
        return ['resultat' => 'fersk'];
    }
    $lås = fopen(data_mappe() . '/synk.lock', 'c');
    if ($lås === false) {
        throw new RuntimeException('Kan ikke åpne låsfilen');
    }
    $frist = time() + $vent;
    while (!flock($lås, LOCK_EX | LOCK_NB)) {
        if (time() >= $frist) {
            fclose($lås);
            return ['resultat' => 'opptatt'];
        }
        usleep(250_000);
    }
    try {
        clearstatcache();
        if ($maksAlder > 0 && data_alder() < $maksAlder) {
            return ['resultat' => 'fersk'];
        }
        @set_time_limit(120);
        ignore_user_abort(true);
        $GLOBALS['http_antall'] = 0;
        $start = naa();

        $ut = [];
        foreach (array_keys(kilder()) as $id) {
            $t0 = microtime(true);
            try {
                lagre_resultat($id, tolk_kilde($id, $full));
                $ut[$id] = 'ok';
            } catch (Throwable $e) {
                lagre_feil($id, $e->getMessage());
                $ut[$id] = 'feil: ' . $e->getMessage();
            }
            $ut[$id] .= sprintf(' (%.1f s)', microtime(true) - $t0);
        }
        if ($full) {
            opprydding();
        }
        lagre_synk(['start' => $start, 'slutt' => naa(), 'utlost' => $utlost, 'foresporsler' => $GLOBALS['http_antall'], 'kilder' => $ut]);
        return ['resultat' => 'hentet', 'kilder' => $ut];
    } finally {
        flock($lås, LOCK_UN);
        fclose($lås);
    }
}

/** Sekunder siden den kilden som ble hentet for lengst siden. Uhentede kilder gir PHP_INT_MAX. */
function data_alder(): int
{
    $k = les_json('kilder.json');
    $eldst = PHP_INT_MAX;
    foreach (array_keys(kilder()) as $id) {
        if (empty($k[$id]['hentet'])) {
            return PHP_INT_MAX;
        }
        $eldst = min($eldst, strtotime($k[$id]['hentet'] . ' UTC'));
    }
    return time() - $eldst;
}
