<?php
/*
 * Traitement du formulaire de contact : envoie le message par e-mail au cabinet.
 * Aucun service tiers n'est utilisé et le message n'est pas enregistré sur le site.
 * Réglages : config.php
 */
declare(strict_types=1);

header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

$cfg = require __DIR__ . '/config.php';

$lang = (isset($_POST['lang']) && in_array($_POST['lang'], ['fr', 'en', 'es'], true)) ? $_POST['lang'] : 'fr';

$MSG = [
    'fr' => [
        'method'  => 'Méthode non autorisée.',
        'missing' => 'Merci de remplir tous les champs obligatoires.',
        'email'   => 'Adresse e-mail invalide.',
        'consent' => 'Merci d\'accepter la politique de confidentialité.',
        'long'    => 'Le message est trop long.',
        'rate'    => 'Trop de messages envoyés. Veuillez réessayer plus tard.',
        'send'    => 'L\'envoi a échoué. Veuillez réessayer ou contacter le cabinet par téléphone.',
        'ok'      => 'Merci pour votre message. Le cabinet vous répondra dans les meilleurs délais.',
        'back'    => 'Retour au formulaire',
    ],
    'en' => [
        'method'  => 'Method not allowed.',
        'missing' => 'Please fill in all required fields.',
        'email'   => 'Invalid email address.',
        'consent' => 'Please accept the privacy policy.',
        'long'    => 'The message is too long.',
        'rate'    => 'Too many messages sent. Please try again later.',
        'send'    => 'Sending failed. Please try again or contact the firm by phone.',
        'ok'      => 'Thank you for your message. The firm will reply as soon as possible.',
        'back'    => 'Back to the form',
    ],
    'es' => [
        'method'  => 'Método no permitido.',
        'missing' => 'Por favor, complete todos los campos obligatorios.',
        'email'   => 'Dirección de correo electrónico no válida.',
        'consent' => 'Por favor, acepte la política de privacidad.',
        'long'    => 'El mensaje es demasiado largo.',
        'rate'    => 'Demasiados mensajes enviados. Inténtelo de nuevo más tarde.',
        'send'    => 'El envío ha fallado. Inténtelo de nuevo o contacte con el despacho por teléfono.',
        'ok'      => 'Gracias por su mensaje. El despacho le responderá a la mayor brevedad posible.',
        'back'    => 'Volver al formulario',
    ],
];
$T = $MSG[$lang];
$BACK = ['fr' => 'contact.html', 'en' => 'contact-en.html', 'es' => 'contact-es.html'][$lang];

$wantsJson = isset($_SERVER['HTTP_ACCEPT']) && stripos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false;

function finish(bool $ok, int $status, string $message, bool $wantsJson, string $back, string $backLabel): void
{
    http_response_code($status);
    if ($wantsJson) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($ok ? ['ok' => true] : ['ok' => false, 'errors' => [['message' => $message]]], JSON_UNESCAPED_UNICODE);
    } else {
        header('Content-Type: text/html; charset=utf-8');
        $m = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
        $b = htmlspecialchars($back, ENT_QUOTES, 'UTF-8');
        $l = htmlspecialchars($backLabel, ENT_QUOTES, 'UTF-8');
        echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">'
           . '<meta name="robots" content="noindex"><title>Contact</title></head>'
           . '<body style="font-family:sans-serif;max-width:600px;margin:80px auto;padding:0 20px;text-align:center">'
           . '<p>' . $m . '</p><p><a href="' . $b . '">' . $l . '</a></p></body></html>';
    }
    exit;
}

function clean(mixed $value, int $max, bool $singleLine): string
{
    $s = trim((string) $value);
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s) ?? '';
    if ($singleLine) {
        $s = str_replace(["\r", "\n"], ' ', $s);
    }
    return mb_substr($s, 0, $max);
}

function rate_limited(int $max): bool
{
    $ip   = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $file = sys_get_temp_dir() . '/contact_rl_' . hash('sha256', $ip . __FILE__);
    $now  = time();
    $fp   = @fopen($file, 'c+');
    if (!$fp) {
        return false;
    }
    flock($fp, LOCK_EX);
    $raw   = stream_get_contents($fp);
    $times = $raw ? (json_decode($raw, true) ?: []) : [];
    $times = array_values(array_filter($times, fn($t) => is_int($t) && $t > $now - 3600));
    $limited = count($times) >= $max;
    if (!$limited) {
        $times[] = $now;
    }
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($times));
    flock($fp, LOCK_UN);
    fclose($fp);
    return $limited;
}

function smtp_expect($fp, array $codes): string
{
    $data = '';
    while (($line = fgets($fp, 515)) !== false) {
        $data .= $line;
        if (strlen($line) < 4 || $line[3] === ' ') {
            break;
        }
    }
    if (!in_array(substr($data, 0, 3), $codes, true)) {
        throw new RuntimeException('SMTP : ' . trim($data));
    }
    return $data;
}

function smtp_cmd($fp, string $cmd, array $codes): string
{
    fwrite($fp, $cmd . "\r\n");
    return smtp_expect($fp, $codes);
}

