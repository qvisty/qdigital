<?php
// Modtager til Ordstribens brugsstatistik. Ligger på https://qdigital.dk/ordstriben/stats/.
// Appen sender kun, når brugeren har sagt ja. Kun tal: ingen tekst, ingen lyd og ingen navne på apps.
// Én fil pr. bruger id i ordstriben-data/stats/ uden for public_html. Filnavnet er en hash af id'et.
// POST gemmer, DELETE sletter alt for et bruger id, GET ?summary giver de samlede tal til dashboardet,
// og GET med læsenøglen giver de rå data. Data ældre end 13 måneder slettes. IP adresser gemmes aldrig.

declare(strict_types=1);

define('MAX_POST_HOUR', (int) (getenv('ORDSTATS_MAX_POST_HOUR') ?: 30));
define('MAX_DELETE_HOUR', (int) (getenv('ORDSTATS_MAX_DELETE_HOUR') ?: 30));
define('MAX_BADKEY_HOUR', (int) (getenv('ORDSTATS_MAX_BADKEY_HOUR') ?: 20));
define('MAX_NEW_DAY', (int) (getenv('ORDSTATS_MAX_NEW_DAY') ?: 10));
define('MAX_ALL_NEW_DAY', (int) (getenv('ORDSTATS_MAX_ALL_NEW_DAY') ?: 500));
define('MAX_FILES', (int) (getenv('ORDSTATS_MAX_FILES') ?: 5000));
define('MAX_SENDERS', (int) (getenv('ORDSTATS_MAX_SENDERS') ?: 5000));
/** Data ældre end så mange sekunder slettes. 13 måneder. */
define('MAX_AGE', (int) (getenv('ORDSTATS_MAX_AGE') ?: 396 * 86400));
/** De samlede tal regnes højst så ofte. */
define('SUMMARY_TTL', (int) (getenv('ORDSTATS_SUMMARY_TTL') ?: 300));
/** Et tal vises kun, når mindst så mange brugere står bag. */
define('MIN_USERS', (int) (getenv('ORDSTATS_MIN_USERS') ?: 5));
const MAX_BODY = 65536;
const MAX_EVENTS = 500;
const MAX_EVENTS_PER_USER = 20000;
/** Ord pr. minut, når man skriver på tastatur. Bruges til sparet tid. */
const TYPING_WPM = 38;
const UUID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';
const VERSION_RE = '/^[0-9][0-9A-Za-z.+-]{0,31}$/';
const EVENT_KEYS = ['t', 'kind', 'lang', 'engine', 'ok', 'error', 'audio_ms', 'wait_ms', 'words', 'chars', 'mode', 'fallback'];

function reply(int $code, ?array $body = null, int $cache = 0): never {
    http_response_code($code);
    header('Cache-Control: ' . ($cache > 0 ? 'public, max-age=' . $cache : 'no-store'));
    if ($body !== null) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    exit;
}

function data_root(): string {
    return getenv('ORDSTRIBEN_DATA') ?: __DIR__ . '/../../../ordstriben-data';
}

/** Mappen uden for public_html. Den oprettes aldrig her, så en forkert sti ikke lægger filerne åbent. */
function data_dir(): string {
    $dir = data_root() . '/stats';
    if (!is_dir($dir)) reply(500, ['status' => 'store']);
    return $dir;
}

/** Samme læsenøgle som loggen. config.php skrives ved udgivelsen fra ORDSTRIBEN_LOG_API_HASH. */
function api_hash(): string {
    $file = data_root() . '/config.php';
    $cfg = is_file($file) ? include $file : [];
    return is_array($cfg) ? strtolower(trim((string) ($cfg['api_hash'] ?? ''))) : '';
}

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

/** Afsenderen som en kort saltet hash. For IPv6 bruges kun de første 64 bit. IP adressen gemmes aldrig. */
function sender_key(string $dir, string $ip): string {
    $bin = @inet_pton($ip);
    if ($bin !== false && strlen($bin) === 16) $ip = bin2hex(substr($bin, 0, 8)) . '::/64';
    return substr(hash('sha256', salt($dir) . $ip), 0, 16);
}

