<?php

declare(strict_types=1);

namespace Pierroons\SelfDataGuard\Pepper;

/**
 * The pepper in a file on this machine, out of the database:
 * "myself-pepper/v1:" followed by 64 lower-case hex, as `bin/pepper.php new`
 * writes it from random_bytes(32).
 *
 * Refused, with its reason (PepperFileException::$reason), when it is absent,
 * unreadable, not a regular file — a symbolic link included —, under the
 * document root the web server serves, readable or writable by others or
 * writable by its group, malformed, or holding fewer than 16 distinct bytes.
 * The last check catches a slip, not a weak secret: a pepper written by hand
 * from a phrase passes it, and the database alone tries candidates against it
 * at the cost of one HMAC each.
 */
final class LocalPepperFile implements PepperSource
{
    private const DISTINCT_BYTES = 16;

    private string $key;

    /**
     * @param ?string $documentRoot the root the web server serves; null takes
     *                              $_SERVER['DOCUMENT_ROOT'] when it is set
     * @throws PepperFileException
     */
    public function __construct(string $path, ?string $documentRoot = null)
    {
        $this->key = self::read($path, $documentRoot ?? (string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));
    }

    public function mac(string $usage, string $data): string
    {
        return hash_hmac('sha256', Pepper::message($usage, $data), $this->key, true);
    }

    /**
     * The pepper a file holds, from its contents: the grammar alone, without
     * the checks on the file itself.
     *
     * @throws PepperFileException malformed or uniform
     */
    public static function parse(#[\SensitiveParameter] string $contents): string
    {
        if (preg_match('/^myself-pepper\/v1:([0-9a-f]{64})\n?$/D', $contents, $m) !== 1) {
            throw new PepperFileException('malformed', 'The pepper file is not "myself-pepper/v1:" followed by 64 lower-case hex');
        }
        $pepper = (string) hex2bin($m[1]);
        if (count(array_unique(str_split($pepper))) < self::DISTINCT_BYTES) {
            throw new PepperFileException(
                'uniform',
                'The pepper holds fewer than ' . self::DISTINCT_BYTES . ' distinct bytes: it was not drawn by bin/pepper.php new'
            );
        }
        return $pepper;
    }

    private static function read(string $path, string $documentRoot): string
    {
        clearstatcache(true, $path);
        if (is_link($path)) {
            throw new PepperFileException('not-regular', "The pepper file {$path} is a symbolic link: give the file itself");
        }
        if (!file_exists($path)) {
            $dir = dirname($path);
            if (!is_dir($dir) || !is_executable($dir)) {
                throw new PepperFileException('unreadable', "Cannot establish whether the pepper file exists: {$dir} is not traversable");
            }
            throw new PepperFileException('absent', "The pepper file {$path} does not exist — bin/pepper.php new writes one");
        }
        if (!is_file($path)) {
            throw new PepperFileException('not-regular', "The pepper file {$path} is not a regular file");
        }
        $real = (string) realpath($path);
        $root = $documentRoot === '' ? false : realpath($documentRoot);
        if ($root !== false && str_starts_with($real, rtrim($root, '/') . '/')) {
            throw new PepperFileException('under-document-root', "The pepper file {$real} is under the document root {$root}: the web server could serve it");
        }
        $mode = fileperms($path) & 0o777;
        if (($mode & 0o027) !== 0) {
            throw new PepperFileException(
                'too-open',
                sprintf('The pepper file %s has mode %04o: others may read or write it, or its group may write it — chmod 600', $path, $mode)
            );
        }
        $contents = @file_get_contents($path);
        if ($contents === false) {
            throw new PepperFileException('unreadable', "The pepper file {$path} cannot be read by this process");
        }
        return self::parse($contents);
    }

    /** Keep the pepper out of var_dump() and print_r(). */
    public function __debugInfo(): array
    {
        return ['key' => '[redacted]'];
    }

    public function __destruct()
    {
        sodium_memzero($this->key);
    }
}
