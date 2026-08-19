<?php

declare(strict_types=1);

namespace Pierroons\SelfRecover\Storage;

use Pierroons\SelfRecover\Device\Appareil;

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
