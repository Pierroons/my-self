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
    /** Compteurs d'échec du niveau 2. */
    public const PREFIXE_L2 = 'l2:';

    /**
     * Un essai de niveau 2 dont le code n'est rattaché à AUCUN compte.
     *
     * 🔑 **Fixe et sans empreinte, exprès.** Il n'y a pas de nom à saler : le
     * code est introuvable. Tous ces essais partagent donc la même étiquette,
     * qui ne dit rien de ce qui a été soumis ni de l'existence d'un compte.
     *
     * ⚠️ **Il existe pour que le frein par ORIGINE les voie.** Les deux
     * `Hashing::verify` de `parCode()` sont payés même sur un code introuvable,
     * pour que le temps ne dise pas lequel a échoué. Laissés hors du compte, ces
     * essais deviennent un calcul gratuit qu'un tiers déclenche sans connaître
     * un seul nom de compte — et des lignes que `purgerEchecs()` n'efface jamais.
     */
    public const PREFIXE_L2_INCONNU = 'l2-inconnu:';

    /**
     * Niveau 1 : les échecs, et les essais dont tous les mots existent dans une
     * liste publique. `Recovery::etiquetteEchecsL1()` et
     * `Recovery::etiquetteSuspicionL1()` disent ce que chacun range.
     */
    public const PREFIXE_L1          = 'l1:';
    public const PREFIXE_SUSPICION   = 'l1-liste:';

    /**
     * Ouverture d'un dossier de niveau 3 : comptée, jamais attribuée — aucun nom
     * de compte n'entre dans cette étiquette.
     */
    public const PREFIXE_L3_OUVRIR = 'l3:ouvrir:';

    /** Dépôt d'un faisceau : nominatif, donc sous HMAC comme le reste. */
    public const PREFIXE_L3_DEPOT = 'l3:depot:';

    /** Compteur d'échecs d'enrôlement d'un appareil. */
    public const PREFIXE_ENROLEMENT = 'enroll:';

    /**
     * La liste CLOSE des étiquettes que cette bibliothèque écrit.
     *
     * 🔑 **Elle existe parce que la table ne nous appartient pas.** Un
     * déploiement y range aussi les échecs de sa page de connexion, sous
     * l'étiquette qu'il veut. Un frein de cette bibliothèque qui compte sans
     * distinguer compte donc des échecs qu'elle n'a pas provoqués — et ferme la
     * récupération de qui a simplement oublié son mot de passe, avec le bon
     * secret en main. `StorageInterface::compterEchecsIp()` reçoit cette liste
     * pour ne peser que ce que la bibliothèque a fait payer.
     *
     * ⚠️ **Un préfixe absent d'ici est invisible à ce frein.** Toute constante
     * `PREFIXE_*` de cette classe doit y figurer ; `sanity_etiquettes.php` le
     * vérifie par réflexion, pour qu'un préfixe neuf ne puisse pas être ajouté
     * sans entrer dans le compte.
     */
    public const PREFIXES = [
        self::PREFIXE_L1,
        self::PREFIXE_SUSPICION,
        self::PREFIXE_L2,
        self::PREFIXE_L2_INCONNU,
        self::PREFIXE_L3_OUVRIR,
        self::PREFIXE_L3_DEPOT,
        self::PREFIXE_ENROLEMENT,
    ];

    /**
     * L'empreinte nue d'une valeur — un HMAC, pas un chiffrement.
     *
     * Elle permet de retrouver une ligne sans stocker la valeur, et sans que la
     * base exfiltrée ne la rende : reconstituer l'empreinte suppose le sel.
     */
    public static function empreinte(#[\SensitiveParameter] string $valeur, #[\SensitiveParameter] string $selDeploiement): string
    {
        return hash_hmac('sha256', strtolower(trim($valeur)), $selDeploiement);
    }

    /** Une étiquette de compteur : préfixe en clair, valeur sous HMAC. */
    public static function sous(string $prefixe, #[\SensitiveParameter] string $valeur, #[\SensitiveParameter] string $selDeploiement): string
    {
        return $prefixe . self::empreinte($valeur, $selDeploiement);
    }

    /**
     * Ce nom de compte empiéterait-il sur l'espace que les compteurs occupent ?
     *
     * 🔑 **À appeler à l'inscription, et à refuser.** Les étiquettes partagent
     * la colonne des noms avec la page de connexion de l'application. Un compte
     * dont le nom commence par un préfixe voit donc ses échecs comptés avec ceux
     * que la bibliothèque a fait payer.
     *
     * ⚠️ C'est cette réserve qui rend `PREFIXE_L2_INCONNU` sans danger : une
     * étiquette fixe n'est tenable que si aucun compte ne peut la porter.
     *
     * La comparaison est insensible à la casse : la colonne l'est souvent, et un
     * `L2:` accepté annulerait la garde.
     */
    public static function empieteSurUnCompteur(string $nomCompte): bool
    {
        $nom = strtolower(trim($nomCompte));
        foreach (self::PREFIXES as $prefixe) {
            if (str_starts_with($nom, strtolower($prefixe))) {
                return true;
            }
        }

        return false;
    }
}
