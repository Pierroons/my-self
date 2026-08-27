<?php
/**
 * Dérivation du mot mémorisé — **pour les scripts CLI uniquement**.
 *
 * ⚠️ Ce fichier n'est jamais chargé par le bootstrap, et aucune page ne doit
 * l'inclure. Dans le flux applicatif, la dérivation appartient au navigateur :
 * c'est la promesse centrale de SelfRecover, et la refaire côté serveur ferait
 * transiter le mot mémorisé en clair.
 *
 * Les scripts de peuplement (seed, préparation du challenge) n'ont pas de
 * navigateur pour le faire à leur place. Ils reproduisent donc ici le calcul de
 * `public/js/sr-derive.js`, à l'identique — mêmes paramètres, même label — pour
 * que les comptes qu'ils créent soient récupérables depuis l'interface comme
 * n'importe quel autre.
 *
 * 🔑 Si le label change dans sr-derive.js, il doit changer ici aussi : deux
 * valeurs divergentes produiraient des comptes de démonstration impossibles à
 * récupérer, et le symptôme n'apparaîtrait qu'au moment d'une récupération.
 */

declare(strict_types=1);

/**
 * L'hôte sur lequel le lab est servi.
 *
 * 🔑 Depuis le 27/08/2026 la dérivation lie l'adresse : un mot mémorisé dérivé
 * ici ne vaut rien ailleurs. Les scripts de peuplement n'ont pas de navigateur
 * pour lire `location.hostname`, on le leur donne donc par l'environnement.
 *
 * ⚠️ Conséquence à connaître avant de s'y cogner : un lab peuplé pour
 * `localhost` et un lab servi en ligne ne partagent PAS leurs comptes. C'est le
 * prix du mode `hostname`, et il est voulu.
 */
const SR_DERIVE_HOTE = 'localhost';

/** La version du FORMAT du protocole — celle de la bibliothèque, pas du lab. */
const SR_DERIVE_VERSION = 'v2';

/**
 * Reproduit `window.srDerive(word)` : HMAC-SHA256 avec le mot pour clé et le
 * label de service pour message, rendu en hexadécimal minuscule (64 car.).
 */
function sr_derive_like_browser(string $word, string $sel, ?string $hote = null): string
{
    $h = strtolower($hote ?? (string) (getenv('SR_DERIVE_HOTE') ?: SR_DERIVE_HOTE));

    return hash_hmac('sha256', $h . '|' . SR_DERIVE_VERSION . $sel, $word);
}

/**
 * Un sel de compte, pour les scripts qui n'ont pas de navigateur.
 *
 * Le miroir de `srEngendrerSel()` : 16 octets, rendus en 32 hexadécimaux.
 */
function sr_sel_aleatoire(): string
{
    return bin2hex(random_bytes(16));
}
