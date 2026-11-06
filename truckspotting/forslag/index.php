<?php
// Modtager forslag til Truckspottings katalog fra appen.
// Ligger på https://qdigital.dk/truckspotting/forslag/ (public_html/truckspotting/forslag/index.php).
// Gemmer hvert forslag i en CSV fil uden for public_html og sender det som mail til kontakt@qdigital.dk.
// Adgangen til SMTP ligger i config.php uden for public_html og aldrig i repoet. Se server/README.md.

declare(strict_types=1);

const MAX_FIELD = 40;
const MAX_PER_HOUR = 20;

function data_dir(): string {
    $dir = getenv('TRUCKSPOTTING_DATA') ?: __DIR__ . '/../../../truckspotting-data';
    if (!is_dir($dir)) mkdir($dir, 0700, true);
    return $dir;
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

/** Højst MAX_PER_HOUR forslag i timen fra samme afsender. IP adressen gemmes kun som en hash. */
function rate_limited(string $dir): bool {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $key = substr(hash('sha256', 'truckspotting' . $ip), 0, 16);
    $file = $dir . '/rate.json';
    $hour = (int) floor(time() / 3600);
    $fp = fopen($file, 'c+');
    if ($fp === false) return false;
    flock($fp, LOCK_EX);
    $data = json_decode(stream_get_contents($fp) ?: '{}', true) ?: [];
    if (($data['hour'] ?? 0) !== $hour) $data = ['hour' => $hour, 'count' => []];
    $count = ($data['count'][$key] ?? 0) + 1;
    $data['count'][$key] = $count;
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($data));
    flock($fp, LOCK_UN);
    fclose($fp);
    return $count > MAX_PER_HOUR;
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

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') reply(405, 'method');
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

$when = gmdate('Y-m-d H:i:s');
$fp = fopen($dir . '/forslag.csv', 'a+');
if ($fp === false) reply(500, 'store');
flock($fp, LOCK_EX);
// Er filen rettet i hånden og gemt uden linjeskift til sidst, kommer det nye forslag ellers på samme linje
fseek($fp, -1, SEEK_END);
$last = ftell($fp) >= 0 ? fread($fp, 1) : '';
if ($last !== '' && $last !== false && $last !== "\n" && $last !== "\r") fwrite($fp, "\n");
// En apostrof foran = + - @ forhindrer, at feltet bliver en formel, når filen åbnes i et regneark
$safe = fn(string $v): string => preg_match('/^[=+\-@]/', $v) ? "'" . $v : $v;
fputcsv($fp, array_map($safe, [$when, $brand, $model, $country, $version, $lang]), ',', '"', '');
flock($fp, LOCK_UN);
fclose($fp);

$cfgFile = $dir . '/config.php';
$cfg = is_file($cfgFile) ? require $cfgFile : [];
// config.php kan også kun have adgangskoden til siden behandl. Så sendes ingen mail
if (is_array($cfg) && isset($cfg['user'], $cfg['pass'], $cfg['to'])) {
    $body = "Nyt forslag til kataloget\n\n"
        . "Mærke: $brand\nSerie: $model\nLand: $country\nVersion: $version\nSprog: $lang\nTid (UTC): $when\n\n"
        . "Alle forslag ligger også i truckspotting-data/forslag.csv på webhotellet.\n";
    smtp_send($cfg, "Truckspotting forslag: $brand $model", $body);
}
reply(200, 'ok');
