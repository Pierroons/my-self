<?php
/**
 * Bi-Self Demo — Server-Sent Events endpoint.
 *
 * GET /demo/api/events
 *   Stream le contenu de log.jsonl de la session en cours, en temps réel.
 *   Le client (frontend split-screen) consomme via EventSource.
 *
 * Format SSE standard :
 *   event: log
 *   data: {"ts":"...","level":"info","step":"register","msg":"...","ctx":{}}
 *
 * Keep-alive : ping toutes les 15 s.
 * Timeout max : 30 min (durée session).
 */

declare(strict_types=1);

require_once __DIR__ . '/../lib/session_manager.php';

$s = DemoSession::current();
if ($s === null) {
    http_response_code(404);
    exit;
}

// Headers SSE
header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-store, no-transform, private');
header('X-Accel-Buffering: no'); // important pour nginx → désactive le buffering
header('Connection: keep-alive');

// Désactive buffering PHP + nginx
while (ob_get_level() > 0) ob_end_flush();
ob_implicit_flush(true);

$log_file = $s->dir . '/log.jsonl';

// Position initiale : offset demandé par le client via ?offset=N, ou 0
$offset = 0;
if (isset($_GET['offset']) && ctype_digit((string) $_GET['offset'])) {
    $offset = (int) $_GET['offset'];
}

$last_ping = time();
$deadline  = $s->expiresAt;
$client_alive = true;

echo "retry: 3000\n\n";

while ($client_alive && time() < $deadline) {
    clearstatcache(false, $log_file);
    $size = @filesize($log_file) ?: 0;

    if ($size > $offset) {
        $fh = @fopen($log_file, 'rb');
        if ($fh !== false) {
            fseek($fh, $offset);
            while (!feof($fh)) {
                $line = fgets($fh);
                if ($line === false) break;
                $line = rtrim($line, "\r\n");
                if ($line === '') continue;
                echo "event: log\n";
                echo "data: " . $line . "\n\n";
                $offset = ftell($fh);
            }
            fclose($fh);
        }
        $last_ping = time();
    }

    // Ping heartbeat
    if (time() - $last_ping >= 15) {
        echo "event: ping\n";
        echo 'data: {"ts":"' . gmdate('Y-m-d\TH:i:s\Z') . '","offset":' . $offset . '}' . "\n\n";
        $last_ping = time();
    }

    // Check client toujours connecté
    if (connection_aborted()) {
        $client_alive = false;
        break;
    }

    usleep(200_000); // poll toutes les 200 ms
}

echo "event: close\n";
echo "data: {\"reason\":\"ttl_reached\"}\n\n";
