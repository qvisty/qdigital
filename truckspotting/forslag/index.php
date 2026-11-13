<?php
// Modtager forslag til Truckspottings katalog fra appen.
// Ligger på https://qdigital.dk/truckspotting/forslag/ (public_html/truckspotting/forslag/index.php).
// Gemmer hvert forslag i en CSV fil uden for public_html. Højst én mail om dagen til kontakt@qdigital.dk
// med de forslag, der er kommet siden sidste mail (ejerens valg 13. november 2026).
// Adgangen til SMTP ligger i config.php uden for public_html og aldrig i repoet. Se server/README.md.

declare(strict_types=1);

const MAX_FIELD = 40;
const MAX_PER_HOUR = 20;
/** Samlet for alle afsendere, så mange adresser på én gang ikke kan fylde filen. Kan sænkes i testen. */
define('MAX_ALL_HOUR', (int) (getenv('TRUCKSPOTTING_MAX_ALL_HOUR') ?: 200));
define('MAX_ALL_DAY', (int) (getenv('TRUCKSPOTTING_MAX_ALL_DAY') ?: 1000));
/** Loft over forslag.csv. Over det tages der ikke imod flere, før der er ryddet op. */
define('MAX_CSV_BYTES', (int) (getenv('TRUCKSPOTTING_MAX_CSV_BYTES') ?: 1_000_000));
const MAIL_EVERY_SECONDS = 86400;

/** Mappen uden for public_html. Den oprettes aldrig her, så en forkert sti ikke lægger filerne åbent. */
function data_dir(): string {
    $dir = getenv('TRUCKSPOTTING_DATA') ?: __DIR__ . '/../../../truckspotting-data';
    if (!is_dir($dir)) reply(500, 'store');
    return $dir;
}

/** Et hemmeligt salt til hashen af IP adressen. Laves første gang og ligger kun i datamappen. */
function salt(string $dir): string {
    $file = $dir . '/salt.txt';
    $salt = is_file($file) ? trim((string) file_get_contents($file)) : '';
    if ($salt === '') {
        $salt = bin2hex(random_bytes(16));
        file_put_contents($file, $salt, LOCK_EX);
        @chmod($file, 0600);
    }
    return $salt;
}

function reply(int $code, string $status): never {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(['status' => $status]);
    exit;
}

/** Fjerner styretegn og ombrydninger og skærer til MAX_FIELD tegn. */
function clean(mixed $value): string {
    if (!is_string($value)) return '';
    $value = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? '';
    $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    return mb_substr($value, 0, MAX_FIELD);
}

/**
 * Højst MAX_PER_HOUR forslag i timen fra samme afsender og højst MAX_ALL_HOUR og MAX_ALL_DAY i alt.
 * IP adressen gemmes kun som en hash med et hemmeligt salt.
 */
function rate_limited(string $dir): bool {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $key = substr(hash('sha256', salt($dir) . $ip), 0, 16);
    $file = $dir . '/rate.json';
    $hour = (int) floor(time() / 3600);
    $day = (int) floor(time() / 86400);
    $fp = fopen($file, 'c+');
    if ($fp === false) return true;
    flock($fp, LOCK_EX);
    $data = json_decode(stream_get_contents($fp) ?: '{}', true) ?: [];
    if (($data['hour'] ?? 0) !== $hour) { $data['hour'] = $hour; $data['count'] = []; $data['all_hour'] = 0; }
    if (($data['day'] ?? 0) !== $day) { $data['day'] = $day; $data['all_day'] = 0; }
    // Afviste forsøg tæller hverken med i afsenderens eller de samlede tal, så én afsender ikke kan lukke for alle
    $count = ($data['count'][$key] ?? 0) + 1;
    $limited = $count > MAX_PER_HOUR
        || ($data['all_hour'] ?? 0) + 1 > MAX_ALL_HOUR
        || ($data['all_day'] ?? 0) + 1 > MAX_ALL_DAY;
    if (!$limited) {
        $data['count'][$key] = $count;
        $data['all_hour'] = ($data['all_hour'] ?? 0) + 1;
        $data['all_day'] = ($data['all_day'] ?? 0) + 1;
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($data));
    }
    flock($fp, LOCK_UN);
    fclose($fp);
    return $limited;
}

/** En nøgle for et forslag, så forslag fra samme sekund kan skelnes. */
function row_key(array $f): string {
    return substr(hash('sha256', json_encode($f)), 0, 16);
}

/**
 * Forslag, der ikke har været med i en mail. [since] er tiden for det seneste sendte, og [since_keys] er de sendte
 * fra netop det sekund, så et forslag fra samme sekund ikke springes over.
 */
