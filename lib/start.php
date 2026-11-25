<?php
declare(strict_types=1);

const VERSJON = '0.002';
const DATAVERSJON = 1;
/** Kildekoden (MIT). Vises nederst på siden; config 'kildekode' kan peke til en egen kopi. */
const KILDEKODE = 'https://github.com/magnehg/statuspage';
const TIDSSONE = 'Europe/Oslo';

// Alt lagres i UTC i datafilene. Visningen regnes om til norsk tid.
date_default_timezone_set('UTC');

require_once __DIR__ . '/kilder.php';
require_once __DIR__ . '/lager.php';
require_once __DIR__ . '/http.php';
require_once __DIR__ . '/tolk.php';
require_once __DIR__ . '/lagre.php';
require_once __DIR__ . '/synk.php';

const KONFIGFIL = __DIR__ . '/../cf/config.php';

/** Innstillingene i cf/config.php. Er siden ikke installert, sendes man til install.php. */
function konfig(?array $ny = null): array
{
    static $k = null;
    if ($ny !== null) {
        return $k = $ny;
    }
    if ($k === null) {
        if (!is_file(KONFIGFIL)) {
            if (PHP_SAPI === 'cli') {
                fwrite(STDERR, "Ikke installert ennå. Kjør: php install.php\n");
                exit(1);
            }
            header('Location: install.php', true, 302);
            exit;
        }
        $k = require KONFIGFIL;
    }
    return $k;
}

/** En innstilling fra cf/config.php, med standardverdi. */
function innst(string $nokkel, mixed $standard = null): mixed
{
    $v = konfig()[$nokkel] ?? null;
    return ($v === null || $v === '') ? $standard : $v;
}

/** Navnet på siden (tittel, fanen i nettleseren). */
function side_navn(): string
{
    return (string) innst('navn', 'Driftsstatus');
}

/** Linjen over overskriften. Standard: vertsnavnet siden kjører på. */
function side_undertittel(): string
{
    $vert = preg_replace('/[^a-z0-9.\-:]/i', '', (string) ($_SERVER['HTTP_HOST'] ?? ''));
    return (string) innst('undertittel', $vert !== '' ? $vert : side_navn());
}

/** Adressen til logoen og ikonet, relativt til siden eller absolutt (https://). */
function side_logo(): string
{
    return (string) innst('logo', 'assets/logo.svg');
}

function side_ikon(): string
{
    return (string) innst('ikon', side_logo());
}

/** User-Agent mot kildene. Sett gjerne en med adressen til siden din, så leverandørene ser hvem som henter. */
function brukeragent(): string
{
    return (string) innst('brukeragent', 'Driftsstatus/' . VERSJON . ' (henter offentlige statussider)');
}

function kildekode_url(): string
{
    $u = (string) innst('kildekode', KILDEKODE);
    return preg_match('~^https://~i', $u) ? $u : KILDEKODE;
}

/** Standardspråket for grensesnittet: 'nb' eller 'en' (config 'sprak'). */
function side_sprak(): string
{
    $s = (string) innst('sprak', 'nb');
    return in_array($s, ['nb', 'en'], true) ? $s : 'nb';
}

/** Skal den som besøker siden kunne bytte språk (flaggknappen)? Config 'sprak_valg', standard ja. */
function sprak_valg(): bool
{
    return (bool) innst('sprak_valg', true);
}

/** Tekstene i assets/tekster.json (samme fil som nettleseren bruker). */
function tekster(): array
{
    static $t = null;
    return $t ??= json_decode((string) file_get_contents(__DIR__ . '/../assets/tekster.json'), true);
}

/** Tekst på standardspråket, som t() i assets/app.js. */
function tt(string $nokkel, array $p = []): string
{
    $tab = tekster()[side_sprak()];
    $s = (($p['n'] ?? null) === 1 ? ($tab[$nokkel . '_1'] ?? null) : null) ?? $tab[$nokkel] ?? tekster()['nb'][$nokkel] ?? $nokkel;
    return preg_replace_callback('/\{(\w+)\}/', fn ($m) => isset($p[$m[1]]) ? (string) $p[$m[1]] : $m[0], $s);
}

/** Navnet på en kilde på standardspråket. */
function kilde_navn(array $k): string
{
    return side_sprak() === 'en' && !empty($k['navn_en']) ? $k['navn_en'] : $k['navn'];
}

/** Nå i UTC som 'Y-m-d H:i:s'. */
function naa(): string
{
    return gmdate('Y-m-d H:i:s');
}

/** Gjør en tidsangivelse (ISO 8601, RFC 2822 …) om til UTC 'Y-m-d H:i:s', eller null. */
function utc(?string $tid): ?string
{
    if ($tid === null || trim($tid) === '') {
        return null;
    }
    try {
        $d = new DateTimeImmutable($tid);
    } catch (Exception) {
        return null;
    }
    return $d->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}

/** UTC fra databasen til ISO 8601 med tidssone, til nettleseren. */
function iso(?string $utc): ?string
{
    if ($utc === null) {
        return null;
    }
    return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
}

function json_ut(array $data, int $kode = 200, int $maksAlder = 0): never
{
    http_response_code($kode);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: ' . ($maksAlder > 0 ? "public, max-age=$maksAlder" : 'no-store'));
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function h(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}
