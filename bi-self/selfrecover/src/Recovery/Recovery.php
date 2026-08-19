<?php

declare(strict_types=1);

namespace Pierroons\SelfRecover\Recovery;

use Pierroons\SelfRecover\Crypto\Hashing;
use Pierroons\SelfRecover\Device\Device;
use Pierroons\SelfRecover\Diceware\Wordlist;
use Pierroons\SelfRecover\Storage\StorageInterface;

/**
 * Les deux premiers niveaux de l'escalade de récupération.
 *
 *   Niveau 1 — la passphrase diceware. Un seul facteur, mais à forte entropie
 *              (77 bits pour six mots) et jamais saisi ailleurs.
 *   Niveau 2 — un code de récupération ET le mot mémorisé. Deux facteurs de
 *              nature différente : une possession imprimable, une connaissance.
 *
 * Le niveau 3 — décision humaine sur faisceau de faits — reste applicatif : il
 * suppose une interface d'arbitrage, des échanges, une notion de litige. Rien
 * de tout cela n'appartient au protocole.
 *
 * 🔑 **Aucune erreur ne dit lequel des facteurs a échoué.** Le préciser rendrait
 * chacun attaquable seul, ce qui annulerait le bénéfice d'en exiger deux.
 */
final class Recovery
{
    public function __construct(
        private readonly StorageInterface $stockage,
        /**
         * Sel du déploiement, pour l'index de recherche des codes.
         *
         * ⚠️ Le changer rend tous les codes émis introuvables : ils ne sont
         * retrouvés que par `HMAC(code, sel)`. Il se conserve comme un secret
         * de service, hors webroot.
         */
        private readonly string $selDeploiement,
        private readonly int $fenetreEchecs = 900,
        private readonly int $maxEchecsCompte = 5,
        private readonly int $maxEchecsIp = 12,
        private readonly int $delaiRefusUs = 300000,
    ) {
    }

    /** Longueur du lot émis à l'inscription. */
    public const CODES_PAR_LOT = 10;

    /**
     * Niveau 1 — récupération par passphrase diceware.
     *
     * @return array{ok: bool, message: string, mot_de_passe?: string, passphrase?: string}
     */
    public function parPassphrase(
        string $nomCompte,
        string $passphrase,
        ?string $ip = null,
        ?int $maintenant = null,
    ): array {
        $maintenant = $maintenant ?? time();
        $nomCompte  = strtolower(trim($nomCompte));
        $refus      = ['ok' => false, 'message' => 'Identifiant ou passphrase incorrect.'];

        if ($frein = $this->freiner($nomCompte, $ip, $maintenant)) {
            return $frein;
        }

        // Espaces normalisés avant comparaison : « alpha  beta » et
        // « alpha beta » sont la même passphrase pour qui l'a recopiée.
        $passphrase = trim((string) preg_replace('/\s+/', ' ', $passphrase));

        $compte = $this->stockage->trouverComptePourPassphrase($nomCompte);

        // Argon2id exécuté même sur compte inconnu : sans cela le temps de
        // réponse trierait les comptes existants, ce que le message refuse.
        $ok = Hashing::verify($passphrase, $compte['empreinte_passphrase'] ?? Hashing::dummyHash())
            && $compte !== null;

        $this->stockage->tracerTentative($nomCompte, $ok, $ip, $maintenant);

        if (!$ok) {
            usleep($this->delaiRefusUs);

            return $refus;
        }

        // 🔑 La passphrase est consommée par son usage : on en émet une neuve.
        // La laisser valable ferait d'un papier volé une porte permanente, et
        // l'utilisateur croirait son accès rendu alors qu'il resterait partagé.
        $motDePasse     = Device::engendrerMotDePasse();
        $nouvellePhrase = implode(' ', Wordlist::generate(4, 'en')['words']);
        $this->stockage->remplacerEmpreintes(
            (int) $compte['id'],
            Hashing::hash($motDePasse),
            Hashing::hash($nouvellePhrase),
        );
        $this->stockage->revoquerSessions((int) $compte['id']);

        return [
            'ok'           => true,
            'message'      => 'Accès rendu. Le mot de récupération, lui, ne change pas.',
            'mot_de_passe' => $motDePasse,
            'passphrase'   => $nouvellePhrase,
        ];
    }

