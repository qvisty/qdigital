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
/** Valgfrit felt: hvor mange dikteringer én linje dækker, fx en samlet linje pr. dag fra historikken. Standard 1. */
const OPTIONAL_KEYS = ['count', 'fillers', 'emoji'];
/** Fyldord, som appen har fjernet. Kun antal pr. slags. */
const FILLER_KEYS = ['oeh', 'aeh', 'oehm', 'hmm', 'andre'];
/** Højst så mange forskellige smileys i én hændelse. */
const MAX_EMOJI_KEYS = 20;

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

/** Et objekt med kendte nøgler og antal fra 0 til 100000, eller null. Tomt objekt er tilladt. */
function valid_counts(mixed $o, array $keys): ?array {
    if (!is_array($o) || (array_is_list($o) && $o !== [])) return null;
    foreach ($o as $k => $n) if (!in_array((string) $k, $keys, true) || !int_in($n, 0, 100000)) return null;
    return $o;
}

/** Smileys som nøgler, kun billedtegn, højst 16 byte hver, med antal fra 1 til 100000. */
function valid_emoji(mixed $o): ?array {
    if (!is_array($o) || (array_is_list($o) && $o !== []) || count($o) > MAX_EMOJI_KEYS) return null;
    foreach ($o as $k => $n) {
        $k = (string) $k;
        if ($k === '' || strlen($k) > 16 || !mb_check_encoding($k, 'UTF-8')) return null;
        if (preg_match('/^(?:\p{Extended_Pictographic}|\p{Regional_Indicator}|[\x{FE0F}\x{200D}\x{1F3FB}-\x{1F3FF}\x{20E3}#*0-9])+$/u', $k) !== 1) return null;
        if (preg_match('/\p{Extended_Pictographic}|\p{Regional_Indicator}/u', $k) !== 1) return null;
        if (!int_in($n, 1, 100000)) return null;
    }
    return $o;
}

