<?php
/*
 * TEST D'ENVOI : à ouvrir une seule fois dans le navigateur après la mise en ligne
 * (adresse : https://www.jhelleboid-avocat.fr/test-envoi.php), puis À SUPPRIMER du serveur.
 * Il envoie un message de test à l'adresse « to » de config.php et affiche le résultat.
 */
declare(strict_types=1);
header('Content-Type: text/plain; charset=utf-8');
header('X-Robots-Tag: noindex');

$cfg = require __DIR__ . '/config.php';
$sujet = '=?UTF-8?B?' . base64_encode('[Site] Test d\'envoi') . '?=';
$corps = "Ceci est un test du formulaire de contact.\nSi vous lisez ce message, l'envoi fonctionne.\n";
$headers = [
    'From: <' . $cfg['from'] . '>',
    'MIME-Version: 1.0',
    'Content-Type: text/plain; charset=UTF-8',
    'Content-Transfer-Encoding: base64',
];
$encoded = chunk_split(base64_encode($corps));

echo 'Méthode : ' . (empty($cfg['smtp_user']) ? 'fonction mail() de PHP' : 'SMTP authentifié (' . $cfg['smtp_host'] . ':' . $cfg['smtp_port'] . ')') . "\n";

try {
    if (!empty($cfg['smtp_user'])) {
        $errno = 0; $errstr = '';
        $scheme = ($cfg['smtp_secure'] === 'ssl') ? 'ssl' : 'tcp';
        $fp = @stream_socket_client($scheme . '://' . $cfg['smtp_host'] . ':' . (int) $cfg['smtp_port'], $errno, $errstr, 15);
        if (!$fp) { throw new RuntimeException('Connexion impossible : ' . $errstr); }
        stream_set_timeout($fp, 15);
        $step = function (string $cmd = '', array $ok = ['250']) use ($fp) {
            if ($cmd !== '') { fwrite($fp, $cmd . "\r\n"); }
            $data = '';
            while (($l = fgets($fp, 515)) !== false) { $data .= $l; if (strlen($l) < 4 || $l[3] === ' ') { break; } }
            if (!in_array(substr($data, 0, 3), $ok, true)) { throw new RuntimeException('Réponse inattendue : ' . trim($data)); }
        };
        $step('', ['220']);
        $step('EHLO ' . ($_SERVER['SERVER_NAME'] ?? 'localhost'));
        if ($cfg['smtp_secure'] === 'tls') {
            $step('STARTTLS', ['220']);
            stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            $step('EHLO ' . ($_SERVER['SERVER_NAME'] ?? 'localhost'));
        }
        $step('AUTH LOGIN', ['334']);
        $step(base64_encode($cfg['smtp_user']), ['334']);
        $step(base64_encode($cfg['smtp_pass']), ['235']);
        $step('MAIL FROM:<' . $cfg['from'] . '>');
        $step('RCPT TO:<' . $cfg['to'] . '>', ['250', '251']);
        $step('DATA', ['354']);
        fwrite($fp, implode("\r\n", $headers) . "\r\nTo: <" . $cfg['to'] . ">\r\nSubject: " . $sujet . "\r\nDate: " . date('r') . "\r\n\r\n" . $encoded . "\r\n.\r\n");
        $step('', ['250']);
        $step('QUIT', ['221']);
        fclose($fp);
    } else {
        if (!mail($cfg['to'], $sujet, $encoded, implode("\r\n", $headers), '-f' . $cfg['from'])) {
            throw new RuntimeException('mail() a renvoyé une erreur');
        }
    }
    echo "RÉSULTAT : message de test envoyé à " . $cfg['to'] . ". Vérifiez la boîte de réception (et les indésirables).\n";
} catch (Throwable $e) {
    echo 'ÉCHEC : ' . $e->getMessage() . "\n";
}
echo "\nPensez à supprimer ce fichier (test-envoi.php) du serveur.\n";
