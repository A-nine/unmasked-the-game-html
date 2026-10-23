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

// Sends with the envelope sender set to our own domain (-f) so receiving servers accept it,
// and records every attempt in mail-log.txt next to waitlist.csv for troubleshooting.
function send_mail(string $dir, string $to, string $subject, string $body): void
{
    $headers = "From: Unmasked <" . FROM_EMAIL . ">\r\n"
        . "Reply-To: " . TEAM_EMAIL . "\r\n"
        . "MIME-Version: 1.0\r\n"
        . "Content-Type: text/plain; charset=utf-8\r\n"
        . "Content-Transfer-Encoding: 8bit";
    $subject = '=?UTF-8?B?' . base64_encode($subject) . '?=';

    $error = '';
    if (!function_exists('mail')) {
        $ok = false;
        $error = 'mail() is disabled on this server';
    } else {
        set_error_handler(function ($no, $msg) use (&$error) { $error = $msg; return true; });
        $ok = mail($to, $subject, $body, $headers, '-f' . FROM_EMAIL);
        restore_error_handler();
    }
    file_put_contents($dir . '/mail-log.txt',
        date('Y-m-d H:i') . "  " . ($ok ? 'HANDED TO MAIL SERVER' : 'FAILED') . "  to $to"
        . ($error ? "  ($error)" : '') . "\n", FILE_APPEND | LOCK_EX);
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