    /**
     * Niveau 2 — code de récupération ET mot mémorisé.
     *
     * 🔑 **Aucun identifiant n'est demandé.** Le code retrouve le compte par son
     * index de recherche, donc il n'existe aucun champ où éprouver l'existence
     * d'un compte : l'énumération n'a plus de porte.
     *
     * @return array{ok: bool, message: string, mot_de_passe?: string, compte?: string, codes_restants?: int}
     */
    public function parCode(
        string $code,
        string $motDerive,
        ?string $ip = null,
        ?int $maintenant = null,
    ): array {
        $maintenant = $maintenant ?? time();
        $code       = strtolower(trim($code));
        $refus      = ['ok' => false, 'message' => 'Code ou mot mémorisé incorrect.'];

        if (!Device::estCleDerivee($motDerive)) {
            return ['ok' => false, 'error' => 'invalid_derived_key',
                    'message' => 'Mot mémorisé invalide : la dérivation doit se faire dans le navigateur.'];
        }
        if ($ip !== null
            && $this->stockage->compterEchecsIp($ip, $maintenant - $this->fenetreEchecs) >= $this->maxEchecsIp) {
            return ['ok' => false, 'message' => 'Trop de tentatives. Réessaie dans 15 minutes.'];
        }

        // Forme validée avant le moindre calcul : un code qui n'a pas la forme
        // d'un code n'a pas à coûter un HMAC, encore moins un Argon2id.
        if (!preg_match('/^[a-f0-9]{5}-[a-f0-9]{5}$/', $code)) {
            usleep($this->delaiRefusUs);

            return $refus;
        }

        $trouve = $this->stockage->trouverCodeParIndex($this->indexRecherche($code));

        // Les deux vérifications sont menées quoi qu'il arrive : s'arrêter à la
        // première échouée dirait, par le temps, laquelle a échoué.
        $codeOk = Hashing::verify($code, $trouve['empreinte_code'] ?? Hashing::dummyHash());
        $motOk  = Hashing::verify($motDerive, $trouve['empreinte_mot'] ?? Hashing::dummyHash());
        $ok     = $trouve !== null && !$trouve['deja_utilise'] && $codeOk && $motOk;

        $this->stockage->tracerTentative(
            'code:' . ($trouve !== null ? $trouve['nom_compte'] : 'inconnu'),
            $ok,
            $ip,
            $maintenant,
        );

        if (!$ok) {
            usleep($this->delaiRefusUs);

            return $refus;
        }

        $this->stockage->consommerCode((int) $trouve['code_id'], $maintenant);

        // Rendre l'accès renouvelle les deux secrets, pas seulement le mot de
        // passe : qui a dû récupérer ne sait pas ce qui a fuité. Laisser
        // l'ancienne passphrase valable garderait ouverte une porte dont on
        // ignore si elle est connue.
        $motDePasse     = Device::engendrerMotDePasse();
        $nouvellePhrase = implode(' ', Wordlist::generate(4, 'en')['words']);
        $this->stockage->remplacerEmpreintes(
            (int) $trouve['compte_id'],
            Hashing::hash($motDePasse),
            Hashing::hash($nouvellePhrase),
        );
        $this->stockage->revoquerSessions((int) $trouve['compte_id']);

        return [
            'ok'             => true,
            'message'        => 'Accès rendu. Le mot de récupération, lui, ne change pas.',
            'mot_de_passe'   => $motDePasse,
            'passphrase'     => $nouvellePhrase,
            'compte'         => $trouve['nom_compte'],
            'codes_restants' => $this->stockage->compterCodesRestants((int) $trouve['compte_id']),
        ];
    }

    /**
     * Émet un lot de codes et rend les codes en clair — la seule fois.
     *
     * Quarante bits par code. C'est peu contre une attaque hors ligne, mais un
     * code ne vit jamais seul : le mot mémorisé est exigé avec lui, et le
     * rate-limit s'applique. Sa fonction est d'être imprimable, pas d'être un
     * secret maximal.
     *
     * @return list<string>
     */
    public function emettreCodes(int $compteId, int $combien = self::CODES_PAR_LOT, ?int $maintenant = null): array
    {
        $maintenant = $maintenant ?? time();
        $this->stockage->purgerCodes($compteId);

        $codes = [];
        for ($i = 0; $i < $combien; $i++) {
            $brut = bin2hex(random_bytes(5));
            $code = substr($brut, 0, 5) . '-' . substr($brut, 5, 5);
            $this->stockage->enregistrerCode(
                $compteId,
                $this->indexRecherche($code),
                Hashing::hash($code),
                $maintenant,
            );
            $codes[] = $code;
        }

        return $codes;
    }

    /**
     * Index de recherche d'un code — un HMAC, pas un chiffrement.
     *
     * Il permet de retrouver la ligne sans stocker le code, et sans que la base
     * exfiltrée ne rende les codes : reconstituer l'index suppose le sel.
     */
    public function indexRecherche(string $code): string
    {
        return hash_hmac('sha256', strtolower(trim($code)), $this->selDeploiement);
    }

    /**
     * Freins communs au niveau 1 : par compte visé, puis par origine.
     *
     * @return array{ok: bool, message: string}|null
     */
    private function freiner(string $nomCompte, ?string $ip, int $maintenant): ?array
    {
        $depuis = $maintenant - $this->fenetreEchecs;

        if ($this->stockage->compterEchecsCompte($nomCompte, $depuis) >= $this->maxEchecsCompte) {
            return ['ok' => false, 'message' => 'Trop de tentatives. Réessaie dans 15 minutes.'];
        }
        if ($ip !== null && $this->stockage->compterEchecsIp($ip, $depuis) >= $this->maxEchecsIp) {
            return ['ok' => false, 'message' => 'Trop de tentatives. Réessaie dans 15 minutes.'];
        }

        return null;
    }
}
