<?php
// Receives feedback from Q Digital apps and mails it to apps@qdigital.dk.
//
// POST application/json with the header X-App-Key:
//   { "app": "crowns", "type": "feedback|feature|bug", "message": "...",
//     "email": "optional reply address", "version": "1.1.0", "android": "15" }
//
// The SMTP password lives in api/config.php, which the deploy workflow writes
// from the GitHub secret FEEDBACK_SMTP_PASSWORD. It is never committed.
//
// The app key is not a secret (it ships inside the apps). It only keeps random
// bots out, together with the rate limit below.
const APP_KEYS = ['crowns' => 'a3bcd6e0a1bc73b6ac39ba58ed9d0dcc'];

header('Content-Type: application/json; charset=utf-8');

function reply(int $status, array $body): void {
    http_response_code($status);
    echo json_encode($body);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') reply(405, ['ok' => false, 'error' => 'POST only']);

$configFile = __DIR__ . '/config.php';
if (!is_file($configFile)) reply(503, ['ok' => false, 'error' => 'Not configured']);
$config = require $configFile;


// At most 5 messages per hour from the same address.
$bucket = sys_get_temp_dir() . '/qd-feedback-' . hash('sha256', $_SERVER['REMOTE_ADDR'] ?? '');
$recent = array_filter(
    is_file($bucket) ? array_map('intval', file($bucket)) : [],
    fn ($t) => $t > time() - 3600
);
if (count($recent) >= 5) reply(429, ['ok' => false, 'error' => 'Too many messages, try again later']);
$recent[] = time();
file_put_contents($bucket, implode("\n", $recent));

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) reply(400, ['ok' => false, 'error' => 'Invalid JSON']);

$clean = fn ($v, $max) => mb_substr(trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', (string) $v)), 0, $max);
$app = preg_replace('/[^a-z0-9-]/', '', strtolower($clean($input['app'] ?? '', 30)));
if (!isset(APP_KEYS[$app]) || !hash_equals(APP_KEYS[$app], $_SERVER['HTTP_X_APP_KEY'] ?? '')) {
    reply(403, ['ok' => false, 'error' => 'Unknown app']);
}
$types = ['feedback' => 'Feedback', 'feature' => 'Feature request', 'bug' => 'Bug report'];
$type = $types[$input['type'] ?? ''] ?? 'Feedback';
$message = $clean($input['message'] ?? '', 5000);
$email = $clean($input['email'] ?? '', 200);
$version = $clean($input['version'] ?? '', 30);
$android = $clean($input['android'] ?? '', 10);

if (mb_strlen($message) < 3) reply(400, ['ok' => false, 'error' => 'Message is empty']);
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) reply(400, ['ok' => false, 'error' => 'Invalid email']);

$subject = '[' . ucfirst($app) . '] ' . $type . ($email !== '' ? '' : ' (no reply address)');
$body = $message . "\n\n---\n"
    . 'App: ' . $app . ' ' . $version . "\n"
    . 'Android: ' . $android . "\n"
    . 'Reply to: ' . ($email !== '' ? $email : 'not given') . "\n";

$headers = [
    'From: Q Digital apps <' . $config['smtp_user'] . '>',
    'To: ' . $config['to'],
    'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=',
    'Date: ' . date('r'),
    'Message-ID: <' . bin2hex(random_bytes(12)) . '@qdigital.dk>',
    'MIME-Version: 1.0',
    'Content-Type: text/plain; charset=UTF-8',
    'Content-Transfer-Encoding: base64',
];
if ($email !== '') $headers[] = 'Reply-To: ' . $email;
$data = implode("\r\n", $headers) . "\r\n\r\n" . chunk_split(base64_encode($body));

try {
    smtp_send($config, $config['to'], $data);
} catch (Throwable $e) {
    error_log('feedback mail failed: ' . $e->getMessage());
    reply(502, ['ok' => false, 'error' => 'Could not send, please try again later']);
}
reply(200, ['ok' => true]);

/** Minimal SMTP client for websmtp.simply.com: STARTTLS on port 587 and AUTH LOGIN. */
function smtp_send(array $c, string $to, string $data): void {
    $s = stream_socket_client('tcp://' . $c['smtp_host'] . ':587', $errno, $errstr, 15);
    if (!$s) throw new RuntimeException("connect: $errstr");
    stream_set_timeout($s, 15);
    $read = function () use ($s) {
        $out = '';
        while (($line = fgets($s, 515)) !== false) {
            $out .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') break;
        }
        return $out;
    };
    $cmd = function (string $line, int $expect) use ($s, $read) {
        if ($line !== '') fwrite($s, $line . "\r\n");
        $res = $read();
        if ((int) substr($res, 0, 3) !== $expect) throw new RuntimeException(trim($res));
    };
    $cmd('', 220);
    $cmd('EHLO qdigital.dk', 250);
    $cmd('STARTTLS', 220);
    if (!stream_socket_enable_crypto($s, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) throw new RuntimeException('tls');
    $cmd('EHLO qdigital.dk', 250);
    $cmd('AUTH LOGIN', 334);
    $cmd(base64_encode($c['smtp_user']), 334);
    $cmd(base64_encode($c['smtp_pass']), 235);
    $cmd('MAIL FROM:<' . $c['smtp_user'] . '>', 250);
    $cmd('RCPT TO:<' . $to . '>', 250);
    $cmd('DATA', 354);
    // Dot stuffing, so a line that starts with "." cannot end the message early.
    $cmd(preg_replace('/^\./m', '..', $data) . "\r\n.", 250);
    $cmd('QUIT', 221);
    fclose($s);
}
