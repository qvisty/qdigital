<?php
// Modtager til Ordstribens tekniske log fra testversioner. Ligger på https://qdigital.dk/ordstriben/log/.
// Brugeren slår selv afsendelsen til i appen. Loggen har kun pakkenavne og tekniske udfald, aldrig tekst eller lyd.
// Én fil pr. install id i ordstriben-data/log/ uden for public_html. Filnavnet er en hash af id'et.
// POST gemmer, DELETE sletter alt for et install id, og GET med læsenøglen henter. Data ældre end 30 dage slettes.

declare(strict_types=1);

/** Grænser. Kan sænkes i testen. */
define('MAX_POST_HOUR', (int) (getenv('ORDLOG_MAX_POST_HOUR') ?: 30));
define('MAX_DELETE_HOUR', (int) (getenv('ORDLOG_MAX_DELETE_HOUR') ?: 30));
define('MAX_BADKEY_HOUR', (int) (getenv('ORDLOG_MAX_BADKEY_HOUR') ?: 20));
define('MAX_NEW_DAY', (int) (getenv('ORDLOG_MAX_NEW_DAY') ?: 10));
define('MAX_ALL_NEW_DAY', (int) (getenv('ORDLOG_MAX_ALL_NEW_DAY') ?: 500));
define('MAX_FILES', (int) (getenv('ORDLOG_MAX_FILES') ?: 2000));
/** Data ældre end så mange sekunder slettes. 30 dage. */
define('MAX_AGE', (int) (getenv('ORDLOG_MAX_AGE') ?: 30 * 86400));
/** Højst så mange afsendere i én spand i rate.json. */
define('MAX_SENDERS', (int) (getenv('ORDLOG_MAX_SENDERS') ?: 5000));
const MAX_BODY = 65536;
const MAX_LINES = 500;
const MAX_LINE = 300;
/** Højst så mange linjer gemmes pr. install id. De ældste kald slettes først. */
const MAX_LINES_PER_INSTALL = 5000;
const LINE_RE = '/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d\|[A-Z_]+\|.*$/';
const UUID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';
const VERSION_RE = '/^[0-9][0-9A-Za-z.+-]{0,31}$/';

function reply(int $code, ?array $body = null): never {
    http_response_code($code);
    header('Cache-Control: no-store');
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
    $dir = data_root() . '/log';
    if (!is_dir($dir)) reply(500, ['status' => 'store']);
    return $dir;
}

/** config.php skrives ved udgivelsen fra GitHub hemmeligheden ORDSTRIBEN_LOG_API_HASH. */
function api_hash(): string {
    $file = data_root() . '/config.php';
    $cfg = is_file($file) ? include $file : [];
    return is_array($cfg) ? strtolower(trim((string) ($cfg['api_hash'] ?? ''))) : '';
}

/** Saltet laves med "x", så to samtidige kald ikke laver hver sit. */
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

/** Tæller et kald af [kind] for afsenderen og i alt. Afviste kald tæller ikke. Med [dryRun] tjekkes kun. */
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

function valid_install(mixed $id): bool {
    return is_string($id) && preg_match(UUID_RE, $id) === 1;
}

/** Kaldet præcis efter kontrakten, eller null. */
function valid_batch(mixed $in): ?array {
    if (!is_array($in) || array_is_list($in)) return null;
    foreach (array_keys($in) as $k) if (!in_array($k, ['install', 'version', 'lines'], true)) return null;
    if (!valid_install($in['install'] ?? null)) return null;
    $v = $in['version'] ?? null;
    if (!is_string($v) || preg_match(VERSION_RE, $v) !== 1) return null;
    $lines = $in['lines'] ?? null;
    if (!is_array($lines) || !array_is_list($lines) || $lines === [] || count($lines) > MAX_LINES) return null;
    foreach ($lines as $l) {
        if (!is_string($l) || mb_strlen($l) > MAX_LINE || !mb_check_encoding($l, 'UTF-8')) return null;
        if (preg_match(LINE_RE, $l) !== 1 || preg_match('/[\x00-\x1f\x7f]/', $l) === 1) return null;
    }
    return ['install' => $in['install'], 'version' => $v, 'lines' => $lines];
}

function install_file(string $dir, string $id): string {
    return $dir . '/i_' . hash('sha256', $id) . '.json';
}

function write_json(string $file, array $data): bool {
    $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
    if (file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX) === false) return false;
    @chmod($tmp, 0600);
    return rename($tmp, $file);
}

