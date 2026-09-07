<?php

declare(strict_types=1);

namespace Pierroons\SelfRecover\Storage;

use Pierroons\SelfRecover\Device\Appareil;
use Pierroons\SelfRecover\Recovery\Litige;

/**
 * Contrat de persistance du protocole SelfRecover.
 *
 * 🔑 **Pourquoi une interface et non du SQL.** Les consommateurs de cette
 * bibliothèque n'ont pas le même schéma : l'un nomme sa colonne `recovery_hash`,
 * l'autre `pass_hash`. Imposer des tables obligerait à migrer des bases servies,
 * ce qui est un chantier étranger au protocole. Chaque application fournit donc
 * son adaptateur et garde son schéma.
 *
 * L'implémentation est responsable des requêtes préparées, de l'atomicité, et
 * de la création de son schéma.
 */
interface StorageInterface
{
    /** Échecs récents attribués à cette IP, pour freiner l'énumération. */
    public function compterEchecsIp(string $ip, int $depuis): int;

    /**
     * Trace une tentative, réussie ou non.
     *
     * ⚠️ L'étiquette ne doit pas révéler l'existence du compte : le lab écrit
     * `enroll:inconnu` quand il n'a rien trouvé, jamais le nom soumis.
     */
    public function tracerTentative(string $etiquette, bool $succes, ?string $ip, int $quand): void;

    /**
     * Empreinte du mot mémorisé pour ce compte, et son identifiant.
     *
     * @return array{id: int, empreinte_mot: string}|null
     */
    public function trouverCompte(string $nomCompte): ?array;

    /** Enrôle ou remplace l'appareil pour ce compte. */
    public function enregistrerAppareil(int $compteId, string $credentialId, string $clePubliqueB64url, int $quand): void;

    public function trouverAppareil(string $credentialId): ?Appareil;

    public function purgerDefisExpires(int $avant): void;

    public function enregistrerDefi(string $defi, string $credentialId, int $quand): void;

    /** Le défi existe-t-il, pour cet appareil, et n'a-t-il pas expiré ? */
    public function defiEnCours(string $defi, string $credentialId, int $depuis): bool;

    /**
     * Retire le défi.
     *
     * ⚠️ Usage unique : appelé dès la vérification faite, succès ou échec. Un
     * défi rejouable annule l'intérêt de le tirer au hasard.
     */
    public function consommerDefi(string $defi): void;

    /** Remplace le mot de passe du compte par cette empreinte. */
    public function remplacerEmpreinteMotDePasse(int $compteId, string $empreinte): void;

    /**
     * Révoque les sessions ouvertes du compte.
     *
     * 🔑 Une récupération qui laisse vivre les sessions existantes ne reprend
     * pas le compte : elle le partage avec qui l'occupait.
     */
    public function revoquerSessions(int $compteId): void;

    // ── Récupération de niveau 1 : passphrase diceware ──────────────────────

    /** Échecs récents visant ce compte précis, en plus du compteur par IP. */
    public function compterEchecsCompte(string $nomCompte, int $depuis): int;

    /**
     * Empreinte de la passphrase pour ce compte.
     *
     * @return array{id: int, empreinte_passphrase: string}|null
     */
    public function trouverComptePourPassphrase(string $nomCompte): ?array;

    /**
     * Remplace mot de passe ET passphrase en une fois.
     *
     * 🔑 Une récupération de niveau 1 consomme la passphrase : la laisser
     * valable après usage ferait d'un vol de papier une porte permanente.
     */
    public function remplacerEmpreintes(int $compteId, string $empreinteMotDePasse, string $empreintePassphrase): void;

    // ── Récupération de niveau 2 : code de récupération + mot mémorisé ──────

    /** Efface les codes du compte — une régénération périme l'ancien papier. */
    public function purgerCodes(int $compteId): void;

    /**
     * Enregistre un code : son index de recherche et son empreinte.
     *
     * ⚠️ `indexRecherche` n'est pas un secret mais ne doit pas être réversible :
     * c'est un HMAC du code sous le sel du déploiement. Il retrouve le compte
     * sans qu'aucun identifiant soit demandé — donc sans champ où éprouver
     * l'existence d'un compte.
     */
    public function enregistrerCode(int $compteId, string $indexRecherche, string $empreinteCode, int $quand): void;

    /**
     * Retrouve un code non consommé par son index de recherche.
     *
     * @return array{code_id: int, empreinte_code: string, deja_utilise: bool, compte_id: int, nom_compte: string, empreinte_mot: string}|null
     */
    public function trouverCodeParIndex(string $indexRecherche): ?array;

    /** Marque le code consommé. Un code de récupération ne sert qu'une fois. */
    public function consommerCode(int $codeId, int $quand): void;

    /** Combien de codes restent utilisables pour ce compte. */
    public function compterCodesRestants(int $compteId): int;

    // ── Récupération de niveau 3 : dossier et arbitrage humain ─────────────
    //
    // 🔑 Aucune de ces opérations ne touche au compte. Un refus clôt un dossier,
    // il ne supprime ni ne bannit : un refus dit « ce demandeur ne m'a pas
    // convaincu », pas « ce compte est illégitime ». Si le demandeur était un
    // imposteur, supprimer détruirait le compte de sa victime ; s'il était le
    // titulaire mal jugé, ça punirait un innocent. Et un attaquant incapable de
    // voler un compte pourrait le faire effacer en accumulant des refus —
    // l'échec deviendrait une arme. Ce qui se durcit est la PROCÉDURE, par le
    // gel ci-dessous.

