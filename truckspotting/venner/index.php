<?php
// Modtager til vennekoder i Truckspotting. Se docs/plan/vennekoder-2026-11-13.md og server/README.md.
// Ligger på https://qdigital.dk/truckspotting/venner/ (public_html/truckspotting/venner/index.php).
// Én fil pr. kode i truckspotting-data/venner/ uden for public_html. Filnavnet er en hash af koden.
// Kun tal fra skemaet herunder gemmes. Ingen fri tekst, ingen billeder, ingen steder, ingen logning af koder.

declare(strict_types=1);

/** Grænser pr. afsender (saltet hash af IP adressen) og i alt. Kan sænkes i testen. */
define('MAX_PUT_HOUR', (int) (getenv('VENNER_MAX_PUT_HOUR') ?: 60));
define('MAX_GET_HOUR', (int) (getenv('VENNER_MAX_GET_HOUR') ?: 120));
define('MAX_NEW_DAY', (int) (getenv('VENNER_MAX_NEW_DAY') ?: 5));
define('MAX_ALL_NEW_DAY', (int) (getenv('VENNER_MAX_ALL_NEW_DAY') ?: 2000));
define('MAX_FILES', (int) (getenv('VENNER_MAX_FILES') ?: 100000));
/** Profiler, der ikke er opdateret i så mange sekunder, slettes. 12 måneder. */
define('MAX_AGE', (int) (getenv('VENNER_MAX_AGE') ?: 365 * 86400));
/** Når loftet over filer er nået, slettes profiler, der aldrig er opdateret efter oprettelsen og er så gamle. */
define('UNUSED_AGE', (int) (getenv('VENNER_UNUSED_AGE') ?: 30 * 86400));
/** Højst så mange afsendere i én spand i rate.json, så filen ikke kan vokse uden loft. */
define('MAX_SENDERS', (int) (getenv('VENNER_MAX_SENDERS') ?: 5000));
const MAX_IDS = 50;
const MAX_BODY = 4096;

/** Skemaet for en profil: felt => [mindst, højst]. finds kontrolleres for sig. */
const SCHEMA = [
    'v' => [1, 1],
    'level' => [1, 999],
    'xp' => [0, 99_999_999],
    'spots' => [0, 999_999],
    'models' => [0, 9999],
    'custom' => [0, 9999],
    'countries' => [0, 300],
    'streak' => [0, 99_999],
    'best' => [0, 99_999],
    'medals' => [0, 999],
    'week' => [0, 1_000_000],
    'weekSpots' => [0, 9999],
];

function reply(int $code, array $body): never {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($body);
    exit;
}

/** Mappen uden for public_html. Den oprettes aldrig her, så en forkert sti ikke lægger filerne åbent. */
function data_dir(): string {
    $root = getenv('TRUCKSPOTTING_DATA') ?: __DIR__ . '/../../../truckspotting-data';
    $dir = $root . '/venner';
    if (!is_dir($dir)) reply(500, ['status' => 'store']);
    return $dir;
}

/** Saltet bør oprettes ved udgivelsen. Mangler det, laves det med "x", så to samtidige kald ikke laver hver sit. */
function salt(string $dir): string {
    $file = $dir . '/salt.txt';
    if (!is_file($file)) {
        $fp = @fopen($file, 'x');
        if ($fp !== false) {
            fwrite($fp, bin2hex(random_bytes(16)));
            fclose($fp);
            @chmod($file, 0600);
        }
    }
    for ($i = 0; $i < 50; $i++) {
        $salt = trim((string) @file_get_contents($file));
        if ($salt !== '') return $salt;
        usleep(10000);
    }
    reply(500, ['status' => 'store']);
}

/**
 * Afsenderen som en kort saltet hash. For IPv6 bruges kun de første 64 bit, så én afsender ikke kan skifte
 * adresse frit inden for sit eget net.
 */
