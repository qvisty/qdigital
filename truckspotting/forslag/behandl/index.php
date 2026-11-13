<?php
// Side til at behandle forslag til kataloget.
// Ligger på https://qdigital.dk/truckspotting/forslag/behandl/ (public_html/truckspotting/forslag/behandl/index.php).
// Et behandlet forslag flyttes fra forslag.csv til behandlet.csv med status, og det kan fortrydes. Kun en
// anmodning om sletning (api=delete) fjerner et forslag helt, som privatlivspolitikken lover.
// Adgangskoden ligger kun som hash i truckspotting-data/config.php under 'admin_hash'. Se server/README.md.

declare(strict_types=1);

const MAX_LOGIN_FAILS = 5;
const SHOWN_HANDLED = 30;
const COLUMNS = ['Tid (UTC)', 'Mærke', 'Serie', 'Land', 'Version', 'Sprog'];

/** Mappen uden for public_html. Den oprettes aldrig her, så en forkert sti ikke lægger filerne åbent. */
function data_dir(): string {
    $dir = getenv('TRUCKSPOTTING_DATA') ?: __DIR__ . '/../../../../truckspotting-data';
    if (!is_dir($dir)) {
        http_response_code(500);
        exit('Datamappen findes ikke. Se server/README.md.');
    }
    return $dir;
}

/** Skriver filen helt om via en midlertidig fil og rename, så den aldrig står halvt skrevet. */
function write_lines(string $file, array $lines): bool {
    $tmp = $file . '.tmp';
    if (file_put_contents($tmp, $lines ? implode("\n", $lines) . "\n" : '') === false) return false;
    return rename($tmp, $file);
}

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function config(): array {
    $file = data_dir() . '/config.php';
    if (!is_file($file)) return [];
    $cfg = require $file;
    return is_array($cfg) ? $cfg : [];
}

/** Læser alle ikke tomme linjer. Linjerne bevares præcis, som de står, så intet ændres ved en fejl. */
function lines_of(string $file): array {
    if (!is_file($file)) return [];
    $raw = file_get_contents($file);
    if ($raw === false || $raw === '') return [];
    $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw) ?? $raw;
    return array_values(array_filter(preg_split('/\r\n|\n|\r/', $raw) ?: [], fn($l) => trim($l) !== ''));
}

function id_of(string $line): string {
    return substr(hash('sha256', $line), 0, 20);
}

/**
 * Flytter den første linje med [$id] fra [$from] til [$to] under lås. [$transform] laver linjen om på vejen.
 * Med [$to] null slettes linjen. Alt sker under behandl.lock, som modtageren også tager, når den skriver.
 */
function move_line(string $from, ?string $to, string $id, callable $transform): bool {
    $dir = data_dir();
    $lock = fopen($dir . '/behandl.lock', 'c');
    if ($lock === false) return false;
    flock($lock, LOCK_EX);
    $lines = lines_of($dir . '/' . $from);
    $found = null;
    foreach ($lines as $i => $l) {
        if (id_of($l) === $id) { $found = $i; break; }
    }
    $ok = false;
    if ($found !== null) {
        $line = $lines[$found];
        $ok = $to === null || file_put_contents($dir . '/' . $to, $transform($line) . "\n", FILE_APPEND | LOCK_EX) !== false;
        if ($ok) {
            array_splice($lines, $found, 1);
            $ok = write_lines($dir . '/' . $from, $lines);
        }
    }
    flock($lock, LOCK_UN);
    fclose($lock);
    return $ok;
}

/** Samme regel som appens normalizedName: små bogstaver, kun bogstaver og tal. */
function norm(string $s): string {
    return preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower($s)) ?? '';
}

/** Appens katalog. Kopien katalog.json skal være den samme som i appen, det tjekker en test i repoet. */
function catalog(): array {
    static $brands = null;
    if ($brands !== null) return $brands;
    $json = @file_get_contents(__DIR__ . '/katalog.json');
    $data = $json === false ? null : json_decode($json, true);
    $brands = is_array($data['brands'] ?? null) ? $data['brands'] : [];
    return $brands;
}

const RARITY = ['COMMON' => 'almindelig', 'RARE' => 'sjælden', 'EPIC' => 'episk', 'LEGENDARY' => 'legendarisk'];