function limited(string $dir, string $kind, int $perSender, ?int $total = null, string $period = 'hour'): bool {
    $key = sender_key($dir, (string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    $slot = (int) floor(time() / ($period === 'hour' ? 3600 : 86400));
    $fp = fopen($dir . '/rate.json', 'c+');
    if ($fp === false) return true;
    flock($fp, LOCK_EX);
    $data = json_decode(stream_get_contents($fp) ?: '{}', true) ?: [];
    $bucket = $data[$kind] ?? [];
    if (($bucket['slot'] ?? null) !== $slot) $bucket = ['slot' => $slot, 'count' => [], 'all' => 0];
    $count = ($bucket['count'][$key] ?? 0) + 1;
    $full = !isset($bucket['count'][$key]) && count($bucket['count']) >= MAX_SENDERS;
    $isLimited = $full || $count > $perSender || ($total !== null && $bucket['all'] + 1 > $total);
    if (!$isLimited) {
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

function valid_user(mixed $id): bool {
    return is_string($id) && preg_match(UUID_RE, $id) === 1;
}

function int_in(mixed $v, int $min, int $max): bool {
    return is_int($v) && $v >= $min && $v <= $max;
}

/** Én hændelse præcis efter kontrakten, eller null. */
function valid_event(mixed $e): ?array {
    if (!is_array($e) || array_is_list($e)) return null;
    foreach (array_keys($e) as $k) if (!in_array($k, EVENT_KEYS, true)) return null;
    $t = $e['t'] ?? null;
    if (!is_string($t) || preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $t) !== 1) return null;
    $ts = strtotime($t);
    if ($ts === false || $ts > time() + 86400 || $ts < time() - MAX_AGE) return null;
    if (!in_array($e['kind'] ?? null, ['dictation', 'file'], true)) return null;
    if (!is_string($e['lang'] ?? null) || preg_match('/^[a-z]{2,3}(-[A-Z]{2})?$/', $e['lang']) !== 1) return null;
    if (!is_string($e['engine'] ?? null) || preg_match('/^[a-z0-9._-]{1,40}$/', $e['engine']) !== 1) return null;
    if (!is_bool($e['ok'] ?? null) || !is_bool($e['fallback'] ?? null)) return null;
    $err = $e['error'] ?? null;
    if ($err !== null && (!is_string($err) || preg_match('/^[A-Z_]{1,40}$/', $err) !== 1)) return null;
    if (!int_in($e['audio_ms'] ?? null, 0, 3600000) || !int_in($e['wait_ms'] ?? null, 0, 3600000)) return null;
    if (!int_in($e['words'] ?? null, 0, 100000) || !int_in($e['chars'] ?? null, 0, 1000000)) return null;
    if (!in_array($e['mode'] ?? null, ['DIRECT', 'WINDOW'], true)) return null;
    $out = [];
    foreach (EVENT_KEYS as $k) $out[$k] = $e[$k];
    return $out;
}

function valid_batch(mixed $in): ?array {
    if (!is_array($in) || array_is_list($in)) return null;
    foreach (array_keys($in) as $k) if (!in_array($k, ['user', 'version', 'events'], true)) return null;
    if (!valid_user($in['user'] ?? null)) return null;
    $v = $in['version'] ?? null;
    if (!is_string($v) || preg_match(VERSION_RE, $v) !== 1) return null;
    $events = $in['events'] ?? null;
    if (!is_array($events) || !array_is_list($events) || $events === [] || count($events) > MAX_EVENTS) return null;
    $out = [];
    foreach ($events as $e) {
        $ok = valid_event($e);
        if ($ok === null) return null;
        $out[] = $ok;
    }
    return ['user' => $in['user'], 'version' => $v, 'events' => $out];
}

function user_file(string $dir, string $id): string {
    return $dir . '/u_' . hash('sha256', $id) . '.json';
}

function write_json(string $file, array $data): bool {
    $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
    if (file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX) === false) return false;
    @chmod($tmp, 0600);
    return rename($tmp, $file);
}

function lock_all(string $dir) {
    $lock = fopen($dir . '/skriv.lock', 'c');
    if ($lock === false) reply(500, ['status' => 'store']);
    flock($lock, LOCK_EX);
    return $lock;
}

/** Sletter hændelser ældre end 13 måneder og filer uden hændelser. Under den fælles lås. */
function sweep(string $dir): void {
    $limit = time() - MAX_AGE;
    foreach (glob($dir . '/u_*.json') ?: [] as $f) {
        if (filemtime($f) < $limit) { @unlink($f); continue; }
        $d = json_decode((string) file_get_contents($f), true);
        if (!is_array($d)) { @unlink($f); continue; }
        $kept = array_values(array_filter($d['events'] ?? [], fn($e) => strtotime((string) ($e['t'] ?? '')) >= $limit));
        if ($kept === []) { @unlink($f); continue; }
        if (count($kept) !== count($d['events'] ?? [])) { $d['events'] = $kept; write_json($f, $d); }
    }
    foreach (glob($dir . '/*.tmp') ?: [] as $f) if (filemtime($f) < time() - 3600) @unlink($f);
}

/** Andel med to decimaler, eller null. */
function ratio(float $a, float $b): ?float {
    return $b > 0 ? round($a / $b, 4) : null;
}

/**
 * De samlede tal til dashboardet. Et tal med færre end MIN_USERS brugere bag gives som null.
 * Fordelinger viser kun grupper med mindst MIN_USERS brugere. De øvrige samles i "andre", hvis de er nok.
 */
function summary(string $dir): array {
    $tz = new DateTimeZone('Europe/Copenhagen');
    $today = (new DateTime('now', $tz))->format('Y-m-d');
    $weekAgo = time() - 7 * 86400;
    $users = 0; $usersToday = 0; $users7 = 0;
    $events = 0; $ok = 0; $files = 0; $words = 0; $chars = 0; $audio = 0; $wait = 0;
    $usersWithOk = 0;
    $lang = []; $engine = [];
    foreach (glob($dir . '/u_*.json') ?: [] as $f) {
        $d = json_decode((string) file_get_contents($f), true);
        if (!is_array($d) || empty($d['events'])) continue;
        $users++;
        $seenToday = false; $seen7 = false; $hadOk = false; $ul = []; $ue = [];
        foreach ($d['events'] as $e) {
            $ts = strtotime((string) $e['t']);
            if ((new DateTime('@' . $ts))->setTimezone($tz)->format('Y-m-d') === $today) $seenToday = true;
            if ($ts >= $weekAgo) $seen7 = true;
            $events++;
            if ($e['kind'] === 'file') $files++;
            $ul[$e['lang']] = true;
            $ue[$e['engine']] = true;
            if ($e['ok']) {
                $ok++; $hadOk = true;
                $words += $e['words']; $chars += $e['chars']; $audio += $e['audio_ms']; $wait += $e['wait_ms'];
            }
        }
        if ($seenToday) $usersToday++;
        if ($seen7) $users7++;
        if ($hadOk) $usersWithOk++;
        foreach (array_keys($ul) as $k) $lang[$k] = ($lang[$k] ?? 0) + 1;
        foreach (array_keys($ue) as $k) $engine[$k] = ($engine[$k] ?? 0) + 1;
    }
    $enough = $users >= MIN_USERS;
    $show = fn(int $n) => $n >= MIN_USERS ? $n : null;
    $dist = function (array $counts) use ($enough): ?array {
        if (!$enough) return null;
        arsort($counts);
        $out = []; $rest = 0;
        foreach ($counts as $k => $n) { if ($n >= MIN_USERS) $out[(string) $k] = $n; else $rest += $n; }
        if ($rest >= MIN_USERS) $out['andre'] = $rest;
        return $out;
    };
    $savedMin = $words / TYPING_WPM - $audio / 60000;
    return [
        'updated' => gmdate('Y-m-d\TH:i:s\Z'),
        'min_users' => MIN_USERS,
        'users' => ['today' => $show($usersToday), 'week' => $show($users7), 'total' => $show($users)],
        'dictations' => $enough ? $ok : null,
        'words' => $enough ? $words : null,
        'words_per_user' => $enough && $usersWithOk >= MIN_USERS ? (int) round($words / $usersWithOk) : null,
        'keystrokes_saved' => $enough ? $chars : null,
        'minutes_saved' => $enough ? (int) max(0, round($savedMin)) : null,
        'words_per_minute' => $enough && $audio > 0 ? round($words / ($audio / 60000), 1) : null,
        'avg_wait_ms' => $enough && $ok > 0 ? (int) round($wait / $ok) : null,
        'success_rate' => $enough ? ratio($ok, $events) : null,
        'file_share' => $enough ? ratio($files, $events) : null,
        'languages' => $dist($lang),
        'engines' => $dist($engine),
    ];
}

// Testen indlæser kun funktionerne
if (PHP_SAPI === 'cli') return;

$method = $_SERVER['REQUEST_METHOD'] ?? '';
$dir = data_dir();
if (is_file($dir . '/lukket')) reply(503, ['status' => 'closed']);

if ($method === 'GET' && isset($_GET['summary'])) {
    header('Access-Control-Allow-Origin: *');
    $cache = $dir . '/summary.json';
    if (is_file($cache) && filemtime($cache) > time() - SUMMARY_TTL) {
        $s = json_decode((string) file_get_contents($cache), true);
        if (is_array($s)) reply(200, $s, 60);
    }
    $lock = lock_all($dir);
    sweep($dir);
    $s = summary($dir);
    write_json($cache, $s);
    flock($lock, LOCK_UN);
    fclose($lock);
    reply(200, $s, 60);
}

if ($method === 'GET') {
    $hash = api_hash();
    $key = (string) ($_SERVER['HTTP_X_API_KEY'] ?? '');
    if ($hash === '' || $key === '' || !hash_equals($hash, hash('sha256', $key))) {
        if (limited($dir, 'badkey', MAX_BADKEY_HOUR)) reply(429, ['status' => 'later']);
        reply(401, ['status' => 'key']);
    }
    $lock = lock_all($dir);
    sweep($dir);
    $out = [];
    foreach (glob($dir . '/u_*.json') ?: [] as $f) {
        $d = json_decode((string) file_get_contents($f), true);
        if (is_array($d)) $out[] = ['user' => $d['user'], 'version' => $d['version'] ?? '', 'events' => $d['events'] ?? []];
    }
    flock($lock, LOCK_UN);
    fclose($lock);
    reply(200, ['users' => $out]);
}

if ($method === 'DELETE') {
    $id = $_GET['user'] ?? null;
    if (!valid_user($id)) reply(400, ['status' => 'user']);
    if (limited($dir, 'delete', MAX_DELETE_HOUR)) reply(429, ['status' => 'later']);
    $lock = lock_all($dir);
    $file = user_file($dir, $id);
    if (is_file($file)) @unlink($file);
    @unlink($dir . '/summary.json');
    flock($lock, LOCK_UN);
    fclose($lock);
    reply(204);
}

if ($method !== 'POST') {
    header('Allow: GET, POST, DELETE');
    reply(405, ['status' => 'method']);
}
if (!str_starts_with(strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? '')), 'application/json')) reply(415, ['status' => 'type']);
$raw = file_get_contents('php://input', false, null, 0, MAX_BODY + 1) ?: '';
if (strlen($raw) > MAX_BODY) reply(413, ['status' => 'size']);
$batch = valid_batch(json_decode($raw, true));
if ($batch === null) reply(400, ['status' => 'format']);
if (limited($dir, 'post', MAX_POST_HOUR)) reply(429, ['status' => 'later']);

