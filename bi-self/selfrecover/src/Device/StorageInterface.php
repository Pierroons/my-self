<?php

declare(strict_types=1);

namespace Pierroons\SelfRecover\Device;

/**
 * Contrat de persistance du facteur « cet appareil ».
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
}
