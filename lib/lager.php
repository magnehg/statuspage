<?php
declare(strict_types=1);

/*
 * Lagring på disk (JSON-filer), ingen database.
 *
 *   data/kilder.json            status per kilde
 *   data/hendelser/<kilde>.json hendelser per kilde, nøkkel = ekstern_id
 *   data/statuslogg/<kilde>.json sammenhengende statusperioder
 *   data/synk.json              de siste synkroniseringene
 *   data/http/<md5>.json/.body  ETag/Last-Modified og forrige svar per adresse
 *   data/synk.lock              låsen (flock)
 *
 * Bare synkroniseringen skriver, og den holder låsen. Filene skrives til en midlertidig fil
 * og flyttes på plass (rename), så den som leser, aldri ser en halvskrevet fil.
 */

function data_mappe(): string
{
    static $m = null;
    if ($m === null) {
        $m = rtrim((string) (konfig()['data_mappe'] ?? (__DIR__ . '/../data')), '/');
        foreach (['', '/hendelser', '/statuslogg', '/http'] as $under) {
            if (!is_dir($m . $under) && !mkdir($m . $under, 0775, true) && !is_dir($m . $under)) {
                throw new RuntimeException("Kan ikke lage mappen $m$under");
            }
        }
        if (!is_file("$m/.htaccess")) {
            @file_put_contents("$m/.htaccess", "Require all denied\n");
        }
    }
    return $m;
}

function les_json(string $navn): array
{
    $fil = data_mappe() . '/' . $navn;
    if (!is_file($fil)) {
        return [];
    }
    $d = json_decode((string) file_get_contents($fil), true);
    return is_array($d) ? $d : [];
}

function skriv_fil(string $navn, string $innhold): void
{
    $fil = data_mappe() . '/' . $navn;
    $tmp = $fil . '.' . bin2hex(random_bytes(4)) . '.tmp';
    if (file_put_contents($tmp, $innhold) === false || !rename($tmp, $fil)) {
        @unlink($tmp);
        throw new RuntimeException("Kunne ikke skrive $navn");
    }
}

function skriv_json(string $navn, array $data): void
{
    skriv_fil($navn, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
}

/** Gyldig filnavn for en kilde-id (bare id-er fra kilder() brukes). */
function kildefil(string $mappe, string $id): string
{
    if (!isset(kilder()[$id])) {
        throw new InvalidArgumentException("Ukjent kilde $id");
    }
    return "$mappe/$id.json";
}

function alle_hendelser(): array
{
    $ut = [];
    foreach (array_keys(kilder()) as $id) {
        foreach (les_json(kildefil('hendelser', $id)) as $h) {
            $ut[] = $h;
        }
    }
    return $ut;
}
