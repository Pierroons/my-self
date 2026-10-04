<?php
/**
 * Quota des retours anonymes — chargé par feedback.php.
 *
 * À part de feedback.php, qui répond dès qu'on le charge : la règle s'éprouve
 * ici sans requête (tests/test_quota_feedback.php).
 */

declare(strict_types=1);

/** Envois gardés au plus, tous retours confondus (ils vivent 30 jours). */
const FEEDBACK_MAX_ENVOIS = 200;
/** Volume gardé au plus, en octets. */
const FEEDBACK_MAX_OCTETS = 200 * 1024 * 1024;

/**
 * Vrai si un envoi de `$octets` tient encore dans le stockage des retours.
 *
 * 🔑 L'envoi est anonyme et pèse jusqu'à 5 Mo : sans plafond, la limite de
 * débit seule laissait un inconnu remplir le disque qui porte les bases. Plein,
 * le stockage refuse les retours suivants ; le service, lui, continue.
 */
function feedback_place_restante(string $dossier, int $octets): bool
{
    if (!is_dir($dossier)) {
        return true;
    }
    $envois = 0;
    $total = $octets;
    foreach (scandir($dossier) ?: [] as $slot) {
        if ($slot === '.' || $slot === '..' || !is_dir("$dossier/$slot")) {
            continue;
        }
        $envois++;
        foreach (scandir("$dossier/$slot") ?: [] as $f) {
            if (is_file("$dossier/$slot/$f")) {
                $total += (int) filesize("$dossier/$slot/$f");
            }
        }
    }
    return $envois < FEEDBACK_MAX_ENVOIS && $total <= FEEDBACK_MAX_OCTETS;
}