function unsent_rows(array $rows, array $state): array {
    $since = (string) ($state['since'] ?? '');
    $keys = is_array($state['since_keys'] ?? null) ? $state['since_keys'] : [];
    return array_values(array_filter($rows, function (array $f) use ($since, $keys): bool {
        $when = (string) ($f[0] ?? '');
        return $when > $since || ($when === $since && !in_array(row_key($f), $keys, true));
    }));
}

/** Tilstanden, efter at [sent] er sendt kl. [now]. */
function state_after(array $state, array $sent, int $now): array {
    $since = (string) ($state['since'] ?? '');
    $keys = is_array($state['since_keys'] ?? null) ? $state['since_keys'] : [];
    foreach ($sent as $f) {
        $when = (string) ($f[0] ?? '');
        if ($when > $since) { $since = $when; $keys = []; }
        if ($when === $since) $keys[] = row_key($f);
    }
    return ['sent_at' => $now, 'since' => $since, 'since_keys' => array_values(array_unique($keys))];
}

function read_state($fp): array {
    rewind($fp);
    return json_decode(stream_get_contents($fp) ?: '{}', true) ?: [];
}

function write_state($fp, array $state): void {
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($state));
    fflush($fp);
}

/**
 * Sender højst én mail pr. MAIL_EVERY_SECONDS med alle forslag siden sidste mail. Det første forslag efter et
 * stille døgn sendes derfor med det samme. Kommer der flere, venter de til næste døgn. Låsen holdes ikke under
 * SMTP, så en langsom mailserver ikke bremser andre. Tiden reserveres først, så kun én sender.
 */
function maybe_send_digest(string $dir, array $cfg): void {
    $fp = fopen($dir . '/mail.json', 'c+');
    if ($fp === false) return;
    flock($fp, LOCK_EX);
    $state = read_state($fp);
    $last = (int) ($state['sent_at'] ?? 0);
    $now = time();
    $rows = [];
    if ($now - $last >= MAIL_EVERY_SECONDS) {
        $all = [];
        $csv = @fopen($dir . '/forslag.csv', 'r');
        if ($csv !== false) {
            while (($f = fgetcsv($csv, 0, ',', '"', '')) !== false) $all[] = $f;
            fclose($csv);
        }
        $rows = unsent_rows($all, $state);
        if ($rows) write_state($fp, ['sent_at' => $now] + $state);
    }
    flock($fp, LOCK_UN);
    if (!$rows) { fclose($fp); return; }

    $google = count(array_filter($rows, fn($f) => ($f[6] ?? '') === 'google'));
    $body = count($rows) . " nye forslag til kataloget siden sidste mail\n"
        . ($google ? "$google af dem er fra Googles testrobot (emulator). De kan stadig være gode.\n" : '')
        . "\n";
    foreach ($rows as $f) {
        $body .= ($f[0] ?? '') . '  ' . ($f[1] ?? '') . ' ' . ($f[2] ?? '') . '  ' . ($f[3] ?? '')
            . '  ' . ($f[4] ?? '') . ' ' . ($f[5] ?? '') . (($f[6] ?? '') === 'google' ? '  [Google test]' : '') . "\n";
    }
    $body .= "\nBehandl forslagene her: https://qdigital.dk/truckspotting/forslag/behandl/\n";
    $ok = smtp_send($cfg, 'Truckspotting: ' . count($rows) . ' nye forslag', $body);

    flock($fp, LOCK_EX);
    $current = read_state($fp);
    // Fejlede mailen, frigives reservationen, så næste forslag prøver igen
    write_state($fp, $ok ? state_after($current, $rows, $now) : ['sent_at' => $last] + $current);
    flock($fp, LOCK_UN);
    fclose($fp);
}