$file = user_file($dir, $batch['user']);
$lock = lock_all($dir);
if (getenv('ORDSTATS_SWEEP') === 'always' || random_int(1, 50) === 1) sweep($dir);
$existing = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
if (!is_array($existing)) {
    if (count(glob($dir . '/u_*.json') ?: []) >= MAX_FILES) sweep($dir);
    if (count(glob($dir . '/u_*.json') ?: []) >= MAX_FILES) reply(507, ['status' => 'full']);
    if (limited($dir, 'new', MAX_NEW_DAY, MAX_ALL_NEW_DAY, 'day')) reply(429, ['status' => 'later']);
    $existing = ['user' => $batch['user'], 'events' => []];
}
$existing['version'] = $batch['version'];
// Samme hændelse sendt to gange tæller kun én gang
$seen = [];
foreach ($existing['events'] as $e) $seen[md5(json_encode($e))] = true;
foreach ($batch['events'] as $e) {
    $h = md5(json_encode($e));
    if (!isset($seen[$h])) { $existing['events'][] = $e; $seen[$h] = true; }
}
if (count($existing['events']) > MAX_EVENTS_PER_USER) {
    usort($existing['events'], fn($a, $b) => strcmp($a['t'], $b['t']));
    $existing['events'] = array_slice($existing['events'], -MAX_EVENTS_PER_USER);
}
$ok = write_json($file, $existing);
flock($lock, LOCK_UN);
fclose($lock);
reply($ok ? 204 : 500, $ok ? null : ['status' => 'store']);