    /** Le dossier portant ce numéro, quel que soit son état. */
    public function trouverLitigeParNumero(string $numero): ?Litige;

    /** Le dossier encore recevable de ce compte, s'il en existe un. */
    public function litigeActifDuCompte(int $compteId, int $maintenant): ?Litige;

    public function ouvrirLitige(
        int $compteId,
        string $numero,
        string $empreinteSesame,
        int $quand,
        int $expireLe,
    ): void;

    /** Une tentative d'ouverture pendant qu'un dossier court : un fait pour l'arbitre. */
    public function compterDemandeurConcurrent(int $litigeId): void;

    /** Range le faisceau et fait passer le dossier en attente de lecture. */
    public function enregistrerFaisceau(int $litigeId, string $faisceauJson, int $quand): void;

    /** Écrit la décision, son auteur et sa date. Ne touche pas au compte. */
    public function trancherLitige(int $litigeId, string $statut, string $par, int $quand): void;

    /**
     * Clôt le dossier et rend son sésame inutilisable.
     *
     * ⚠️ Le sésame est à usage unique : un dossier repris après le
     * ré-enrôlement rouvrirait une porte que le titulaire croit refermée.
     */
    public function cloreLitige(int $litigeId, int $quand): void;

    /**
     * Combien de dossiers REFUSÉS sur ce compte depuis cet instant.
     *
     * ⚠️ On compte les dossiers, pas les dépôts : trois soumissions sur un même
     * dossier restent un seul refus, sinon l'insistance d'un titulaire honnête
     * déclencherait le gel aussi vite qu'une campagne hostile.
     */
    public function compterRefusRecents(int $compteId, int $depuis): int;

    /** Gèle l'OUVERTURE de nouveaux dossiers. Le compte reste entier et connectable. */
    public function poserGel(int $compteId, int $jusqua, int $quand): void;

    /** Jusqu'à quand l'ouverture est gelée, 0 si elle ne l'est pas. */
    public function gelJusqua(int $compteId, int $maintenant): int;

    /**
     * Lève le gel.
     *
     * ⚠️ La trace se garde : qui a dégelé et quand vaut d'être conservé, y
     * compris pour l'arbitre suivant. Effacer la ligne effacerait la décision.
     */
    public function leverGel(int $compteId, string $par, int $quand): void;

    /** `$auteur` vaut `demandeur` ou `admin`. */
    public function ajouterMessageLitige(int $litigeId, string $auteur, string $texte, int $quand): void;

    /** @return list<array{auteur: string, texte: string, ecrit_le: int}> */
    public function messagesDuLitige(int $litigeId): array;

    /** Les dossiers pour la console d'arbitrage, du plus récent au plus ancien. */
    public function listerLitiges(int $limite): array;

    /** Efface les dossiers périmés. Rend le nombre effacé. */
    public function purgerLitigesExpires(int $avant): int;

    /**
     * Ce que le serveur sait du compte, sans que personne l'ait déclaré.
     *
     * ⚠️ `derniere_connexion` et `nombre_connexions` sont NULLABLES, et c'est
     * délibéré : un déploiement qui ne les enregistre pas doit rendre `null`,
     * jamais zéro. Zéro se lit « jamais connecté » et transforme une réponse
     * honnête en divergence — le dossier d'une personne légitime arrive alors à
     * charge devant l'arbitre.
     *
     * @return array{id: int, nom_compte: string, cree_le: int, derniere_connexion: int|null, nombre_connexions: int|null}|null
     */
    public function faitsDuCompte(int $compteId): ?array;

    /**
     * Repose les quatre secrets d'un compte en une fois, après un accord.
     *
     * 🔑 Les trois empreintes et le sel ne sont pas quatre informations mais
     * une seule : le mot mémorisé est dérivé sous ce sel, les séparer laisse une
     * fenêtre où le compte n'est récupérable par rien.
     */
    public function reposerSecrets(
        int $compteId,
        string $empreinteMotDePasse,
        string $empreintePassphrase,
        string $empreinteMotDerive,
        string $sel,
    ): void;

    // ── Atomicité ──────────────────────────────────────────────────────────
    //
    // 🔑 Une récupération réussie touche plusieurs lignes : le secret consommé,
    // les empreintes remplacées, les sessions révoquées. Les écrire séparément
    // laisse des fenêtres — celle où le code est déjà marqué utilisé alors que
    // le mot de passe n'a pas changé rend le compte inaccessible par ce code
    // sans qu'il ait servi.
    //
    // ⚠️ Ce qui doit survivre à un échec ne va pas dans la transaction. Le défi
    // du facteur « cet appareil » est consommé avant la vérification de
    // signature, exprès : un défi rejouable annule l'intérêt de le tirer au
    // hasard, échec compris.

    public function commencerTransaction(): void;

    public function validerTransaction(): void;

    public function annulerTransaction(): void;
}