/** Sletter kald ældre end MAX_AGE og filer uden kald tilbage. Skal køres under den fælles lås. */
function sweep(string $dir): void {
    $limit = time() - MAX_AGE;
    foreach (glob($dir . '/i_*.json') ?: [] as $f) {
        if (filemtime($f) < $limit) { @unlink($f); continue; }
        $d = json_decode((string) file_get_contents($f), true);
        if (!is_array($d)) { @unlink($f); continue; }
        $kept = array_values(array_filter($d['batches'] ?? [], fn($b) => strtotime((string) ($b['received'] ?? '')) >= $limit));
        if ($kept === []) { @unlink($f); continue; }
        if (count($kept) !== count($d['batches'] ?? [])) {
            $d['batches'] = $kept;
            write_json($f, $d);
        }
    }
    foreach (glob($dir . '/*.tmp') ?: [] as $f) if (filemtime($f) < time() - 3600) @unlink($f);
}

function lock_all(string $dir) {
    $lock = fopen($dir . '/skriv.lock', 'c');
    if ($lock === false) reply(500, ['status' => 'store']);
    flock($lock, LOCK_EX);
    return $lock;
}

// Testen indlæser kun funktionerne
if (PHP_SAPI === 'cli') return;

$method = $_SERVER['REQUEST_METHOD'] ?? '';
$dir = data_dir();
// Nødbremsen: findes filen "lukket", svarer alle kald 503
if (is_file($dir . '/lukket')) reply(503, ['status' => 'closed']);

if ($method === 'GET') {
    $hash = api_hash();
    $key = (string) ($_SERVER['HTTP_X_API_KEY'] ?? '');
    if ($hash === '' || $key === '' || !hash_equals($hash, hash('sha256', $key))) {
        if (limited($dir, 'badkey', MAX_BADKEY_HOUR)) reply(429, ['status' => 'later']);
        reply(401, ['status' => 'key']);
    }
    $since = null;
    if (isset($_GET['since']) && $_GET['since'] !== '') {
        $since = strtotime((string) $_GET['since']);
        if ($since === false) reply(400, ['status' => 'since']);
    }
    $lock = lock_all($dir);
    sweep($dir);
    $out = [];
    foreach (glob($dir . '/i_*.json') ?: [] as $f) {
        $d = json_decode((string) file_get_contents($f), true);
        if (!is_array($d)) continue;
        foreach ($d['batches'] ?? [] as $b) {
            if ($since !== null && strtotime((string) $b['received']) <= $since) continue;
            $out[] = ['install' => $d['install'], 'version' => $b['version'], 'received' => $b['received'], 'lines' => $b['lines']];
        }
    }
    flock($lock, LOCK_UN);
    fclose($lock);
    usort($out, fn($a, $b) => strcmp($a['received'], $b['received']));
    reply(200, ['installs' => $out]);
}

if ($method === 'DELETE') {
    $id = $_GET['install'] ?? null;
    if (!valid_install($id)) reply(400, ['status' => 'install']);
    if (limited($dir, 'delete', MAX_DELETE_HOUR)) reply(429, ['status' => 'later']);
    $lock = lock_all($dir);
    $file = install_file($dir, $id);
    if (is_file($file)) @unlink($file);
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

$file = install_file($dir, $batch['install']);
$lock = lock_all($dir);
if (getenv('ORDLOG_SWEEP') === 'always' || random_int(1, 50) === 1) sweep($dir);
$existing = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
if (!is_array($existing)) {
    // Nyt install id: grænser pr. afsender og i alt, og et loft over antal filer
    if (count(glob($dir . '/i_*.json') ?: []) >= MAX_FILES) { sweep($dir); }
    if (count(glob($dir . '/i_*.json') ?: []) >= MAX_FILES) reply(507, ['status' => 'full']);
    if (limited($dir, 'new', MAX_NEW_DAY, MAX_ALL_NEW_DAY, 'day')) reply(429, ['status' => 'later']);
    $existing = ['install' => $batch['install'], 'batches' => []];
}
$existing['batches'][] = ['received' => gmdate('Y-m-d\TH:i:s\Z'), 'version' => $batch['version'], 'lines' => $batch['lines']];
// Loft pr. install id: de ældste kald fjernes først
$total = array_sum(array_map(fn($b) => count($b['lines']), $existing['batches']));
while ($total > MAX_LINES_PER_INSTALL && count($existing['batches']) > 1) {
    $total -= count(array_shift($existing['batches'])['lines']);
}
$ok = write_json($file, $existing);
flock($lock, LOCK_UN);
fclose($lock);
reply($ok ? 204 : 500, $ok ? null : ['status' => 'store']);
