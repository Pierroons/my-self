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

/** Label de service stable, copie conforme de `window.SR_DOMAIN`. */
const SR_DERIVE_LABEL = 'myself-lab-domain-v1';

/**
 * Reproduit `window.srDerive(word)` : HMAC-SHA256 avec le mot pour clé et le
 * label de service pour message, rendu en hexadécimal minuscule (64 car.).
 */
function sr_derive_like_browser(string $word): string
{
    return hash_hmac('sha256', SR_DERIVE_LABEL, $word);
}
