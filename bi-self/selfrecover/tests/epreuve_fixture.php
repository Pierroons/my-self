<?php

declare(strict_types=1);

/**
 * La solution d'une épreuve du niveau 3, cherchée comme le navigateur la cherche
 * (`client/sr-epreuve.js`) : la première valeur hexadécimale telle que
 * SHA-256(épreuve . ':' . solution) commence par `difficulte` bits nuls.
 *
 * Pour les bancs qui traversent `ouvrir()` sous un profil sans adresse : un
 * serveur n'a aucune raison de résoudre l'épreuve qu'il émet.
 *
 * @param array{epreuve: string, difficulte: int} $e
 */
function resoudreEpreuve(array $e): string
{
    for ($n = 0; ; $n++) {
        $h = hash('sha256', $e['epreuve'] . ':' . dechex($n));
        $nuls = strlen($h) - strlen(ltrim($h, '0'));
        $bits = $nuls * 4 + ($nuls < 64 ? 4 - strlen(decbin(hexdec($h[$nuls]))) : 0);
        if ($bits >= $e['difficulte']) {
            return dechex($n);
        }
    }
}
