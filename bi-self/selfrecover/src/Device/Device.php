<?php

declare(strict_types=1);

namespace Pierroons\SelfRecover\Device;

use Pierroons\SelfRecover\Crypto\Encoding;
use Pierroons\SelfRecover\Crypto\Hashing;
use Pierroons\SelfRecover\Storage\StorageInterface;

/**
 * Facteur de possession « cet appareil » — enrôlement, défi, vérification.
 *
 * Le navigateur détient une clé ECDSA P-256 dont la privée est chiffrée au repos
 * par une clé dérivée du mot mémorisé (Argon2id, côté client). Le serveur ne
 * détient que la publique. Récupérer revient à signer un défi : impossible sans
 * l'appareil ET sans le mot, deux facteurs liés cryptographiquement, une seule
 * signature à vérifier.
 *
 * 🔑 **L'enrôlement exige le mot mémorisé.** Sans cette preuve, la chaîne
 * enroll → auth-begin → auth-finish permettait de prendre n'importe quel compte
 * sans aucun secret : nommer sa victime, poser sa propre clé publique sur son
 * compte, signer le défi, et recevoir un mot de passe neuf. Trois requêtes.
 * Corrigé au lab le 02/08/2026, porté à la démo publique le 13/08 seulement —
 * onze jours d'écart entre deux implémentations du même protocole. Cette classe
 * existe pour que la question ne se pose plus qu'à un endroit.
 *
 * ⚠️ Enrôler n'est pas récupérer. On enrôle depuis un compte auquel on a déjà
 * accès ; qui a tout perdu passe par les niveaux 1, 2 ou 3.
 */
final class Device
{
    /** Durée de vie d'un défi. Cinq minutes suffisent à signer, pas à chercher. */
    public const DEFI_TTL = 300;

    public function __construct(
        private readonly StorageInterface $stockage,
        /** Fenêtre de comptage des échecs par IP. */
        private readonly int $fenetreEchecs = 900,
        /** Échecs tolérés par IP dans la fenêtre — un foyer NAT partage son IP. */
        private readonly int $maxEchecsIp = 12,
        /** Délai appliqué aux refus, pour aplatir ce que le message tait. */
        private readonly int $delaiRefusUs = 300000,
    ) {
    }

    /**
     * Enrôle un appareil sur un compte, contre preuve du mot mémorisé.
     *
     * @return array{ok: bool, message: string, error?: string}
     */
    public function enroler(
        string $nomCompte,
        string $credentialId,
        string $clePubliqueB64url,
        string $motDerive,
        ?string $ip = null,
        ?int $maintenant = null,
    ): array {
        $maintenant = $maintenant ?? time();

        // Message unique pour tous les refus qui suivent la validation de forme :
        // distinguer « compte inconnu » de « mot incorrect » rendrait l'un des
        // deux facteurs testable seul, et ferait de cet appel un oracle
        // d'existence de comptes.
        $refus = ['ok' => false, 'message' => 'Compte ou mot mémorisé incorrect.'];

        $nomCompte    = strtolower(trim($nomCompte));
        $credentialId = trim($credentialId);
        $clePublique  = Encoding::b64urlDecode($clePubliqueB64url);

        if (!preg_match('/^[A-Za-z0-9_-]{16,64}$/', $credentialId) || strlen($clePublique) < 50) {
            return ['ok' => false, 'message' => "Données d'enrôlement invalides."];
        }
        if (!self::estCleDerivee($motDerive)) {
            return ['ok' => false, 'error' => 'invalid_derived_key',
                    'message' => 'Mot mémorisé invalide : la dérivation doit se faire dans le navigateur.'];
        }
        if (openssl_pkey_get_public(Encoding::spkiToPem($clePublique)) === false) {
            return ['ok' => false, 'message' => 'Clé publique invalide.'];
        }

        // Même compteur que la récupération : enrôler mène au compte au même
        // titre, ce chemin doit se fatiguer aussi vite.
        if ($ip !== null
            && $this->stockage->compterEchecsIp($ip, $maintenant - $this->fenetreEchecs) >= $this->maxEchecsIp) {
            return ['ok' => false, 'message' => 'Trop de tentatives. Réessaie dans 15 minutes.'];
        }

        $compte = $this->stockage->trouverCompte($nomCompte);

        // Argon2id exécuté même sur compte inconnu : sinon le temps de réponse
        // dirait ce que le message se garde de dire.
        $motOk = Hashing::verify($motDerive, $compte['empreinte_mot'] ?? Hashing::dummyHash());
        $ok    = $compte !== null && $motOk;

        $this->stockage->tracerTentative(
            'enroll:' . ($compte !== null ? $nomCompte : 'inconnu'),
            $ok,
            $ip,
            $maintenant,
        );

        if (!$ok) {
            usleep($this->delaiRefusUs);

            return $refus;
        }

        $this->stockage->enregistrerAppareil(
            (int) $compte['id'],
            $credentialId,
            Encoding::b64urlEncode($clePublique),
            $maintenant,
        );

        return ['ok' => true, 'message' => 'Appareil enrôlé.'];
    }

