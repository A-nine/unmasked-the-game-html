<?php
/**
 * Unmasked waitlist sign-up handler.
 *
 * Saves each sign-up as a row in waitlist.csv (opens in Excel / Google Sheets) and,
 * optionally, emails the team and the person who signed up.
 *
 * The CSV is kept OUTSIDE the website folder, in your cPanel home directory:
 *   /home/<your-cpanel-user>/unmasked-waitlist-data/waitlist.csv
 * Download it any time from cPanel → File Manager.
 */

// ---------------------------------------------------------------- settings
const TEAM_EMAIL       = 'hello@unmasked.ng';        // receives a note for every sign-up
const FROM_EMAIL       = 'no-reply@unmasked.ng';     // must be an address on your domain
const NOTIFY_TEAM      = true;                       // email the team on each sign-up
const CONFIRM_SIGNUP   = true;                       // email the person a "you're on the list" note
const MAX_PER_HOUR     = 10;                         // sign-ups allowed from one connection per hour
const TIMEZONE         = 'Africa/Lagos';

const STATES = ['Abia', 'Adamawa', 'Akwa Ibom', 'Anambra', 'Bauchi', 'Bayelsa', 'Benue', 'Borno', 'Cross River',
    'Delta', 'Ebonyi', 'Edo', 'Ekiti', 'Enugu', 'FCT, Abuja', 'Gombe', 'Imo', 'Jigawa', 'Kaduna', 'Kano', 'Katsina',
    'Kebbi', 'Kogi', 'Kwara', 'Lagos', 'Nasarawa', 'Niger', 'Ogun', 'Ondo', 'Osun', 'Oyo', 'Plateau', 'Rivers',
    'Sokoto', 'Taraba', 'Yobe', 'Zamfara', 'Outside Nigeria'];

const INTERESTS = ['An individual or community member', 'An organisation (NGO, company, faith body, school)',
    'A facilitator', 'A partner or funder', 'Media'];

// ---------------------------------------------------------------- helpers
date_default_timezone_set(TIMEZONE);

// A few hosts ship PHP without the mbstring extension
if (!function_exists('mb_substr')) {
    function mb_substr($s, $start, $len) { return substr($s, $start, $len); }
    function mb_strlen($s) { return strlen($s); }
    function mb_strtolower($s) { return strtolower($s); }
}

function wants_json(): bool
{
    return strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false;
}

function respond(bool $ok, string $message = '', int $status = 200): void
{
    http_response_code($status);
    if (wants_json()) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => $ok, 'message' => $message]);
    } elseif ($ok) {
        header('Location: thanks.html', true, 303);
    } else {
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>Unmasked waitlist</title><body style="font-family:sans-serif;padding:2rem;max-width:36rem">'
            . '<p>' . htmlspecialchars($message) . '</p><p><a href="./">Go back to the waitlist</a></p></body>';
    }
    exit;
}

function data_dir(): string
{
    $home = getenv('HOME');
    if ((!$home || !is_dir($home)) && function_exists('posix_getpwuid')) {
        $home = posix_getpwuid(posix_geteuid())['dir'] ?? '';
    }
    $dir = ($home && is_dir($home) && is_writable($home))
        ? rtrim($home, '/') . '/unmasked-waitlist-data'
        : __DIR__ . '/data'; // fallback, locked down by data/.htaccess
    if (!is_dir($dir) && !mkdir($dir, 0700, true)) {
        respond(false, 'The waitlist is temporarily unavailable. Please email ' . TEAM_EMAIL . '.', 500);
    }
    return $dir;
}

function clean(string $key, int $max): string
{
    $v = trim((string)($_POST[$key] ?? ''));
    $v = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $v);   // no control characters or line breaks
    return mb_substr($v, 0, $max);
}

// Stop spreadsheet apps treating a value as a formula
function csv_safe(string $v): string
{
    return preg_match('/^[=+\-@\t\r]/', $v) ? "'" . $v : $v;
}

// ---------------------------------------------------------------- request
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Location: ./', true, 303);
    exit;
}

// Honeypot: people never see this field, bots fill it in. Pretend it worked.
if (clean('website', 200) !== '') {
    respond(true);
}

$name     = clean('name', 120);
$email    = mb_strtolower(clean('email', 190));
$phone    = clean('phone', 30);
$state    = clean('state', 60);
$interest = clean('interest', 80);

