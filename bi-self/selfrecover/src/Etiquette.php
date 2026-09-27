<?php

declare(strict_types=1);

namespace Pierroons\SelfRecover;

/**
 * Les étiquettes sous lesquelles les compteurs d'échec sont écrits, et relus.
 *
 * ⚠️ **Le sel n'est pas là pour cacher, il est là pour EMPÊCHER D'ÉCRIRE.**
 * `compterEchecsCompte()` compte des lignes par étiquette, dans une table où les
 * tentatives de connexion atterrissent aussi — et un nom de compte soumis y
 * arrive tel quel, sans contrôle de forme, depuis une route publique. Une
 * étiquette devinable est donc un compteur que n'importe qui remplit : vingt
 * requêtes sur la page de connexion, sous l'étiquette d'un tiers, et plus
 * personne n'ouvre de dossier ni ne se sert de ses codes papier. Mesuré avant
 * d'être corrigé, pas supposé.
 *
 * Sous HMAC, l'étiquette suppose le sel du déploiement, qui vit hors du webroot.
 * Le préfixe reste en clair pour que les consoles sachent quoi ne pas afficher
 * comme un échec d'authentification ; il ne suffit à personne pour viser un
 * compteur.
 *
 * 🔑 **Un seul endroit calcule.** Trois chemins écrivent dans ces compteurs — la
 * récupération par code, l'enrôlement d'un appareil, l'ouverture d'un dossier —
 * et chacun avait son propre préfixe. Le calcul, lui, n'a aucune raison de
 * différer : recopié, il divergerait, et le frein qui relit deviendrait muet du
 * côté qui a bougé, sans qu'aucune erreur ne le dise.
 */
final class Etiquette
{
    /**
     * L'empreinte nue d'une valeur — un HMAC, pas un chiffrement.
     *
     * Elle permet de retrouver une ligne sans stocker la valeur, et sans que la
     * base exfiltrée ne la rende : reconstituer l'empreinte suppose le sel.
     */
    public static function empreinte(string $valeur, string $selDeploiement): string
    {
        return hash_hmac('sha256', strtolower(trim($valeur)), $selDeploiement);
    }

    /** Une étiquette de compteur : préfixe en clair, valeur sous HMAC. */
    public static function sous(string $prefixe, string $valeur, string $selDeploiement): string
    {
        return $prefixe . self::empreinte($valeur, $selDeploiement);
    }
}