function sender_key(string $dir, string $ip): string {
    $bin = @inet_pton($ip);
    if ($bin !== false && strlen($bin) === 16) $ip = bin2hex(substr($bin, 0, 8)) . '::/64';
    return substr(hash('sha256', salt($dir) . $ip), 0, 16);
}

/** Koden er 16 tegn uden I, L, O og U (80 bit). secret er 32 hex tegn (128 bit). */
function valid_id(mixed $id): bool {
    return is_string($id) && preg_match('/^[0-9A-HJKMNP-TV-Z]{16}$/', $id) === 1;
}

function valid_secret(mixed $s): bool {
    return is_string($s) && preg_match('/^[0-9a-f]{32}$/', $s) === 1;
}

function profile_file(string $dir, string $id): string {
    return $dir . '/p_' . hash('sha256', $id) . '.json';
}

/**
 * Tæller et kald af [kind] for afsenderen og i alt. Afviste kald tæller ikke med, så én afsender aldrig kan
 * lukke for andre. Med [dryRun] tjekkes kun, uden at tælle.
 */
function limited(string $dir, string $kind, int $perSender, ?int $total = null, string $period = 'hour', bool $dryRun = false): bool {
    $key = sender_key($dir, (string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    $slot = (int) floor(time() / ($period === 'hour' ? 3600 : 86400));
    $fp = fopen($dir . '/rate.json', 'c+');
    if ($fp === false) return true;
    flock($fp, LOCK_EX);
    $data = json_decode(stream_get_contents($fp) ?: '{}', true) ?: [];
    $bucket = $data[$kind] ?? [];
    if (($bucket['slot'] ?? null) !== $slot) $bucket = ['slot' => $slot, 'count' => [], 'all' => 0];
    $count = ($bucket['count'][$key] ?? 0) + 1;
    // En ny afsender afvises, når spanden er fuld, så filen ikke kan vokse uden loft
    $full = !isset($bucket['count'][$key]) && count($bucket['count']) >= MAX_SENDERS;
    $isLimited = $full || $count > $perSender || ($total !== null && $bucket['all'] + 1 > $total);
    if (!$isLimited && !$dryRun) {
        $bucket['count'][$key] = $count;
        $bucket['all'] += 1;
        $data[$kind] = $bucket;
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($data));
    }
    flock($fp, LOCK_UN);
    fclose($fp);
    return $isLimited;
}

/** Katalogets id'er, så kun rigtige modeller kan stå som fund. */
function catalog_ids(): array {
    $file = getenv('TRUCKSPOTTING_KATALOG') ?: __DIR__ . '/../forslag/behandl/katalog.json';
    $json = json_decode((string) @file_get_contents($file), true);
    $ids = [];
    foreach (($json['brands'] ?? []) as $b) foreach (($b['models'] ?? []) as $m) if (isset($m['id'])) $ids[(string) $m['id']] = true;
    return $ids;
}

/** Profilen præcis efter skemaet, eller null. Ukendte felter, forkerte typer og tal uden for grænserne afvises. */
function valid_profile(mixed $p): ?array {
    if (!is_array($p) || array_is_list($p) && $p !== []) return null;
    $allowed = array_merge(array_keys(SCHEMA), ['finds']);
    foreach (array_keys($p) as $k) if (!in_array($k, $allowed, true)) return null;
    $out = [];
    foreach (SCHEMA as $k => [$min, $max]) {
        $v = $p[$k] ?? null;
        if (!is_int($v) || $v < $min || $v > $max) return null;
        $out[$k] = $v;
    }
    $finds = $p['finds'] ?? [];
    if (!is_array($finds) || !array_is_list($finds) || count($finds) > 3) return null;
    $known = catalog_ids();
    foreach ($finds as $f) if (!is_string($f) || strlen($f) > 80) return null;
    // Ukendte fund udelades i stedet for at afvise hele profilen. Appen kan kende nyere modeller end serverens kopi
    $out['finds'] = array_values(array_filter($finds, fn($f) => isset($known[$f])));
    return $out;
}

/** Skriver til en midlertidig fil og flytter den på plads, så en læser aldrig ser en halv fil. */
function write_json(string $file, array $data): bool {
    $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
    if (file_put_contents($tmp, json_encode($data), LOCK_EX) === false) return false;
    @chmod($tmp, 0600);
    return rename($tmp, $file);
}

/** Antallet af profilfiler. Holdes i en lille fil, så det ikke tælles ved hvert kald. Kun under den fælles lås. */
function file_count(string $dir, int $delta = 0): int {
    $file = $dir . '/antal.txt';
    $n = is_file($file) ? (int) trim((string) file_get_contents($file)) : count(glob($dir . '/p_*.json') ?: []);
    $n = max(0, $n + $delta);
    if ($delta !== 0 || !is_file($file)) file_put_contents($file, (string) $n, LOCK_EX);
    return $n;
}

/**
 * Sletter profiler og spærrede koder, der ikke er opdateret i 12 måneder. Med [unused] også profiler, der aldrig
 * er opdateret efter oprettelsen og er ældre end UNUSED_AGE. Arbejder på ét af 16 udsnit ad gangen, medmindre [all].
 * Skal køres under den fælles lås. Giver antallet af slettede.
 */
function sweep(string $dir, bool $all = false, bool $unused = false): int {
    $prefix = $all ? '' : dechex(random_int(0, 15));
    $removed = 0;
    foreach (glob($dir . '/p_' . $prefix . '*.json') ?: [] as $f) {
        $old = filemtime($f) < time() - MAX_AGE;
        if (!$old && $unused) {
            $d = json_decode((string) file_get_contents($f), true);
            $old = is_array($d) && empty($d['deleted']) && ($d['created'] ?? '') === ($d['updated'] ?? '')
                && filemtime($f) < time() - UNUSED_AGE;
        }
        if ($old && @unlink($f)) $removed++;
    }
    if ($removed > 0) file_count($dir, -$removed);
    return $removed;
}

/** Én fælles lås for alle skrivninger. Den ligger altid, så afviste kald aldrig efterlader filer. */
function lock_all(string $dir) {
    $lock = fopen($dir . '/skriv.lock', 'c');
    if ($lock === false) reply(500, ['status' => 'store']);
    flock($lock, LOCK_EX);
    return $lock;
}

// Testen indlæser kun funktionerne
if (PHP_SAPI === 'cli') return;

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') reply(405, ['status' => 'method']);
if (!str_starts_with(strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? '')), 'application/json')) reply(415, ['status' => 'type']);
$dir = data_dir();
// Nødbremsen: findes filen "lukket", svarer alle kald 503
if (is_file($dir . '/lukket')) reply(503, ['closed' => true]);
$raw = file_get_contents('php://input', false, null, 0, MAX_BODY + 1) ?: '';
if (strlen($raw) > MAX_BODY) reply(413, ['status' => 'size']);
$in = json_decode($raw, true);
if (!is_array($in)) reply(400, ['status' => 'json']);