    /**
     * Émet un défi de 32 octets pour une récupération depuis cet appareil.
     *
     * @return array{ok: bool, challenge?: string, message?: string}
     */
    public function ouvrirDefi(string $credentialId, ?int $maintenant = null): array
    {
        $maintenant = $maintenant ?? time();
        $this->stockage->purgerDefisExpires($maintenant - self::DEFI_TTL);

        $defi = Encoding::b64urlEncode(random_bytes(32));
        $this->stockage->enregistrerDefi($defi, $credentialId, $maintenant);

        return ['ok' => true, 'challenge' => $defi];
    }

    /**
     * Vérifie la signature du défi et rend la main au titulaire.
     *
     * Le compte visé vient du lien d'enrôlement, jamais de la requête : c'est
     * la seconde moitié du correctif du 02/08/2026.
     *
     * @return array{ok: bool, message: string, mot_de_passe?: string, compte?: string}
     */
    public function cloreDefi(
        string $credentialId,
        string $defi,
        string $signatureB64url,
        ?int $maintenant = null,
    ): array {
        $maintenant   = $maintenant ?? time();
        $credentialId = trim($credentialId);
        $defi         = trim($defi);

        if ($credentialId === '' || $defi === '') {
            return ['ok' => false, 'message' => 'Données incomplètes.'];
        }
        if (!$this->stockage->defiEnCours($defi, $credentialId, $maintenant - self::DEFI_TTL)) {
            return ['ok' => false, 'message' => 'Challenge invalide ou expiré.'];
        }

        $this->stockage->consommerDefi($defi);

        $appareil = $this->stockage->trouverAppareil($credentialId);
        if ($appareil === null) {
            return ['ok' => false, 'message' => 'Appareil ou mot mémorisé incorrect.'];
        }

        $pem = Encoding::spkiToPem(Encoding::b64urlDecode($appareil->clePubliqueB64url));
        $der = Encoding::p1363ToDer(Encoding::b64urlDecode($signatureB64url));

        // Le navigateur a signé les octets de la chaîne base64url du défi.
        $verdict = $der !== '' ? openssl_verify($defi, $der, $pem, OPENSSL_ALGO_SHA256) : -1;
        if ($verdict !== 1) {
            return ['ok' => false, 'message' => 'Appareil ou mot mémorisé incorrect.'];
        }

        $motDePasse = self::engendrerMotDePasse();
        $this->stockage->remplacerEmpreinteMotDePasse($appareil->compteId, Hashing::hash($motDePasse));
        $this->stockage->revoquerSessions($appareil->compteId);

        return [
            'ok'           => true,
            'message'      => 'Appareil reconnu.',
            'mot_de_passe' => $motDePasse,
            'compte'       => $appareil->nomCompte,
        ];
    }

    /**
     * Le mot mémorisé a-t-il la forme d'une clé dérivée ?
     *
     * 🔑 **Le mot lui-même n'arrive jamais ici.** Le client calcule
     * `HMAC-SHA256(clé = mot, message = label de service)` et n'envoie que le
     * résultat : 64 caractères hexadécimaux. Refuser toute autre forme est ce
     * qui tient la promesse — sans ce contrôle, un client resté sur une version
     * antérieure enverrait le mot en clair et le serveur l'accepterait sans que
     * rien ne le signale.
     */
    public static function estCleDerivee(string $valeur): bool
    {
        return (bool) preg_match('/^[0-9a-f]{64}$/', $valeur);
    }

    /** Mot de passe temporaire rendu au titulaire après une récupération. */
    public static function engendrerMotDePasse(int $longueur = 16): string
    {
        $alphabet = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $sortie   = '';
        $max      = strlen($alphabet) - 1;
        for ($i = 0; $i < $longueur; $i++) {
            $sortie .= $alphabet[random_int(0, $max)];
        }

        return $sortie;
    }
}