function smtp_send(array $cfg, string $from, string $to, string $message): void
{
    $secure = $cfg['smtp_secure'] ?? 'ssl';
    $scheme = ($secure === 'ssl') ? 'ssl' : 'tcp';
    $fp = @stream_socket_client($scheme . '://' . $cfg['smtp_host'] . ':' . (int) $cfg['smtp_port'], $errno, $errstr, 15);
    if (!$fp) {
        throw new RuntimeException('Connexion SMTP impossible : ' . $errstr);
    }
    stream_set_timeout($fp, 15);
    try {
        smtp_expect($fp, ['220']);
        $host = $_SERVER['SERVER_NAME'] ?? 'localhost';
        smtp_cmd($fp, 'EHLO ' . $host, ['250']);
        if ($secure === 'tls') {
            smtp_cmd($fp, 'STARTTLS', ['220']);
            if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('STARTTLS impossible');
            }
            smtp_cmd($fp, 'EHLO ' . $host, ['250']);
        }
        smtp_cmd($fp, 'AUTH LOGIN', ['334']);
        smtp_cmd($fp, base64_encode($cfg['smtp_user']), ['334']);
        smtp_cmd($fp, base64_encode($cfg['smtp_pass']), ['235']);
        smtp_cmd($fp, 'MAIL FROM:<' . $from . '>', ['250']);
        smtp_cmd($fp, 'RCPT TO:<' . $to . '>', ['250', '251']);
        smtp_cmd($fp, 'DATA', ['354']);
        $message = str_replace(["\r\n", "\r"], "\n", $message);
        $message = preg_replace('/^\./m', '..', $message);
        $message = str_replace("\n", "\r\n", $message);
        fwrite($fp, $message . "\r\n.\r\n");
        smtp_expect($fp, ['250']);
        @smtp_cmd($fp, 'QUIT', ['221']);
    } finally {
        fclose($fp);
    }
}

// ---------------------------------------------------------------- requête
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    finish(false, 405, $T['method'], $wantsJson, $BACK, $T['back']);
}

// Champ piège invisible : un robot le remplit, un visiteur non. On répond « ok » sans rien envoyer.
if (!empty($_POST['_gotcha'])) {
    finish(true, 200, $T['ok'], $wantsJson, $BACK, $T['back']);
}

$nom       = clean($_POST['nom'] ?? '', 100, true);
$prenom    = clean($_POST['prenom'] ?? '', 100, true);
$telephone = clean($_POST['telephone'] ?? '', 40, true);
$email     = clean($_POST['email'] ?? '', 200, true);
$objet     = clean($_POST['_subject'] ?? '', 200, true);
$message   = clean($_POST['message'] ?? '', 5001, false);

if ($nom === '' || $prenom === '' || $telephone === '' || $email === '' || $objet === '' || $message === '') {
    finish(false, 400, $T['missing'], $wantsJson, $BACK, $T['back']);
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    finish(false, 400, $T['email'], $wantsJson, $BACK, $T['back']);
}
if (empty($_POST['rgpd'])) {
    finish(false, 400, $T['consent'], $wantsJson, $BACK, $T['back']);
}
if (mb_strlen($message) > 5000) {
    finish(false, 400, $T['long'], $wantsJson, $BACK, $T['back']);
}
if (rate_limited((int) ($cfg['rate_limit'] ?? 5))) {
    finish(false, 429, $T['rate'], $wantsJson, $BACK, $T['back']);
}

// ---------------------------------------------------------------- message
$to   = $cfg['to'];
$from = $cfg['from'];
$subject = '=?UTF-8?B?' . base64_encode('[Site] ' . $objet) . '?=';

$body = "Nouveau message reçu via le formulaire du site.\n\n"
      . "Nom : $nom\n"
      . "Prénom : $prenom\n"
      . "Téléphone : $telephone\n"
      . "E-mail : $email\n"
      . "Objet : $objet\n"
      . 'Langue du formulaire : ' . $lang . "\n\n"
      . "Message :\n$message\n";

$replyName = '=?UTF-8?B?' . base64_encode($prenom . ' ' . $nom) . '?=';
$headers = [
    'From: =?UTF-8?B?' . base64_encode('Site jhelleboid-avocat.fr') . '?= <' . $from . '>',
    'Reply-To: ' . $replyName . ' <' . $email . '>',
    'MIME-Version: 1.0',
    'Content-Type: text/plain; charset=UTF-8',
    'Content-Transfer-Encoding: base64',
];
$encodedBody = chunk_split(base64_encode($body));

try {
    if (!empty($cfg['smtp_user'])) {
        $full = implode("\r\n", $headers)
              . "\r\nTo: <" . $to . '>'
              . "\r\nSubject: " . $subject
              . "\r\nDate: " . date('r')
              . "\r\nMessage-ID: <" . bin2hex(random_bytes(12)) . '@' . ($_SERVER['SERVER_NAME'] ?? 'localhost') . '>'
              . "\r\n\r\n" . $encodedBody;
        smtp_send($cfg, $from, $to, $full);
    } else {
        if (!mail($to, $subject, $encodedBody, implode("\r\n", $headers), '-f' . $from)) {
            throw new RuntimeException('mail() a échoué');
        }
    }
} catch (Throwable $e) {
    error_log('envoyer.php : ' . $e->getMessage());
    finish(false, 500, $T['send'], $wantsJson, $BACK, $T['back']);
}

finish(true, 200, $T['ok'], $wantsJson, $BACK, $T['back']);