$op = $in['op'] ?? '';
if ($op === 'get') {
    if (limited($dir, 'get', MAX_GET_HOUR)) reply(429, ['status' => 'later']);
    $ids = $in['ids'] ?? null;
    if (!is_array($ids) || !array_is_list($ids) || count($ids) > MAX_IDS) reply(400, ['status' => 'ids']);
    $profiles = [];
    foreach ($ids as $id) {
        if (!valid_id($id)) reply(400, ['status' => 'ids']);
        $file = profile_file($dir, $id);
        if (!is_file($file)) continue;
        $data = json_decode((string) file_get_contents($file), true);
        // En spærret kode har ingen profil og udelades
        if (empty($data['deleted']) && is_array($data['profile'] ?? null)) $profiles[$id] = $data['profile'] + ['updated' => $data['updated'] ?? ''];
    }
    reply(200, ['status' => 'ok', 'profiles' => (object) $profiles]);
}

if ($op !== 'put' && $op !== 'delete') reply(400, ['status' => 'op']);
$id = $in['id'] ?? null;
$secret = $in['secret'] ?? null;
if (!valid_id($id) || !valid_secret($secret)) reply(400, ['status' => 'id']);
$allowedKeys = $op === 'put' ? ['op', 'id', 'secret', 'profile'] : ['op', 'id', 'secret'];
foreach (array_keys($in) as $k) if (!in_array($k, $allowedKeys, true)) reply(400, ['status' => 'field']);
// Profilen kontrolleres, før noget røres på disken
$profile = $op === 'put' ? valid_profile($in['profile'] ?? null) : null;
if ($op === 'put' && $profile === null) reply(400, ['status' => 'profile']);
if (limited($dir, 'put', MAX_PUT_HOUR)) reply(429, ['status' => 'later']);