/** Én hændelse præcis efter kontrakten, eller null. */
function valid_event(mixed $e): ?array {
    if (!is_array($e) || array_is_list($e)) return null;
    foreach (array_keys($e) as $k) if (!in_array($k, EVENT_KEYS, true) && !in_array($k, OPTIONAL_KEYS, true)) return null;
    if (array_key_exists('count', $e) && !int_in($e['count'], 1, 10000)) return null;
    if (array_key_exists('fillers', $e) && valid_counts($e['fillers'], FILLER_KEYS) === null) return null;
    if (array_key_exists('emoji', $e) && valid_emoji($e['emoji']) === null) return null;
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
    if (array_key_exists('count', $e) && $e['count'] !== 1) $out['count'] = $e['count'];
    if (!empty($e['fillers'])) $out['fillers'] = $e['fillers'];
    if (!empty($e['emoji'])) $out['emoji'] = $e['emoji'];
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

/**
 * De sjove tal. Smileys vises kun, hvis mindst MIN_USERS brugere har brugt dem, og højst fem.
 * Ugedag og time er dem med flest enkelte dikteringer.
 */
function fun_numbers(bool $enough, array $fillers, array $emojiN, array $emojiUsers, int $longest, array $weekday, array $hour): ?array {
    if (!$enough) return null;
    $top = [];
    arsort($emojiN);
    foreach ($emojiN as $k => $n) {
        if (($emojiUsers[$k] ?? 0) < MIN_USERS) continue;
        $top[] = ['emoji' => (string) $k, 'count' => rough($n)];
        if (count($top) === 5) break;
    }
    $f = [];
    foreach (FILLER_KEYS as $k) if (!empty($fillers[$k])) $f[$k] = rough($fillers[$k]);
    arsort($weekday);
    arsort($hour);
    return [
        'fillers_total' => rough(array_sum($fillers)),
        'fillers' => $f,
        'top_emoji' => $top,
        'longest_seconds' => $longest > 0 ? (int) round($longest / 1000) : null,
        'busiest_weekday' => $weekday ? (int) array_key_first($weekday) : null,
        'busiest_hour' => $hour ? (int) array_key_first($hour) : null,
    ];
}

/** Andel med to decimaler, eller null. */
function ratio(float $a, float $b): ?float {
    return $b > 0 ? round($a / $b, 4) : null;
}

/** Afrunder nedad til to betydende cifre, fx 98.450 til 98.000 og 1.234 til 1.200. Under 10 til hele tal. */
function rough(float $n): int {
    if ($n < 10) return (int) floor($n);
    if ($n < 100) return (int) (floor($n / 10) * 10);
    $p = 10 ** (floor(log10($n)) - 1);
    return (int) (floor($n / $p) * $p);
}

/**
 * De samlede tal til dashboardet. Antallet af brugere gives aldrig ud. Totaler og gennemsnit pr. bruger
 * afrundes til to betydende cifre, så antallet af brugere kun kan gisnes groft. Med færre end MIN_USERS
 * brugere bag gives null.
 * Fordelinger er andele i hele procent og viser kun grupper med mindst MIN_USERS brugere. Resten samles i "andre".
 */
function summary(string $dir): array {
    $users = 0; $usersWithOk = 0;
    $events = 0; $ok = 0; $dict = 0; $files = 0; $fileChars = 0; $fileAudio = 0; $words = 0; $chars = 0; $audio = 0; $wait = 0; $waitN = 0;
    $lang = []; $engine = [];
    $fillers = []; $emojiN = []; $emojiUsers = []; $longest = 0; $weekday = []; $hour = [];
    $tz = new DateTimeZone('Europe/Copenhagen');
    foreach (glob($dir . '/u_*.json') ?: [] as $f) {
        $d = json_decode((string) file_get_contents($f), true);
        if (!is_array($d) || empty($d['events'])) continue;
        $users++;
        $hadOk = false; $ul = []; $ue = []; $uEmoji = [];
        foreach ($d['events'] as $e) {
            $events++;
            if ($e['kind'] === 'file') $files++;
            $ul[$e['lang']] = true;
            $ue[$e['engine']] = true;
            if ($e['ok']) {
                $ok++; $hadOk = true;
                $dict += (int) ($e['count'] ?? 1);
                if ($e['kind'] === 'file') { $fileChars += $e['chars']; $fileAudio += $e['audio_ms']; }
                $words += $e['words']; $chars += $e['chars']; $audio += $e['audio_ms'];
                // Historik fra før samtykket har ventetid 0 og tæller ikke med i ventetiden
                if ($e['wait_ms'] > 0) { $wait += $e['wait_ms']; $waitN++; }
                foreach ($e['fillers'] ?? [] as $k => $n) $fillers[$k] = ($fillers[$k] ?? 0) + $n;
                foreach ($e['emoji'] ?? [] as $k => $n) { $k = (string) $k; $emojiN[$k] = ($emojiN[$k] ?? 0) + $n; $uEmoji[$k] = true; }
                // Enkelte dikteringer, ikke historik: længste diktering og hvornår der dikteres
                if (!isset($e['count']) && $e['wait_ms'] > 0) {
                    $longest = max($longest, $e['audio_ms']);
                    $dt = (new DateTime('@' . strtotime((string) $e['t'])))->setTimezone($tz);
                    $wd = (int) $dt->format('N'); $hr = (int) $dt->format('G');
                    $weekday[$wd] = ($weekday[$wd] ?? 0) + 1;
                    $hour[$hr] = ($hour[$hr] ?? 0) + 1;
                }
            }
        }
        if ($hadOk) $usersWithOk++;
        foreach (array_keys($uEmoji) as $k) $emojiUsers[$k] = ($emojiUsers[$k] ?? 0) + 1;
        foreach (array_keys($ul) as $k) $lang[$k] = ($lang[$k] ?? 0) + 1;
        foreach (array_keys($ue) as $k) $engine[$k] = ($engine[$k] ?? 0) + 1;
    }
    $enough = $users >= MIN_USERS;
    $per = $enough && $usersWithOk >= MIN_USERS;
    $dist = function (array $counts) use ($enough): ?array {
        if (!$enough) return null;
        arsort($counts);
        $keep = []; $rest = 0;
        foreach ($counts as $k => $n) { if ($n >= MIN_USERS) $keep[(string) $k] = $n; else $rest += $n; }
        if ($rest >= MIN_USERS) $keep['andre'] = $rest;
        $sum = array_sum($keep);
        $out = [];
        foreach ($keep as $k => $n) $out[$k] = (int) round($n / $sum * 100);
        return $out;
    };
    $savedMin = max(0, $words / TYPING_WPM - $audio / 60000);
    return [
        'updated' => gmdate('Y-m-d\TH:i:s\Z'),
        'min_users' => MIN_USERS,
        'enough' => $enough,
        'total' => [
            'dictations' => $enough ? rough($dict) : null,
            'minutes_spoken' => $enough ? rough($audio / 60000) : null,
            'words' => $enough ? rough($words) : null,
            'keystrokes_saved' => $enough ? rough($chars) : null,
            'minutes_saved' => $enough ? rough($savedMin) : null,
            'file_chars' => $enough ? rough($fileChars) : null,
            'file_minutes' => $enough ? rough($fileAudio / 60000) : null,
        ],
        'per_user' => [
            'dictations' => $per ? rough($dict / $usersWithOk) : null,
            'minutes_spoken' => $per ? rough($audio / 60000 / $usersWithOk) : null,
            'words' => $per ? rough($words / $usersWithOk) : null,
            'keystrokes_saved' => $per ? rough($chars / $usersWithOk) : null,
            'minutes_saved' => $per ? rough($savedMin / $usersWithOk) : null,
            'file_chars' => $per ? rough($fileChars / $usersWithOk) : null,
        ],
        'words_per_minute' => $enough && $audio > 0 ? round($words / ($audio / 60000), 1) : null,
        'avg_wait_ms' => $enough && $waitN > 0 ? (int) round($wait / $waitN) : null,
        'success_rate' => $enough ? ratio($ok, $events) : null,
        'file_share' => $enough ? ratio($files, $events) : null,
        'fun' => fun_numbers($enough, $fillers, $emojiN, $emojiUsers, $longest, $weekday, $hour),
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
    // Id'et kan komme i en header, så det ikke havner i webserverens adgangslog.
    $id = $_SERVER['HTTP_X_ORDSTRIBEN_USER'] ?? ($_GET['user'] ?? null);
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