/**
 * Slår et forslag op i kataloget. Giver [klasse, tekst]: findes allerede, ligner en model, kun mærket
 * findes, eller helt nyt. "Ligner" er, når det ene navn indeholder det andet, fx "R" og "R serie".
 */
function lookup(string $brand, string $model): array {
    $b = norm($brand);
    $m = norm($model);
    $found = null;
    foreach (catalog() as $cb) {
        $n = norm((string) ($cb['name'] ?? ''));
        if ($n !== '' && $b !== '' && ($n === $b || str_contains($n, $b) || str_contains($b, $n))) { $found = $cb; break; }
    }
    if ($found === null) return ['new', 'Nyt mærke. Findes ikke i appen.'];
    $similar = [];
    foreach ($found['models'] ?? [] as $cm) {
        $names = array_filter([norm((string) ($cm['name'] ?? '')), norm((string) ($cm['nameEn'] ?? ''))]);
        $label = $found['name'] . ' ' . ($cm['name'] ?? '') . ' (' . (RARITY[$cm['rarity'] ?? ''] ?? '') . ')';
        if ($m !== '' && in_array($m, $names, true)) return ['have', 'Findes allerede i appen: ' . $label];
        foreach ($names as $n) {
            if ($m !== '' && (str_contains($n, $m) || str_contains($m, $n))) { $similar[] = $label; break; }
        }
    }
    if ($similar) return ['like', 'Ligner: ' . implode(', ', $similar)];
    return ['brand', $found['name'] . ' findes i appen, men ikke denne model.'];
}

/** Et behandlet forslag er: status, tidspunkt og derefter den oprindelige linje uændret. */
function handled_line(string $status, string $line): string {
    return $status . ',' . gmdate('Y-m-d H:i:s') . ',' . $line;
}

function original_of(string $handled): string {
    $parts = explode(',', $handled, 3);
    return $parts[2] ?? $handled;
}

/**
 * Spærrer efter MAX_LOGIN_FAILS forkerte forsøg fra samme afsender den seneste time. Vinduet ruller, så en spærring
 * ikke nulstilles ved et timeskift. Login på siden ([$kind] "admin") og nøglen ("api") tælles hver for sig.
 */
function login_blocked(string $kind, bool $failed): bool {
    $key = $kind . ':' . substr(hash('sha256', 'behandl' . ($_SERVER['REMOTE_ADDR'] ?? '')), 0, 16);
    $file = data_dir() . '/login.json';
    $now = time();
    $fp = fopen($file, 'c+');
    if ($fp === false) return true;
    flock($fp, LOCK_EX);
    $data = json_decode(stream_get_contents($fp) ?: '{}', true) ?: [];
    $fails = is_array($data['fails'] ?? null) ? $data['fails'] : [];
    foreach ($fails as $k => $times) {
        $fails[$k] = array_values(array_filter(is_array($times) ? $times : [], fn($t) => is_int($t) && $t > $now - 3600));
        if (!$fails[$k]) unset($fails[$k]);
    }
    if ($failed) $fails[$key][] = $now;
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode(['fails' => $fails]));
    flock($fp, LOCK_UN);
    fclose($fp);
    return count($fails[$key] ?? []) >= MAX_LOGIN_FAILS;
}

header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; base-uri 'none'");

$cfg = config();
$hash = is_string($cfg['admin_hash'] ?? null) ? $cfg['admin_hash'] : '';
$post = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
$action = $post ? (string) ($_POST['action'] ?? '') : '';
$message = '';

