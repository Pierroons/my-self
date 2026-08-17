<?php
/**
 * Bi-Self Demo — Logger.
 *
 * Écrit des événements JSONL dans /var/lib/selfjustice/demo-sessions/{id}/log.jsonl.
 * Le fichier est ensuite tailé par l'endpoint SSE events.php.
 *
 * Niveaux : info, crypto, success, warning, error
 * Chaque log : { ts, level, step, msg, ctx? }
 */

declare(strict_types=1);

require_once __DIR__ . '/redactor.php';

final class DemoLogger {
    private string $logFile;

    public function __construct(string $sessionDir) {
        $this->logFile = rtrim($sessionDir, '/') . '/log.jsonl';
    }

    public function log(string $level, string $step, string $msg, array $ctx = []): void {
        $line = json_encode([
            'ts'    => (new DateTimeImmutable('now'))->format('Y-m-d\TH:i:s.v\Z'),
            'level' => $level,
            'step'  => $step,
            'msg'   => Redactor::redactLog($msg),
            'ctx'   => Redactor::redactCtx($ctx),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($line === false) {
            return;
        }

        // Append atomique — flock pour éviter les interleavings avec le tail SSE
        $fh = @fopen($this->logFile, 'ab');
        if (!$fh) {
            return;
        }
        if (flock($fh, LOCK_EX)) {
            fwrite($fh, $line . "\n");
            fflush($fh);
            flock($fh, LOCK_UN);
        }
        fclose($fh);
    }

    public function info(string $step, string $msg, array $ctx = []): void {
        $this->log('info', $step, $msg, $ctx);
    }

    public function crypto(string $step, string $msg, array $ctx = []): void {
        $this->log('crypto', $step, $msg, $ctx);
    }

    public function success(string $step, string $msg, array $ctx = []): void {
        $this->log('success', $step, $msg, $ctx);
    }

    public function warning(string $step, string $msg, array $ctx = []): void {
        $this->log('warning', $step, $msg, $ctx);
    }

    public function error(string $step, string $msg, array $ctx = []): void {
        $this->log('error', $step, $msg, $ctx);
    }
}