/** Sender en mail gennem websmtp.simply.com på port 587 med STARTTLS. */
function smtp_send(array $cfg, string $subject, string $body): bool {
    $s = @stream_socket_client('tcp://' . ($cfg['host'] ?? 'websmtp.simply.com') . ':587', $errno, $err, 15);
    if ($s === false) return false;
    stream_set_timeout($s, 15);
    $read = function () use ($s): string {
        $out = '';
        while (($line = fgets($s, 1024)) !== false) {
            $out .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') break;
        }
        return $out;
    };
    $cmd = function (string $c, string $expect) use ($s, $read): bool {
        fwrite($s, $c . "\r\n");
        return str_starts_with($read(), $expect);
    };
    $ok = str_starts_with($read(), '220')
        && $cmd('EHLO qdigital.dk', '250')
        && $cmd('STARTTLS', '220')
        && stream_socket_enable_crypto($s, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)
        && $cmd('EHLO qdigital.dk', '250')
        && $cmd('AUTH LOGIN', '334')
        && $cmd(base64_encode($cfg['user']), '334')
        && $cmd(base64_encode($cfg['pass']), '235')
        && $cmd('MAIL FROM:<' . $cfg['user'] . '>', '250')
        && $cmd('RCPT TO:<' . $cfg['to'] . '>', '250')
        && $cmd('DATA', '354');
    if ($ok) {
        $headers = 'From: Truckspotting <' . $cfg['user'] . ">\r\n"
            . 'To: <' . $cfg['to'] . ">\r\n"
            . 'Subject: =?UTF-8?B?' . base64_encode($subject) . "?=\r\n"
            . 'Date: ' . date('r') . "\r\n"
            . "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n";
        $ok = $cmd($headers . "\r\n" . chunk_split(base64_encode($body)) . '.', '250');
    }
    fwrite($s, "QUIT\r\n");
    fclose($s);
    return $ok;
}

// Testen indlæser kun funktionerne
if (PHP_SAPI === 'cli') return;

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') reply(405, 'method');
// Appen sender altid JSON. Alt andet er ikke fra appen
if (!str_starts_with(strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? '')), 'application/json')) reply(415, 'type');
$raw = file_get_contents('php://input', false, null, 0, 4096) ?: '';
$in = json_decode($raw, true);
if (!is_array($in)) reply(400, 'json');
// Skjult felt. Appen sender det altid tomt, så kun robotter udfylder det.
if (clean($in['website'] ?? '') !== '') reply(200, 'ok');

$brand = clean($in['brand'] ?? '');
$model = clean($in['model'] ?? '');
$country = strtoupper(clean($in['country'] ?? ''));
$version = clean($in['version'] ?? '');
$lang = strtolower(clean($in['lang'] ?? ''));
if ($brand === '' && $model === '') reply(400, 'empty');
if ($country !== '' && !preg_match('/^[A-Z]{1,3}$/', $country)) $country = '';
if (!preg_match('/^[0-9.]{1,12}$/', $version)) $version = '';
if (!preg_match('/^[a-z]{2}$/', $lang)) $lang = '';

$dir = data_dir();
if (rate_limited($dir)) reply(429, 'later');

// Googles automatiske test i Play Console kører appen på emulatorer, som også sender forslag.
// De gemmes stadig, for forslaget kan være godt, men de markeres, så ejeren kan se det.
$ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
$source = preg_match('/sdk_gphone|google_sdk|\bEmulator\b|Android SDK built for/i', $ua) ? 'google' : '';

$csvFile = $dir . '/forslag.csv';
clearstatcache(true, $csvFile);
if (is_file($csvFile) && filesize($csvFile) > MAX_CSV_BYTES) reply(503, 'full');

$when = gmdate('Y-m-d H:i:s');
// Samme lås som siden behandl, som skriver filen om via en midlertidig fil
$lock = fopen($dir . '/behandl.lock', 'c');
if ($lock === false) reply(500, 'store');
flock($lock, LOCK_EX);
$fp = fopen($csvFile, 'a+');
if ($fp === false) reply(500, 'store');
flock($fp, LOCK_EX);
// Er filen rettet i hånden og gemt uden linjeskift til sidst, kommer det nye forslag ellers på samme linje
fseek($fp, -1, SEEK_END);
$last = ftell($fp) >= 0 ? fread($fp, 1) : '';
if ($last !== '' && $last !== false && $last !== "\n" && $last !== "\r") fwrite($fp, "\n");
// En apostrof foran = + - @ forhindrer, at feltet bliver en formel, når filen åbnes i et regneark
$safe = fn(string $v): string => preg_match('/^[=+\-@]/', $v) ? "'" . $v : $v;
fputcsv($fp, array_map($safe, [$when, $brand, $model, $country, $version, $lang, $source]), ',', '"', '');
flock($fp, LOCK_UN);
fclose($fp);
flock($lock, LOCK_UN);
fclose($lock);

$cfgFile = $dir . '/config.php';
$cfg = is_file($cfgFile) ? require $cfgFile : [];
// config.php kan også kun have adgangskoden til siden behandl. Så sendes ingen mail
if (is_array($cfg) && isset($cfg['user'], $cfg['pass'], $cfg['to'])) maybe_send_digest($dir, $cfg);
reply(200, 'ok');