// Adgang for Claude med en nøgle i headeren X-Api-Key (eller Authorization: Bearer). Kun hashen af nøglen ligger i
// config.php under 'api_hash'. Svarer med JSON og bruger hverken session eller cookies.
$apiHash = is_string($cfg['api_hash'] ?? null) ? $cfg['api_hash'] : '';
$auth = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
$apiCall = (string) ($_GET['api'] ?? $_POST['api'] ?? '');
if ($apiCall !== '') {
    header('Content-Type: application/json; charset=utf-8');
    $token = trim((string) ($_SERVER['HTTP_X_API_KEY'] ?? ''));
    if ($token === '' && preg_match('/^Bearer\s+(\S+)$/', $auth, $m)) $token = $m[1];
    // Under en spærring tælles forsøg ikke, så spærringen ikke forlænges ved hvert forsøg
    $blocked = $token !== '' && login_blocked('api', false);
    if ($apiHash === '' || $token === '' || $blocked || !password_verify($token, $apiHash)) {
        if ($token !== '' && !$blocked) login_blocked('api', true);
        http_response_code(401);
        exit(json_encode(['status' => 'unauthorized']));
    }
    $dir = data_dir();
    $row = function (string $line, bool $handled): array {
        $f = str_getcsv($line, ',', '"', '');
        if ($handled) {
            $status = $f[0] ?? '';
            $at = $f[1] ?? '';
            $f = array_slice($f, 2);
        }
        $out = ['id' => id_of($line), 'when' => $f[0] ?? '', 'brand' => $f[1] ?? '', 'model' => $f[2] ?? '', 'country' => $f[3] ?? '',
            'version' => $f[4] ?? '', 'lang' => $f[5] ?? '', 'source' => $f[6] ?? ''];
        if ($handled) {
            $out['status'] = $status;
            $out['handled_at'] = $at;
        } else {
            [$kind, $text] = lookup($out['brand'], $out['model']);
            $out['lookup'] = ['kind' => $kind, 'text' => $text];
        }
        return $out;
    };
    if ($apiCall === 'list') {
        exit(json_encode([
            'open' => array_map(fn($l) => $row($l, false), lines_of($dir . '/forslag.csv')),
            'handled' => array_map(fn($l) => $row($l, true), array_slice(array_reverse(lines_of($dir . '/behandlet.csv')), 0, 50)),
        ], JSON_UNESCAPED_UNICODE));
    }
    if (!$post) {
        http_response_code(405);
        exit(json_encode(['status' => 'method']));
    }
    $id = (string) ($_POST['id'] ?? '');
    $ok = match ($apiCall) {
        'added' => move_line('forslag.csv', 'behandlet.csv', $id, fn($l) => handled_line('tilføjet', $l)),
        'rejected' => move_line('forslag.csv', 'behandlet.csv', $id, fn($l) => handled_line('afvist', $l)),
        'undo' => move_line('behandlet.csv', 'forslag.csv', $id, fn($l) => original_of($l)),
        // Anmodning om sletning: forslaget fjernes helt, uanset om det er behandlet
        'delete' => move_line('forslag.csv', null, $id, fn($l) => $l) || move_line('behandlet.csv', null, $id, fn($l) => $l),
        default => null,
    };
    if ($ok === null) {
        http_response_code(400);
        exit(json_encode(['status' => 'unknown']));
    }
    http_response_code($ok ? 200 : 404);
    exit(json_encode(['status' => $ok ? 'ok' : 'missing']));
}

