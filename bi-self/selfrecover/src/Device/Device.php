<?php

declare(strict_types=1);

namespace Pierroons\SelfRecover\Device;

use Pierroons\SelfRecover\Crypto\Encoding;
use Pierroons\SelfRecover\Crypto\Hashing;
use Pierroons\SelfRecover\Duree;
use Pierroons\SelfRecover\Etiquette;
use Pierroons\SelfRecover\ProfilDeploiement;
use Pierroons\SelfRecover\Titulaire;
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

    /**
     * Préfixe du compteur d'échecs d'enrôlement. En clair, pour qu'une console
     * sache le reconnaître ; le HMAC qui suit est ce qui empêche de l'écrire.
     */
    private const PREFIXE_ENROLEMENT = 'enroll:';

    public function __construct(
        private readonly StorageInterface $stockage,
        /**
         * **Obligatoire, sans défaut.** Le contrat est dans `ProfilDeploiement`.
         * Ce chemin freine par adresse, dans la même table que la récupération :
         * deux profils divergents y produiraient deux comptages incohérents.
         */
        private readonly ProfilDeploiement $profil,
        /**
         * Sel du déploiement, celui de `Recovery`. Il ne sert ici qu'à fabriquer
         * l'étiquette du compteur d'échecs, que `Etiquette` explique.
         */
        private readonly string $selDeploiement,
        /** Fenêtre de comptage des échecs, par compte comme par IP. */
        private readonly int $fenetreEchecs = 900,
        /** Échecs tolérés sur un même compte dans la fenêtre. */
        private readonly int $maxEchecsCompte = 5,
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
        /**
         * ⚠️ **Obligatoire, sans défaut.** Enrôler ouvre le compte avec le seul mot
         * mémorisé : `Titulaire` dit ce que cela coûte, et ce que l'intégrateur doit
         * garantir à la place de cette bibliothèque.
         */
        Titulaire $titulaire,
        ?string $ip = null,
        ?int $maintenant = null,
    ): array {
        $this->profil->verifierOrigine($ip);
        $maintenant = $maintenant ?? time();

        // Message unique pour tous les refus qui suivent la validation de forme :
        // distinguer « compte inconnu » de « mot incorrect » rendrait l'un des
        // deux facteurs testable seul, et ferait de cet appel un oracle
        // d'existence de comptes.
        $refus = ['ok' => false, 'message' => 'Compte ou mot mémorisé incorrect.'];

        // 🔴 Avant tout calcul : enrôler ouvre le compte avec le seul mot mémorisé.
        // `Titulaire` porte la mesure du 13 août 2026 et ce que l'intégrateur doit
        // garantir. Le refus est ordinaire, pas une exception : celui qui découvre
        // ce paramètre le lit dans sa réponse, pas dans une page blanche.
        if ($titulaire !== Titulaire::AUTHENTIFIE) {
            return ['ok' => false, 'error' => 'titulaire_non_authentifie',
                    'message' => 'Enrôler un appareil demande une session ouverte du titulaire. '
                               . 'Qui a perdu son accès passe par la récupération.'];
        }

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

        if ($ip !== null
            && $this->stockage->compterEchecsIp($ip, $maintenant - $this->fenetreEchecs) >= $this->maxEchecsIp) {
            usleep($this->delaiRefusUs);

            return $this->refusFrein();
        }

        // 🔑 **L'étiquette vient du nom SOUMIS, avant toute recherche.** Tirée du
        // compte trouvé, elle n'existerait que pour les comptes réels : le frein ne
        // mordrait que sur eux, et six requêtes sur un nom choisi diraient s'il
        // existe — l'oracle que le message unique de cette méthode refuse. Mesuré
        // avant d'être corrigé : « Trop de tentatives » d'un côté, « Compte ou mot
        // mémorisé incorrect » de l'autre, au sixième essai.
        //
        // Le prix, assumé et déjà celui du niveau 1 : qui soumet un nom en boucle
        // ferme l'enrôlement de ce nom pendant la fenêtre. C'est un confort, pas une
        // récupération — et l'étiquette étant sous HMAC, aucune autre route ne peut
        // remplir ce compteur.
        $etiquette = $this->etiquetteEchecsEnrolement($nomCompte);

        // Le frein par compte, avant l'Argon2id : c'est le seul qui agisse quand
        // l'adresse ne distingue personne, et ce chemin n'a que le mot pour secret.
        // Pas de seuil de suspension, contrairement au niveau 2 : il n'y a pas de
        // feuille de codes à plafonner ici, et suspendre priverait un titulaire
        // légitime d'un confort sans borner autre chose.
        //
        // Le refus est celui du frein par adresse, au mot près, et paie le même délai.
        if ($this->stockage->compterEchecsCompte($etiquette, $maintenant - $this->fenetreEchecs)
            >= $this->maxEchecsCompte) {
            usleep($this->delaiRefusUs);

            return $this->refusFrein();
        }

        $compte = $this->stockage->trouverCompte($nomCompte);

        // Argon2id exécuté même sur compte inconnu : sinon le temps de réponse
        // dirait ce que le message se garde de dire.
        $motOk = Hashing::verify($motDerive, $compte['empreinte_mot'] ?? Hashing::dummyHash());
        $ok    = $compte !== null && $motOk;

        // L'étiquette est la même que le compte existe ou non : c'est ce qui rend les
        // deux cas indiscernables. Elle valait `enroll:inconnu` pour tout nom
        // introuvable, donc un compte de ce nom héritait du frein de tout le service.
        $this->stockage->tracerTentative($etiquette, $ok, $ip, $maintenant);

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

        return ['ok' => true, 'message' => 'Appareil enrôlé. Sa clé vit dans ce navigateur, chiffrée par ton '
                                         . 'mot mémorisé : un autre navigateur, ou des données de site effacées, '
                                         . 'demanderont un nouvel enrôlement.'];
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

        // Le défi a déjà été consommé plus haut, hors transaction : il doit
        // l'être même si ce qui suit échoue.
        $motDePasse = self::engendrerMotDePasse();
        $this->stockage->commencerTransaction();
        try {
            $this->stockage->remplacerEmpreinteMotDePasse($appareil->compteId, Hashing::hash($motDePasse));
            $this->stockage->revoquerSessions($appareil->compteId);
            $this->stockage->validerTransaction();
        } catch (\Throwable $e) {
            $this->stockage->annulerTransaction();

            throw $e;
        }

        return [
            'ok'           => true,
            'message'      => 'Appareil reconnu. Note ton nouveau mot de passe : il ne sera pas réaffiché. Ta passphrase et tes codes papier, eux, ne changent pas.',
            'mot_de_passe' => $motDePasse,
            'compte'       => $appareil->nomCompte,
        ];
    }

    /**
     * L'étiquette du compteur d'échecs d'enrôlement — un HMAC sous le sel du
     * déploiement. `Etiquette` dit pourquoi elle ne peut pas être en clair.
     *
     * 🔑 **Publique exprès**, comme celle du niveau 2 : un intégrateur qui pose son
     * propre frein devant ce chemin doit lire le compteur que la bibliothèque écrit,
     * au lieu de recopier une étiquette qui le laisserait muet le jour où elle change.
     */
    public function etiquetteEchecsEnrolement(string $nomCompte): string
    {
        return Etiquette::sous(self::PREFIXE_ENROLEMENT, $nomCompte, $this->selDeploiement);
    }

    /**
     * Le mot mémorisé a-t-il la forme d'une clé dérivée ?
     *
     * 🔑 **Le mot lui-même n'arrive jamais ici.** Le client calcule
     * `HMAC-SHA256(clé = mot, message = matériel | version + sel)` et n'envoie que le
     * résultat : 64 caractères hexadécimaux. Refuser toute autre forme est ce
     * qui tient la promesse — sans ce contrôle, un client resté sur une version
     * antérieure enverrait le mot en clair et le serveur l'accepterait sans que
     * rien ne le signale.
     */
    public static function estCleDerivee(string $valeur): bool
    {
        return (bool) preg_match('/^[0-9a-f]{64}$/', $valeur);
    }

    /**
     * Le refus des freins par fenêtre, identique à celui de `Recovery` : même
     * texte pour le frein par compte et par origine, délai tiré de la fenêtre.
     *
     * @return array{ok: false, message: string}
     */
    private function refusFrein(): array
    {
        return ['ok' => false, 'message' => 'Trop de tentatives. Réessaie dans ' . Duree::enClair($this->fenetreEchecs) . '.'];
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