if ($name === '' || mb_strlen($name) < 2) {
    respond(false, 'Please enter your name.', 422);
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    respond(false, 'Please enter a valid email address.', 422);
}
if ($phone !== '' && !preg_match('/^[0-9+()\s.-]{7,30}$/', $phone)) {
    respond(false, 'Please enter a valid WhatsApp number, or leave it blank.', 422);
}
if (!in_array($state, STATES, true)) {
    respond(false, 'Please choose your state.', 422);
}
if (!in_array($interest, INTERESTS, true)) {
    respond(false, 'Please tell us how you are interested.', 422);
}

$dir = data_dir();

// ---- rate limit (stores only a one-way hash of the connection, never the IP itself)
$limitFile = $dir . '/rate-limit.json';
$who  = hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . '|unmasked-waitlist');
$now  = time();
$fh   = fopen($limitFile, 'c+');
if ($fh && flock($fh, LOCK_EX)) {
    $hits = json_decode(stream_get_contents($fh) ?: '{}', true) ?: [];
    foreach ($hits as $k => $times) {                       // forget anything older than an hour
        $hits[$k] = array_values(array_filter($times, function ($t) use ($now) { return $t > $now - 3600; }));
        if (!$hits[$k]) unset($hits[$k]);
    }
    $tooMany = count($hits[$who] ?? []) >= MAX_PER_HOUR;
    if (!$tooMany) $hits[$who][] = $now;
    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, json_encode($hits));
    flock($fh, LOCK_UN);
    fclose($fh);
    if ($tooMany) {
        respond(false, 'Too many sign-ups from this connection. Please try again in an hour.', 429);
    }
}

// ---- save
$csv   = $dir . '/waitlist.csv';
$isNew = !file_exists($csv);
$fh    = fopen($csv, 'a+');
if (!$fh || !flock($fh, LOCK_EX)) {
    respond(false, 'The waitlist is temporarily unavailable. Please email ' . TEAM_EMAIL . '.', 500);
}

// Already signed up? Treat as success so we never reveal who is on the list.
rewind($fh);
$already = false;
while (($row = fgetcsv($fh, 0, ',', '"', '')) !== false) {
    if (isset($row[2]) && mb_strtolower(trim($row[2])) === $email) {
        $already = true;
        break;
    }
}

if (!$already) {
    fseek($fh, 0, SEEK_END);
    if ($isNew) {
        fwrite($fh, "\xEF\xBB\xBF"); // UTF-8 marker so Excel shows names with accents correctly
        fputcsv($fh, ['Signed up (Lagos time)', 'Name', 'Email', 'WhatsApp', 'State', 'Interested as'], ',', '"', '');
    }
    fputcsv($fh, array_map('csv_safe', [date('Y-m-d H:i'), $name, $email, $phone, $state, $interest]), ',', '"', '');
    fflush($fh);
}
flock($fh, LOCK_UN);
fclose($fh);

// ---- emails (failures here never block the sign-up)

// Reads one SMTP reply (which may span several lines) and returns [code, text]
function smtp_read($sock): array
{
    $text = '';
    while (($line = fgets($sock, 1024)) !== false) {
        $text .= $line;
        if (strlen($line) < 4 || $line[3] === ' ') break;
    }
    return [(int)substr($text, 0, 3), trim($text)];
}

// Sends one command and checks the reply code; throws with the server's answer if it is unexpected
function smtp_cmd($sock, ?string $cmd, array $expect, ?string $logAs = null): void
{
    if ($cmd !== null) fwrite($sock, $cmd . "\r\n");
    [$code, $text] = smtp_read($sock);
    if (!in_array($code, $expect, true)) {
        throw new RuntimeException(($logAs ?? strtok((string)$cmd, ' ') ?: 'CONNECT') . " -> $text");
    }
}

