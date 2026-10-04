<?php

declare(strict_types=1);

namespace Pierroons\SelfRecover\Recovery;

use Pierroons\SelfRecover\Crypto\Hashing;
use Pierroons\SelfRecover\Device\Device;
use Pierroons\SelfRecover\Diceware\Wordlist;
use Pierroons\SelfRecover\Duree;
use Pierroons\SelfRecover\Etiquette;
use Pierroons\SelfRecover\ProfilDeploiement;
use LogicException;
use Pierroons\SelfRecover\Storage\CodeDejaConsomme;
use Pierroons\SelfRecover\Storage\SelParCodeInterface;
use Pierroons\SelfRecover\Storage\StorageInterface;

/**
 * Les deux premiers niveaux de l'escalade de récupération.
 *
 *   Niveau 1 — la passphrase diceware. Un seul facteur, mais à forte entropie
 *              (≈77,5 bits pour six mots de la liste EFF) et jamais saisi
 *              ailleurs. `MOTS_PASSPHRASE` fixe la longueur, à un seul
 *              endroit : les démos le lisent au lieu d'écrire leur nombre.
 *   Niveau 2 — un code de récupération ET le mot mémorisé. Deux facteurs de
 *              nature différente : une possession imprimable, une connaissance.
 *
 *   Niveau 3 — la décision humaine sur faisceau de faits, quand il ne reste
 *              aucun secret. Il vit dans `Escalade`, depuis le 07/09/2026 :
 *              porté par deux applications, il y avait divergé, et l'une
 *              supprimait le compte au premier refus. Ce que le protocole doit
 *              garantir ne pouvait pas rester au libre choix de chacune.
 *
 * 🔑 L'arbitrage suppose des rôles et des sessions, que la bibliothèque ne
 * connaît pas : `Escalade` ne vérifie jamais qu'un appelant a le droit de
 * trancher. Elle garantit le reste — le sésame, le faisceau, et qu'aucun refus
 * ne touche au compte.
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
        /**
         * **Obligatoire, sans défaut.** Le contrat est dans `ProfilDeploiement` :
         * il ne se devine pas depuis le transport.
         */
        private readonly ProfilDeploiement $profil,
        private readonly int $fenetreEchecs = 900,
        private readonly int $maxEchecsCompte = 5,
        private readonly int $maxEchecsIp = 12,
        private readonly int $delaiRefusUs = 300000,
        /**
         * Échecs du niveau 2 au-delà desquels il est suspendu pour ce compte,
         * jusqu'au prochain réarmement — l'émission d'un lot de codes, ou une
         * récupération par code réussie.
         *
         * Il borne ce qu'ouvre une feuille volée : `maxEchecsCompte` seul fait
         * attendre, il ne plafonne pas. Le titulaire lève la suspension par sa
         * passphrase ou par le niveau 3, puis renouvelle ses codes.
         */
        private readonly int $maxEchecsL2AvantSuspension = 20,
    ) {
    }

    /**
     * Préfixe des compteurs d'échec du niveau 2. En clair, pour qu'une console
     * sache quoi ne pas afficher comme une tentative de connexion.
     */
    private const PREFIXE_L2 = 'l2:';

    /** Longueur du lot émis à l'inscription. */
    public const CODES_PAR_LOT = 10;

    /**
     * Mots tirés pour une passphrase de niveau 1 — six valent ≈77,5 bits.
     *
     * Ne vaut que pour les passphrases engendrées : la vérification compare
     * une empreinte et ne compte pas les mots, donc une passphrase plus courte
     * délivrée avant un changement de cette valeur reste valide.
     */
    public const MOTS_PASSPHRASE = 6;

    /**
     * Niveau 1 — récupération par passphrase diceware.
     *
     * `age_jours` dit depuis combien de jours la passphrase qui vient de servir
     * avait été émise, ou `null` si le déploiement ne tient pas cette date.
     * **Elle informe, elle ne refuse jamais** — le contrat de
     * `trouverComptePourPassphrase()` dit pourquoi.
     *
     * @return array{ok: bool, message: string, mot_de_passe?: string, passphrase?: string, age_jours?: int|null}
     */
    public function parPassphrase(
        string $nomCompte,
        string $passphrase,
        ?string $ip = null,
        ?int $maintenant = null,
    ): array {
        $this->profil->verifierOrigine($ip);
        $maintenant = $maintenant ?? time();
        $nomCompte  = strtolower(trim($nomCompte));
        $refus      = ['ok' => false, 'message' => 'Identifiant ou passphrase incorrect.'];

        if ($frein = $this->freiner($nomCompte, $ip, $maintenant)) {
            return $frein;
        }

        $passphrase = self::normaliserPassphrase($passphrase);

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
        $nouvellePhrase = self::engendrerPassphrase();

        $this->stockage->commencerTransaction();
        try {
            $this->stockage->remplacerEmpreintes(
                (int) $compte['id'],
                Hashing::hash($motDePasse),
                Hashing::hash($nouvellePhrase),
            );
            $this->stockage->revoquerSessions((int) $compte['id']);
            $this->stockage->validerTransaction();
        } catch (\Throwable $e) {
            $this->stockage->annulerTransaction();

            throw $e;
        }

        // 🔑 L'âge de la passphrase qui VIENT DE SERVIR, jamais celui de la
        // neuve. C'est le seul moment où la bibliothèque lit cette date, et la
        // question utile est « depuis combien de temps ce papier traînait-il ».
        // Rendu pour informer — journal, message à l'utilisateur, ce que
        // l'application en fait ne regarde pas le protocole. Rien ici n'a été
        // refusé à cause de l'âge, et rien ne le sera : voir le contrat de
        // `trouverComptePourPassphrase()`.
        $emiseLe = $compte['emise_le'] ?? null;

        return [
            'ok'           => true,
            'message'      => 'Accès rendu. Ton mot de passe et ta passphrase ont été remplacés : note-les, les anciens ne valent plus rien et ceux-ci ne seront pas réaffichés. Le mot mémorisé, lui, ne change pas.',
            'mot_de_passe' => $motDePasse,
            'passphrase'   => $nouvellePhrase,
            'age_jours'    => is_int($emiseLe) ? intdiv(max(0, $maintenant - $emiseLe), 86400) : null,
        ];
    }

    /**
     * Niveau 2 — code de récupération ET mot mémorisé.
     *
     * 🔑 **Aucun identifiant n'est demandé.** Le code retrouve le compte par son
     * index de recherche, donc il n'existe aucun champ où éprouver l'existence
     * d'un compte : l'énumération n'a plus de porte.
     *
     * @return array{ok: bool, error?: string, message: string, mot_de_passe?: string, passphrase?: string, compte?: string, codes_restants?: int}
     */
    public function parCode(
        string $code,
        string $motDerive,
        ?string $ip = null,
        ?int $maintenant = null,
    ): array {
        $this->profil->verifierOrigine($ip);
        $maintenant = $maintenant ?? time();
        $code       = strtolower(trim($code));
        $refus      = ['ok' => false, 'message' => 'Code ou mot mémorisé incorrect.'];

        if (!Device::estCleDerivee($motDerive)) {
            return ['ok' => false, 'error' => 'invalid_derived_key',
                    'message' => 'Mot mémorisé invalide : la dérivation doit se faire dans le navigateur.'];
        }
        if ($ip !== null
            && $this->stockage->compterEchecsIp($ip, $maintenant - $this->fenetreEchecs) >= $this->maxEchecsIp) {
            return $this->refusFrein();
        }

        // Forme validée avant le moindre calcul : un code qui n'a pas la forme
        // d'un code n'a pas à coûter un HMAC, encore moins un Argon2id.
        if (!self::estFormeCode($code)) {
            usleep($this->delaiRefusUs);

            return $refus;
        }

        $trouve = $this->stockage->trouverCodeParIndex($this->indexRecherche($code));

        // Le frein par compte avant les deux Argon2id, donc avant toute
        // consommation : un essai freiné ne coûte ni code ni place au quota de
        // l'appelant. Il ne s'applique qu'à un code retrouvé — sans compte, il
        // n'y a pas de compteur à consulter, et le frein par origine tient seul.
        $etiquette = $trouve !== null ? $this->etiquetteEchecsL2($trouve['nom_compte']) : null;
        if ($etiquette !== null) {
            $frein = $this->freinerNiveau2(
                $etiquette,
                (string) $trouve['nom_compte'],
                (int) $trouve['compte_id'],
                $maintenant,
            );
            if ($frein !== null) {
                return $frein;
            }
        }

        // Les deux vérifications sont menées quoi qu'il arrive : s'arrêter à la
        // première échouée dirait, par le temps, laquelle a échoué.
        $codeOk = Hashing::verify($code, $trouve['empreinte_code'] ?? Hashing::dummyHash());
        $motOk  = Hashing::verify($motDerive, $trouve['empreinte_mot'] ?? Hashing::dummyHash());
        $ok     = $trouve !== null && !$trouve['deja_utilise'] && $codeOk && $motOk;

        // 🔑 Un code introuvable n'est rattaché à AUCUN compte. La ligne garde en
        // revanche son adresse : le frein par origine continue de la voir.
        $this->stockage->tracerTentative($etiquette, $ok, $ip, $maintenant);

        if (!$ok) {
            usleep($this->delaiRefusUs);

            return $refus;
        }

        $this->stockage->commencerTransaction();
        try {
            $this->stockage->consommerCode((int) $trouve['code_id'], $maintenant);

        // Rendre l'accès renouvelle les deux secrets, pas seulement le mot de
        // passe : qui a dû récupérer ne sait pas ce qui a fuité. Laisser
        // l'ancienne passphrase valable garderait ouverte une porte dont on
        // ignore si elle est connue.
            $motDePasse     = Device::engendrerMotDePasse();
            $nouvellePhrase = self::engendrerPassphrase();
            $this->stockage->remplacerEmpreintes(
                (int) $trouve['compte_id'],
                Hashing::hash($motDePasse),
                Hashing::hash($nouvellePhrase),
            );
            $this->stockage->revoquerSessions((int) $trouve['compte_id']);
            $this->stockage->validerTransaction();
        } catch (CodeDejaConsomme) {
            // 🔑 Une seconde requête portant le même code est arrivée pendant nos
            // deux Argon2id : la garde de l'écriture a tranché, et le perdant reçoit
            // le refus ordinaire. Le laisser remonter rendrait une erreur de serveur
            // sur un simple double-clic — et ce serait un oracle, puisqu'on n'arrive
            // ici qu'avec les DEUX facteurs bons.
            $this->stockage->annulerTransaction();
            usleep($this->delaiRefusUs);

            return $refus;
        } catch (\Throwable $e) {
            $this->stockage->annulerTransaction();

            throw $e;
        }

        return [
            'ok'             => true,
            'message'        => 'Accès rendu. Ton mot de passe et ta passphrase ont été remplacés : note-les, les anciens ne valent plus rien et ceux-ci ne seront pas réaffichés. Le mot mémorisé, lui, ne change pas.',
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
    /**
     * Une passphrase neuve, de la longueur du protocole.
     *
     * ⚠️ Définie ICI et nulle part ailleurs. Elle était engendrée à deux
     * endroits de ce fichier et à un troisième dans le niveau 3 : trois copies
     * du même choix, qui n'ont aucune raison de rester d'accord. Changer la
     * longueur se fait sur cette ligne, et se répercute partout.
     */
    public static function engendrerPassphrase(): string
    {
        return implode(' ', Wordlist::generate(self::MOTS_PASSPHRASE, 'en')['words']);
    }

    /**
     * La passphrase telle qu'on la compare : espaces de bord retirés, toute
     * suite d'espaces réduite à un seul. « alpha  beta » et « alpha beta » sont
     * la même passphrase pour qui l'a recopiée.
     *
     * SelfDataGuard scelle la serrure « passphrase » d'un coffre sur la même
     * chaîne : `tests/sanity_couplage_dataguard.php` tient les deux d'accord.
     */
    public static function normaliserPassphrase(string $passphrase): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $passphrase));
    }

    /**
     * Le refus des freins par fenêtre. Un seul texte pour tous : le frein par
     * compte ne doit pas se distinguer du frein par origine. Le délai annoncé est
     * celui de la fenêtre réglée, pas un nombre recopié.
     *
     * @return array{ok: false, message: string}
     */
    private function refusFrein(): array
    {
        return ['ok' => false, 'message' => 'Trop de tentatives. Réessaie dans ' . Duree::enClair($this->fenetreEchecs) . '.'];
    }

    /**
     * Octets du sel de compte, engendré par le navigateur à l'inscription et
     * transmis en `2 × SEL_OCTETS` hexadécimaux minuscules. Le client JS
     * (`sr-derive.js`, `sr-kdf.js`) et les schémas SQL le déclarent de leur
     * côté : `tests/sanity_forme_sel.php` les tient d'accord.
     */
    public const SEL_OCTETS = 16;

    /** Le sel a-t-il la forme d'un sel de compte ? */
    public static function estSelCompte(string $sel): bool
    {
        return strlen($sel) === 2 * self::SEL_OCTETS && ctype_xdigit($sel) && strtolower($sel) === $sel;
    }

    /**
     * Le sel de dérivation à rendre au navigateur qui présente ce code.
     *
     * Au niveau 2, le navigateur dérive avant que le compte soit identifié : c'est
     * le code qui l'identifie. La route qui sert ce sel est donc publique et sans
     * authentification, et c'est à l'intégrateur de l'écrire ; cette méthode en
     * est la garde.
     *
     * 🔑 **Toujours un sel.** Répondre pour un code valide et refuser un code
     * inconnu permettrait d'éprouver les codes au prix d'une requête, sans payer
     * un seul Argon2id. Pour un code inconnu, le sel est fabriqué : un HMAC du code
     * sous le sel du déploiement, de la forme d'un vrai, stable si le code est
     * retenté, et calculé dans tous les cas pour que les deux chemins coûtent
     * pareil. La normalisation est celle de `parCode()` : sinon un code en
     * majuscules recevrait un faux sel ici et serait accepté là-bas.
     *
     * @throws LogicException si le stockage n'implémente pas SelParCodeInterface
     */
    public function selDeDerivation(string $code): string
    {
        if (!$this->stockage instanceof SelParCodeInterface) {
            throw new LogicException(
                'selDeDerivation() : le stockage doit implémenter SelParCodeInterface, '
                . 'qui retrouve le sel du compte par l\'index du code.'
            );
        }
        $code = strtolower(trim($code));
        $faux = substr(Etiquette::empreinte('sel-absent:' . $code, $this->selDeploiement), 0, 2 * self::SEL_OCTETS);
        if (!self::estFormeCode($code)) {
            return $faux;
        }
        $vrai = $this->stockage->selDuCompteParIndexCode($this->indexRecherche($code));

        return $vrai !== null && $vrai !== '' ? $vrai : $faux;
    }

    /**
     * Le code a-t-il la forme de ceux qu'`emettreCodes()` fabrique ?
     *
     * Les intégrateurs qui filtrent avant d'appeler `parCode()` l'appellent au
     * lieu de recopier l'expression : un format changé ici leur parvient.
     */
    public static function estFormeCode(string $code): bool
    {
        return preg_match('/^[a-f0-9]{5}-[a-f0-9]{5}$/', $code) === 1;
    }

    /**
     * Émet un lot de codes neufs et les rend en clair, cette fois seulement.
     *
     * ⚠️ **Efface d'abord le lot en place** : la feuille que le titulaire a
     * imprimée cesse de valoir à cet appel, et rien dans le retour ne le
     * rappelle — c'est à l'application de le lui dire. À n'appeler qu'à
     * l'inscription ou sur sa demande.
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
     * Le profil de ce déploiement.
     *
     * 🔑 `Escalade` compose une `Recovery` : elle lit le profil ici plutôt que de
     * recevoir le sien. Deux copies du même choix n'ont aucune raison de rester
     * d'accord, et celle qui diverge appliquerait le mauvais frein.
     */
    public function profil(): ProfilDeploiement
    {
        return $this->profil;
    }

    /**
     * Index de recherche d'un code — un HMAC, pas un chiffrement.
     *
     * Il permet de retrouver la ligne sans stocker le code, et sans que la base
     * exfiltrée ne rende les codes : reconstituer l'index suppose le sel.
     */
    public function indexRecherche(string $code): string
    {
        return Etiquette::empreinte($code, $this->selDeploiement);
    }

    /**
     * L'étiquette du compteur d'échecs du niveau 2 — un HMAC sous le sel du
     * déploiement.
     *
     * ⚠️ **Le sel n'est pas là pour cacher, il est là pour EMPÊCHER D'ÉCRIRE** :
     * sans lui, le compteur des codes papier d'un compte se remplit depuis la
     * page de connexion. Le raisonnement en entier est dans `Etiquette`.
     *
     * 🔑 **Publique exprès.** Un intégrateur qui pose son propre frein devant le
     * niveau 2 doit lire le même compteur que celui que la bibliothèque écrit.
     * Recopier l'étiquette le condamnerait à devenir muet le jour où elle
     * change, sans qu'aucune erreur ne le dise.
     */
    public function etiquetteEchecsL2(string $nomCompte): string
    {
        return Etiquette::sous(self::PREFIXE_L2, $nomCompte, $this->selDeploiement);
    }

    /**
     * Freins du niveau 2, par compte, sur un code déjà retrouvé.
     *
     * 🔑 **L'objection faite au blocage de compte ne vaut pas ici.** À la
     * connexion, n'importe qui fermerait le compte d'un autre en échouant
     * exprès ; pour charger ce compteur, il faut un code du compte, même déjà
     * consommé, et l'étiquette n'est pas fabricable sans le sel.
     *
     * ⚠️ **Ce que la suspension concède, et que le frein par fenêtre ne concède
     * pas.** Elle doit se dire, sinon le titulaire ne sait pas quoi faire : son
     * refus est donc le seul de cette classe qui nomme un état. Il apprend à qui
     * détient déjà un code que ce code appartient à un compte réel, et il l'apprend
     * plus vite que le refus ordinaire, faute des deux Argon2id. Aucune énumération
     * n'en sort — il faut déjà détenir un code —, mais un code partiellement
     * illisible se complète à ce prix. Le frein par fenêtre, lui, rend le message
     * du frein par origine et paie le délai : il est indiscernable.
     *
     * ⚠️ **La suspension exige une sortie, et elle vient du déploiement.** Trois
     * gestes la lèvent, parce que tous les trois réarment le compteur : un lot de
     * codes neufs, une récupération par code réussie, une récupération par
     * passphrase réussie. Le dernier est le seul qui soit toujours à portée du
     * titulaire sans qu'aucune route ne soit ajoutée, et il est hors de portée de
     * qui n'a volé que la feuille. Un déploiement qui ne tient aucune de ces trois
     * dates ne suspend pas : voir plus bas.
     *
     * ⚠️ Le compteur est lu ici et écrit après l'essai : des requêtes
     * simultanées sur un même compte passent ensemble, soit au plus le seuil plus
     * le nombre de requêtes servies en parallèle, moins une. Le frein du niveau 1
     * a la même borne.
     *
     * ⚠️ Un seuil de suspension inférieur au frein par fenêtre rendrait celui-ci
     * inatteignable : la suspension mordrait la première.
     *
     * @return array{ok: bool, error?: string, message: string}|null
     */
    private function freinerNiveau2(
        string $etiquette,
        string $nomCompte,
        int $compteId,
        int $maintenant,
    ): ?array {
        $rearmements = array_filter([
            $this->stockage->dateDernierCodeEmis($compteId),
            $this->stockage->dateDerniereReussite($etiquette),
            $this->stockage->dateDerniereReussite($nomCompte),
        ], static fn (?int $quand): bool => $quand !== null);

        // 🔑 Aucune des trois dates n'est tenue par ce déploiement : on ne suspend
        // pas. Prendre zéro pour point de réarmement compterait les échecs depuis
        // 1970 — la suspension tomberait sur l'usure d'années, et aucun geste ne la
        // lèverait, puisque la date qui la lève est celle qui manque. Le niveau 1
        // traite le même piège de la même façon, pour la date d'émission.
        if ($rearmements !== []) {
            // ⚠️ `compterEchecsCompte()` compte ce qui est STRICTEMENT postérieur à
            // sa borne. Un échec survenu dans la seconde même du réarmement compte
            // pour après lui : la borne passée est donc reculée d'une seconde.
            $depuis = max($rearmements) - 1;
            if ($this->stockage->compterEchecsCompte($etiquette, $depuis)
                >= $this->maxEchecsL2AvantSuspension) {
                usleep($this->delaiRefusUs);

                return ['ok' => false, 'error' => 'l2_suspendu',
                        'message' => 'Trop d\'essais manqués : la récupération par code est suspendue pour '
                                   . 'ce compte. Récupère ton accès par ta passphrase.'];
            }
        }

        // 🔑 Le message est celui du frein par origine, au mot près, et le délai
        // est payé : un refus qui nomme le compte dirait à qui détient un code que
        // ce code appartient à un compte réel — et le dirait sans payer les deux
        // Argon2id, donc en offrant un oracle gratuit sur le premier facteur.
        if ($this->stockage->compterEchecsCompte($etiquette, $maintenant - $this->fenetreEchecs)
            >= $this->maxEchecsCompte) {
            usleep($this->delaiRefusUs);

            return $this->refusFrein();
        }

        return null;
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
            return $this->refusFrein();
        }
        if ($ip !== null && $this->stockage->compterEchecsIp($ip, $depuis) >= $this->maxEchecsIp) {
            return $this->refusFrein();
        }

        return null;
    }
}