// Session og cookie kun for siden, ikke for nøglen. Cookien gælder kun denne mappe
$https = ($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off';
$cookiePath = rtrim(dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php')), '/') . '/';
session_name('tsbehandl');
session_set_cookie_params(['lifetime' => 0, 'path' => $cookiePath, 'secure' => $https, 'httponly' => true, 'samesite' => 'Strict']);
session_start();

if ($post && $action !== 'login' && $action !== 'makehash'
    && !hash_equals((string) ($_SESSION['csrf'] ?? ''), (string) ($_POST['csrf'] ?? ''))) {
    http_response_code(400);
    exit('Siden var for gammel. Gå tilbage og prøv igen.');
}

if ($action === 'logout') {
    $_SESSION = [];
    session_destroy();
    header('Location: ./', true, 303);
    exit;
}

if ($action === 'login' && $hash !== '') {
    if (login_blocked('admin', false)) {
        $message = 'For mange forkerte forsøg. Prøv igen om en time.';
    } elseif (password_verify((string) ($_POST['password'] ?? ''), $hash)) {
        session_regenerate_id(true);
        $_SESSION['ok'] = true;
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
        header('Location: ./', true, 303);
        exit;
    } else {
        login_blocked('admin', true);
        $message = 'Forkert adgangskode.';
    }
}

$logged_in = ($_SESSION['ok'] ?? false) === true && $hash !== '';

if ($logged_in && $post) {
    $id = (string) ($_POST['id'] ?? '');
    if ($action === 'added' || $action === 'rejected') {
        $status = $action === 'added' ? 'tilføjet' : 'afvist';
        $ok = move_line('forslag.csv', 'behandlet.csv', $id, fn($l) => handled_line($status, $l));
        $_SESSION['flash'] = $ok ? "Markeret som $status." : 'Forslaget findes ikke længere. Intet er ændret.';
    } elseif ($action === 'makekey') {
        // Nøglen vises kun denne ene gang. Siden gemmer den ikke, kun den hash, ejeren selv lægger i GitHub
        $key = bin2hex(random_bytes(24));
        $_SESSION['newkey'] = ['key' => $key, 'hash' => password_hash($key, PASSWORD_DEFAULT)];
    } elseif ($action === 'undo') {
        $ok = move_line('behandlet.csv', 'forslag.csv', $id, fn($l) => original_of($l));
        $_SESSION['flash'] = $ok ? 'Fortrudt. Forslaget er tilbage på listen.' : 'Kunne ikke finde det. Intet er ændret.';
    }
    header('Location: ./', true, 303);
    exit;
}

$flash = (string) ($_SESSION['flash'] ?? '');
unset($_SESSION['flash']);
$newKey = $_SESSION['newkey'] ?? null;
unset($_SESSION['newkey']);
?>
<!doctype html>
<html lang="da">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Truckspotting forslag</title>
<style>
  :root { --bg: #f6f6f8; --card: #fff; --fg: #1b1b1f; --muted: #5b5b66; --line: #dcdce3; --ok: #1f7a3a; --no: #a32b2b; }
  @media (prefers-color-scheme: dark) { :root { --bg: #111116; --card: #1b1b22; --fg: #ececf1; --muted: #a4a4b0; --line: #33333d; --ok: #5fd07f; --no: #ff8a8a; } }
  body { background: var(--bg); color: var(--fg); font: 16px/1.45 system-ui, sans-serif; margin: 0 auto; max-width: 46rem; padding: 16px; }
  h1 { font-size: 1.4rem; margin: 0 0 4px; } h2 { font-size: 1.1rem; margin-top: 28px; }
  .muted { color: var(--muted); } .flash { background: var(--card); border-left: 4px solid var(--ok); padding: 10px 12px; margin: 12px 0; }
  .err { border-left-color: var(--no); }
  .card { background: var(--card); border: 1px solid var(--line); border-radius: 12px; padding: 12px; margin: 10px 0; }
  .name { font-weight: 700; font-size: 1.05rem; overflow-wrap: anywhere; }
  .row { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 10px; }
  button { font: inherit; border-radius: 10px; border: 1px solid var(--line); background: var(--card); color: var(--fg); padding: 10px 14px; cursor: pointer; }
  .ok { border-color: var(--ok); color: var(--ok); font-weight: 600; } .no { border-color: var(--no); color: var(--no); font-weight: 600; }
  .tag { display: inline-block; font-size: .8rem; font-weight: 700; padding: 2px 8px; border-radius: 99px; border: 1px solid var(--line); margin-right: 6px; }
  .google { color: #b26a00; border-color: #b26a00; }
  .look { margin-top: 6px; font-size: .95rem; } .look-have { color: var(--ok); } .look-like { color: #b26a00; } .look-new, .look-brand { color: var(--fg); font-weight: 600; }
  .status-tilføjet { color: var(--ok); font-weight: 600; } .status-afvist { color: var(--no); font-weight: 600; }
  input[type=password], input[type=text] { font: inherit; padding: 10px; border-radius: 10px; border: 1px solid var(--line); width: 100%; box-sizing: border-box; background: var(--card); color: var(--fg); }
  code { overflow-wrap: anywhere; } header { display: flex; justify-content: space-between; align-items: center; gap: 12px; }
</style>
</head>
<body>
<?php if ($hash === ''): ?>
  <h1>Opsætning</h1>
  <p>Siden har endnu ingen adgangskode. Skriv den adgangskode, du vil bruge. Siden gemmer den ikke, men viser en linje, som du selv sætter ind i <code>truckspotting-data/config.php</code> før <code>];</code>.</p>
  <?php if ($action === 'makehash' && strlen((string) ($_POST['password'] ?? '')) >= 10): ?>
    <div class="card"><code>'admin_hash' =&gt; '<?= h(password_hash((string) $_POST['password'], PASSWORD_DEFAULT)) ?>',</code></div>
  <?php elseif ($action === 'makehash'): ?>
    <div class="flash err">Brug mindst 10 tegn.</div>
  <?php endif; ?>
  <form method="post"><input type="hidden" name="action" value="makehash">
    <input type="password" name="password" autocomplete="new-password" placeholder="Ny adgangskode, mindst 10 tegn" required minlength="10">
    <div class="row"><button class="ok">Lav linjen</button></div>
  </form>
<?php elseif (!$logged_in): ?>
  <h1>Truckspotting forslag</h1>
  <?php if ($message !== ''): ?><div class="flash err"><?= h($message) ?></div><?php endif; ?>
  <form method="post"><input type="hidden" name="action" value="login">
    <input type="password" name="password" autocomplete="current-password" placeholder="Adgangskode" required autofocus>
    <div class="row"><button class="ok">Log ind</button></div>
  </form>
<?php else:
    $csrf = h((string) $_SESSION['csrf']);
    $open = lines_of(data_dir() . '/forslag.csv');
    $handled = array_reverse(lines_of(data_dir() . '/behandlet.csv'));
?>
  <header>
    <div><h1>Forslag til kataloget</h1><div class="muted"><?= count($open) ?> venter, <?= count($handled) ?> behandlet</div></div>
    <form method="post"><input type="hidden" name="csrf" value="<?= $csrf ?>"><button name="action" value="logout">Log ud</button></form>
  </header>
  <?php if ($flash !== ''): ?><div class="flash"><?= h($flash) ?></div><?php endif; ?>

  <?php if (!$open): ?><p class="muted">Ingen forslag venter.</p><?php endif; ?>
  <?php foreach ($open as $line): $f = str_getcsv($line, ',', '"', ''); [$lk, $lt] = lookup($f[1] ?? '', $f[2] ?? ''); ?>
    <div class="card">
      <?php if (($f[6] ?? '') === 'google'): ?><span class="tag google">Google test</span><?php endif; ?>
      <div class="name"><?= h(trim(($f[1] ?? '') . ' ' . ($f[2] ?? ''))) ?: '(tomt)' ?></div>
      <div class="muted"><?= h(implode(' · ', array_filter([$f[0] ?? '', $f[3] ?? '', isset($f[4]) && $f[4] !== '' ? 'v' . $f[4] : '', $f[5] ?? ''], fn($v) => $v !== ''))) ?></div>
      <div class="look look-<?= h($lk) ?>"><?= h($lt) ?></div>
      <form method="post" class="row">
        <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="id" value="<?= h(id_of($line)) ?>">
        <button class="ok" name="action" value="added">Tilføjet</button>
        <button class="no" name="action" value="rejected">Afvist</button>
      </form>
    </div>
  <?php endforeach; ?>

  <?php if ($handled): ?>
    <h2>Behandlet</h2>
    <p class="muted">Intet slettes. Fortryd lægger forslaget tilbage på listen. Viser de seneste <?= SHOWN_HANDLED ?>.</p>
    <?php foreach (array_slice($handled, 0, SHOWN_HANDLED) as $line):
        $f = str_getcsv($line, ',', '"', ''); $status = $f[0] ?? ''; ?>
      <div class="card">
        <div><span class="status-<?= h($status) ?>"><?= h(ucfirst($status)) ?></span> <span class="muted"><?= h($f[1] ?? '') ?></span></div>
        <div class="name"><?= h(trim(($f[3] ?? '') . ' ' . ($f[4] ?? ''))) ?></div>
        <form method="post" class="row">
          <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="id" value="<?= h(id_of($line)) ?>">
          <button name="action" value="undo">Fortryd</button>
        </form>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>

  <h2>Nøgle til Claude</h2>
  <p class="muted">Med en nøgle kan Claude selv hente forslag, tjekke dem mod kataloget og markere dem. Nøglen vises kun én gang. Hashen skal i GitHub hemmeligheden <code>TRUCKSPOTTING_API_HASH</code>, og selve nøglen skal i den sikre formular, som Claude viser dig. Skriv aldrig nøglen i chatten.</p>
  <?php if (is_array($newKey)): ?>
    <div class="card"><div class="muted">Nøgle</div><code><?= h($newKey['key']) ?></code></div>
    <div class="card"><div class="muted">Hash til GitHub</div><code><?= h($newKey['hash']) ?></code></div>
  <?php endif; ?>
  <form method="post" class="row"><input type="hidden" name="csrf" value="<?= $csrf ?>">
    <button name="action" value="makekey"><?= $apiHash === '' ? 'Lav nøgle' : 'Lav ny nøgle' ?></button>
  </form>
<?php endif; ?>
</body>
</html>