// Sends through the mailbox in mail-config.php (authenticated SMTP, like an email app does)
function smtp_send(array $cfg, string $to, string $message): void
{
    $port = (int)$cfg['port'];
    $ssl  = ($cfg['encryption'] ?? ($port === 465 ? 'ssl' : 'tls')) === 'ssl';
    $ctx = stream_context_create(['ssl' => [
        'verify_peer' => (bool)$cfg['verify_certificate'],
        'verify_peer_name' => (bool)$cfg['verify_certificate'],
        'peer_name' => $cfg['host'],
    ]]);
    $remote = ($ssl ? 'ssl://' : 'tcp://') . $cfg['host'] . ':' . $port;
    $warning = '';
    set_error_handler(function ($no, $msg) use (&$warning) { $warning .= ' ' . preg_replace('/^stream_socket_client\(\): /', '', $msg); return true; });
    $sock = stream_socket_client($remote, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
    restore_error_handler();
    if (!$sock) {
        $hint = stripos($warning, 'certificate') !== false ? ' [certificate problem: try verify_certificate => false]' : '';
        throw new RuntimeException("cannot connect to $remote: " . trim(($errstr ?: '') . $warning) . $hint);
    }
    stream_set_timeout($sock, 15);

    try {
        smtp_cmd($sock, null, [220]);
        smtp_cmd($sock, 'EHLO unmasked.ng', [250]);
        if (!$ssl) {
            smtp_cmd($sock, 'STARTTLS', [220]);
            if (!stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('STARTTLS -> could not start encryption');
            }
            smtp_cmd($sock, 'EHLO unmasked.ng', [250]);
        }
        smtp_cmd($sock, 'AUTH LOGIN', [334]);
        smtp_cmd($sock, base64_encode($cfg['username']), [334], 'AUTH username');
        smtp_cmd($sock, base64_encode($cfg['password']), [235], 'AUTH password');
        smtp_cmd($sock, 'MAIL FROM:<' . FROM_EMAIL . '>', [250]);
        smtp_cmd($sock, "RCPT TO:<$to>", [250, 251]);
        smtp_cmd($sock, 'DATA', [354]);
        // a line starting with "." would end the message early, so double it
        fwrite($sock, preg_replace('/^\./m', '..', $message) . "\r\n.\r\n");
        smtp_cmd($sock, null, [250], 'DATA end');
        fwrite($sock, "QUIT\r\n");
    } finally {
        fclose($sock);
    }
}

function mail_config(): ?array
{
    $file = __DIR__ . '/mail-config.php';
    $cfg = is_file($file) ? include $file : null;
    if (!is_array($cfg) || empty($cfg['password']) || strpos($cfg['password'], 'PUT-THE') === 0) return null;
    return $cfg + ['port' => 465, 'verify_certificate' => true];
}

// Sends one email (SMTP if mail-config.php is filled in, otherwise PHP mail())
// and records the outcome in mail-log.txt next to waitlist.csv.
function send_mail(string $dir, string $to, string $subject, string $body): void
{
    $subject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $headers = "From: Unmasked <" . FROM_EMAIL . ">\r\n"
        . "Reply-To: " . TEAM_EMAIL . "\r\n"
        . "MIME-Version: 1.0\r\n"
        . "Content-Type: text/plain; charset=utf-8\r\n"
        . "Content-Transfer-Encoding: quoted-printable";
    $body = quoted_printable_encode(str_replace("\n", "\r\n", $body));

    $cfg = mail_config();
    $via = $cfg ? 'SMTP' : 'mail()';
    $error = '';
    try {
        if ($cfg) {
            $message = "Date: " . date('r') . "\r\n"
                . "Message-ID: <" . bin2hex(random_bytes(12)) . "@unmasked.ng>\r\n"
                . "To: <$to>\r\nSubject: $subject\r\n$headers\r\n\r\n$body";
            smtp_send($cfg, $to, $message);
        } elseif (!function_exists('mail')) {
            throw new RuntimeException('mail() is disabled on this server; fill in mail-config.php');
        } else {
            set_error_handler(function ($no, $msg) use (&$error) { $error = $msg; return true; });
            $ok = mail($to, $subject, $body, $headers, '-f' . FROM_EMAIL);
            restore_error_handler();
            if (!$ok) throw new RuntimeException($error ?: 'mail() returned false; fill in mail-config.php to use SMTP');
        }
        $status = 'SENT';
    } catch (Throwable $e) {
        $status = 'FAILED';
        $error = $e->getMessage();
    }
    file_put_contents($dir . '/mail-log.txt',
        date('Y-m-d H:i') . "  $status via $via  to $to" . ($status === 'FAILED' ? "  ($error)" : '') . "\n",
        FILE_APPEND | LOCK_EX);
}

if (!$already) {
    if (NOTIFY_TEAM) {
        send_mail($dir, TEAM_EMAIL, 'New waitlist sign-up',
            "Name: $name\nEmail: $email\nWhatsApp: " . ($phone ?: '-') . "\nState: $state\nInterested as: $interest\n");
    }
    if (CONFIRM_SIGNUP) {
        send_mail($dir, $email, "You're on the Unmasked waitlist",
            "Hi $name,\n\nThank you for joining the Unmasked waitlist. We'll email you on launch day, and when sessions open near you.\n\n"
            . "What cannot be named cannot be challenged. Unmasked gives communities the language.\n\n"
            . "If you didn't sign up, reply to this email and we'll remove your details.\n\n"
            . "Unmasked\nhttps://unmasked.ng\n\n"
            . "Unmasked is an education programme, not a crisis service. If you or someone you know is in immediate danger, call 112.\n");
    }
}

respond(true, $already ? 'already' : 'joined');