$file = profile_file($dir, $id);
$lock = lock_all($dir);
// Oprydning ved ca. hvert hundrede skrivning, på et udsnit ad gangen
if (getenv('VENNER_SWEEP') === 'always' || random_int(1, 100) === 1) sweep($dir, getenv('VENNER_SWEEP') === 'always');
$existing = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
$hash = hash('sha256', $secret);
if (is_array($existing) && !hash_equals((string) ($existing['secret'] ?? ''), $hash)) reply(403, ['status' => 'secret']);
$today = gmdate('Y-m-d');
// En slettet kode bruges aldrig igen, heller ikke af ejeren. Appen laver så en ny kode
if ($op === 'put' && is_array($existing) && !empty($existing['deleted'])) reply(403, ['status' => 'deleted']);

if ($op === 'delete') {
    // En kode, der aldrig er registreret, efterlader intet. Ellers kunne sletninger fylde serveren
    if (!is_array($existing)) {
        flock($lock, LOCK_UN);
        fclose($lock);
        reply(200, ['status' => 'ok']);
    }
    // Tallene slettes, men koden spærres, så ingen anden kan overtage den og vise falske tal under vennens navn
    $tomb = ['secret' => $hash, 'deleted' => true, 'updated' => $today];
    $ok = write_json($file, $tomb);
    flock($lock, LOCK_UN);
    fclose($lock);
    reply($ok ? 200 : 500, ['status' => $ok ? 'ok' : 'store']);
}

if (!is_array($existing)) {
    // En ny kode: grænser for nye koder pr. afsender og i alt, og et loft over antal filer. Er loftet nået, ryddes
    // først profiler, der aldrig er brugt efter oprettelsen. Den fulde oprydning køres højst én gang i timen og kun
    // for afsendere, der stadig må lave nye koder
    if (limited($dir, 'new', MAX_NEW_DAY, MAX_ALL_NEW_DAY, 'day', true)) reply(429, ['status' => 'later']);
    if (file_count($dir) >= MAX_FILES) {
        $stamp = $dir . '/oprydning.txt';
        $last = is_file($stamp) ? (int) trim((string) file_get_contents($stamp)) : 0;
        if ($last < time() - 3600) {
            file_put_contents($stamp, (string) time(), LOCK_EX);
            sweep($dir, true, true);
        }
        if (file_count($dir) >= MAX_FILES) reply(507, ['status' => 'full']);
    }
    if (limited($dir, 'new', MAX_NEW_DAY, MAX_ALL_NEW_DAY, 'day')) reply(429, ['status' => 'later']);
}
$created = is_array($existing) && !empty($existing['created']) ? $existing['created'] : $today;
$ok = write_json($file, ['secret' => $hash, 'profile' => $profile, 'created' => $created, 'updated' => $today]);
if ($ok && !is_array($existing)) file_count($dir, 1);
flock($lock, LOCK_UN);
fclose($lock);
if (!$ok) reply(500, ['status' => 'store']);
reply(200, ['status' => 'ok']);
