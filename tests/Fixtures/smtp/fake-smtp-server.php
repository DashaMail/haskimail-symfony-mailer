<?php

declare(strict_types=1);

/*
 * This file is part of the haskimail/symfony-mailer package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

// Minimal SMTP server for tests that records every line it receives (after decryption).
// Usage: php fake-smtp-server.php <log-file> [<certificate.pem>]
// Without a certificate STARTTLS is never offered (like a downgrade attack); with one it is offered and honoured.
// The listening port is printed on the first line of stdout.

$log = $argv[1] ?? throw new InvalidArgumentException('Log file argument is required.');
$certificate = $argv[2] ?? null;
$context = stream_context_create(null !== $certificate ? ['ssl' => ['local_cert' => $certificate, 'verify_peer' => false]] : []);
$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error, \STREAM_SERVER_BIND | \STREAM_SERVER_LISTEN, $context);
if (false === $server) {
    throw new RuntimeException((string) $error);
}
$name = (string) stream_socket_get_name($server, false);
fwrite(\STDOUT, substr($name, (int) strrpos($name, ':') + 1)."\n");
fflush(\STDOUT);

$client = stream_socket_accept($server, 10);
if (false === $client) {
    exit(1);
}

$reply = static function (string $line) use ($client): void {
    fwrite($client, $line."\r\n");
};

$reply('220 fake.haskimail.test ESMTP');
$inData = false;
$tls = false;
while (false !== ($line = fgets($client))) {
    file_put_contents($log, $line, \FILE_APPEND);
    $line = rtrim($line, "\r\n");

    if ($inData) {
        if ('.' === $line) {
            $inData = false;
            $reply('250 queued');
        }
        continue;
    }

    $verb = strtoupper((string) strtok($line, ' '));
    if ('EHLO' === $verb) {
        $reply("250-fake.haskimail.test\r\n".(null !== $certificate && !$tls ? "250-STARTTLS\r\n" : '')."250-AUTH PLAIN LOGIN\r\n250 8BITMIME");
    } elseif ('STARTTLS' === $verb && null !== $certificate && !$tls) {
        $reply('220 Ready to start TLS');
        if (true !== stream_socket_enable_crypto($client, true, \STREAM_CRYPTO_METHOD_TLS_SERVER)) {
            exit(2);
        }
        $tls = true;
    } elseif (in_array($verb, ['HELO', 'MAIL', 'RCPT', 'RSET', 'NOOP'], true)) {
        $reply('250 OK');
    } elseif ('AUTH' === $verb) {
        $reply('235 Authenticated');
    } elseif ('DATA' === $verb) {
        $inData = true;
        $reply('354 Go ahead');
    } elseif ('QUIT' === $verb) {
        $reply('221 Bye');
        exit(0);
    } else {
        $reply('500 Unknown command');
    }
}
