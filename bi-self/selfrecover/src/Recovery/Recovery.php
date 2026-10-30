<?php

declare(strict_types=1);

namespace Pierroons\SelfRecover\Recovery;

use Pierroons\SelfRecover\Crypto\Hashing;
use Pierroons\SelfRecover\Device\Device;
use Pierroons\SelfRecover\Diceware\Wordlist;
use Pierroons\SelfRecover\Duree;
use Pierroons\SelfRecover\Etiquette;
use Pierroons\SelfRecover\Langue;
use Pierroons\SelfRecover\Messages;
use Pierroons\SelfRecover\ProfilDeploiement;
use LogicException;
use Pierroons\SelfRecover\Storage\CodeDejaConsomme;
use Pierroons\SelfRecover\Storage\PassphraseDejaConsommee;
use Pierroons\SelfRecover\Storage\SelParCodeInterface;
use Pierroons\SelfRecover\Storage\StorageInterface;

/**
 * Les deux premiers niveaux de l'escalade de récupération.
 *
 *   Niveau 1 — la passphrase diceware. Un seul facteur, mais à forte entropie
 *              (≈77,5 bits pour six mots tirés uniformément) et jamais saisi
 *              ailleurs. Le serveur la tire, ou l'utilisateur l'apporte, tirée
 *              aux dés : `validerPassphraseApportee()`. `MOTS_PASSPHRASE` fixe
 *              la longueur, à un seul endroit : le code des démos le lit au
 *              lieu d'écrire son nombre ; leurs pages l'écrivent en lettres.
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
        #[\SensitiveParameter] private readonly string $selDeploiement,
        /**
         * **Obligatoire, sans défaut.** Le contrat est dans `ProfilDeploiement` :
         * il ne se devine pas depuis le transport.
         */
        private readonly ProfilDeploiement $profil,
        /**
         * **Obligatoire, sans défaut.** Le contrat est dans `Langue` : elle
         * gouverne ce que le titulaire lit ET la liste dans laquelle sa
         * passphrase est tirée. Un défaut imposerait sa langue à tout
         * intégrateur sans le dire.
         */
        private readonly Langue $langue,
        private readonly int $fenetreEchecs = 900,
        /**
         * Échecs par compte avant que le **niveau 2** freine ce compte.
         *
         * ⚠️ Le niveau 1 ne freine plus par compte : son refus ne dépend que de
         * l'origine, et `freiner()` dit pourquoi. Le régler n'a donc aucun effet
         * sur la récupération par passphrase.
         */
        private readonly int $maxEchecsCompte = 5,
        private readonly int $maxEchecsIp = 12,
        private readonly int $delaiRefusUs = 300000,
        /**
         * Échecs du niveau 2 au-delà desquels il est suspendu pour ce compte,
         * jusqu'au prochain réarmement — l'émission d'un lot de codes, une
         * récupération par code ou par passphrase réussie.
         *
         * Il borne ce qu'ouvre une feuille volée : `maxEchecsCompte` seul fait
         * attendre, il ne plafonne pas. Le titulaire lève la suspension par sa
         * passphrase ou par le niveau 3, puis renouvelle ses codes.
         */
        private readonly int $maxEchecsL2AvantSuspension = 20,
        /**
         * Ce qui suit chaque récupération acceptée, dans sa transaction : le
         * coffre, par le pont. `null` : rien ne suit, et la réponse le dit
         * (`suite` = `aucune`). `Escalade` et `Device` la lisent ici.
         */
        private readonly ?SuiteDeReprise $suite = null,
    ) {
    }

    /** La suite branchée, que `Escalade` appelle sur les chemins du niveau 3. */
    public function suite(): ?SuiteDeReprise
    {
        return $this->suite;
    }

    /** Ce que la suite branchée rend, ou `aucune`. Appelé dans la transaction. */
    private function suivre(Reprise $reprise): string
    {
        return $this->suite?->suivre($reprise) ?? 'aucune';
    }

    /**
     * Nombre d'essais plausibles dans la fenêtre **à partir duquel** il y a de
     * quoi réveiller quelqu'un. Trois : les deux premiers sont silencieux.
     *
     * 🔑 **Les premiers essais plausibles ne réveillent personne.** Qui range
     * plusieurs passphrases les essaie l'une après l'autre avant la bonne.
     *
     * ⚠️ **Une recommandation, pas une décision.** La bibliothèque ne compare
     * rien à ce seuil : `essaisPlausiblesL1()` rend le compte, et le déploiement
     * choisit. Le mettre ici serait décider à sa place de qui on réveille.
     */
    public const ESSAIS_PLAUSIBLES_SIGNALES = 3;

    /** Longueur du lot émis à l'inscription. */
    public const CODES_PAR_LOT = 10;

    /**
     * Mots d'une passphrase de niveau 1 — six tirés uniformément valent ≈77,5 bits.
     *
     * Longueur d'une passphrase engendrée, et minimum d'une passphrase apportée.
     * La vérification compare une empreinte et ne compte pas les mots : une
     * passphrase plus courte délivrée avant un changement de cette valeur reste
     * valide.
     */
    public const MOTS_PASSPHRASE = 6;

    /**
     * Plancher du classement d'un essai : en deçà, la saisie n'est pas comparée
     * aux listes.
     *
     * ⚠️ **Il ne suit PAS `MOTS_PASSPHRASE`, et c'est le point.** Celui-ci décrit
     * ce que la bibliothèque émet aujourd'hui ; il est passé de quatre à six, et
     * les passphrases plus courtes émises avant restent valides — le banc
     * d'équivalence en éprouve une. Aligner le plancher sur la longueur du jour
     * rendrait le classement **aveugle sur les comptes les plus anciens**, donc
     * les plus faibles : leurs essais ne seraient jamais comptés, et c'est le
     * seul signal qui reste à ce niveau. Cette valeur est la plus petite longueur
     * jamais émise ; elle ne descend pas, pour qu'un mot isolé de la liste ne
     * compte pas pour un essai.
     */
    public const MOTS_MINIMUM_CLASSEMENT = 4;

    /**
     * Plafond d'une passphrase apportée, contrôlé avant toute expression
     * régulière : elle est validée avant les freins, son coût doit être borné.
     */
    public const OCTETS_PASSPHRASE_MAXIMUM = 1024;

    /**
     * Niveau 1 — récupération par passphrase diceware.
     *
     * `age_jours` dit depuis combien de jours la passphrase qui vient de servir
     * avait été émise, ou `null` si le déploiement ne tient pas cette date.
     * **Elle informe, elle ne refuse jamais** — le contrat de
     * `trouverComptePourPassphrase()` dit pourquoi.
     *
     * `$nouvellePassphrase` : la passphrase que l'utilisateur apporte pour
     * remplacer celle qui sert, au lieu d'en recevoir une tirée par le serveur
     * (`validerPassphraseApportee()`). `null` : le serveur tire.
     *
     * `compte` : le nom sous lequel le compte a été trouvé — la saisie normalisée,
     * pas la saisie. C'est lui que l'application emploie pour enchaîner (session,
     * clé de SelfDataGuard), comme au niveau 2.
     *
     * 🔑 **Le retour du titulaire a sa propre forme.** Dans la fenêtre d'après un
     * niveau 3, un secret d'avant rend le compte à son titulaire : la réponse porte
     * `titulaire_reprend`, un lot `codes` neuf — l'ancien ne vaut plus, et il n'y a
     * pas de `codes_restants` —, `mot_memorise_a_changer`, `appareils_retires` et
     * `suite`. Une enveloppe qui recopie la forme ordinaire perd le lot neuf, en
     * silence.
     *
     * @return array{ok: bool, message: string, error?: string, motif?: string, mot_de_passe?: string, passphrase?: string, compte?: string, age_jours?: int|null, titulaire_reprend?: bool, mot_memorise_a_changer?: bool, codes?: list<string>, appareils_retires?: int, suite?: string}
     */
    public function parPassphrase(
        string $nomCompte,
        #[\SensitiveParameter] string $passphrase,
        ?string $ip = null,
        ?int $maintenant = null,
        #[\SensitiveParameter] ?string $nouvellePassphrase = null,
    ): array {
        $this->profil->verifierOrigine($ip);
        $maintenant = $maintenant ?? time();
        $debut      = hrtime(true);
        $nomCompte  = strtolower(trim($nomCompte));
        $refus      = ['ok' => false, 'message' => Messages::dire($this->langue, 'l1.refus')];

        // 🔑 La passphrase apportée est jugée avant les freins, sans trace ni
        // délai : rien n'a encore été vérifié, et son refus ne dit rien du
        // compte. Ce n'est pas un essai manqué ; il n'y a rien à compter.
        $apport = null;
        if ($nouvellePassphrase !== null) {
            $jugee = self::validerPassphraseApportee($nouvellePassphrase);
            if (!$jugee['ok']) {
                return $this->direRefusPassphrase($jugee);
            }
            $apport = $jugee['canonique'];
        }

        if ($frein = $this->freiner($ip, $maintenant)) {
            return $frein;
        }

        // 🔑 **Le seul refus qu'on puisse opposer sans comparer**, et il se dit.
        // Avant la normalisation, qui copierait tout le corps de la requête :
        // `OCTETS_PASSPHRASE_MAXIMUM` dit pourquoi son contrôle précède toute
        // expression régulière.
        //
        // ⚠️ **Il nomme son motif, parce qu'il peut refuser un bon secret.**
        // L'inscription appartient à l'application (`remplacerEmpreintes()`), qui
        // a pu ranger l'empreinte d'une passphrase plus longue que ce plafond :
        // la taire derrière le refus générique enfermerait son titulaire dehors
        // sans lui dire quoi faire. Ce motif ne dépend que de la saisie et ne
        // trie aucun compte.
        if (strlen($passphrase) > self::OCTETS_PASSPHRASE_MAXIMUM) {
            $this->stockage->tracerTentative(
                $this->etiquetteEchecsL1($nomCompte), false, $ip, $maintenant,
            );
            usleep($this->delaiRefusUs);

            return ['ok' => false, 'error' => 'passphrase_trop_longue',
                    'message' => Messages::dire($this->langue, 'passphrase.trop_longue_soumise',
                                                [self::OCTETS_PASSPHRASE_MAXIMUM])];
        }

        $passphrase = self::normaliserPassphrase($passphrase);

        $compte = $this->stockage->trouverComptePourPassphrase($nomCompte);

        // Argon2id exécuté même sur compte inconnu : sans cela le temps de
        // réponse trierait les comptes existants, ce que le message refuse.
        $ok = Hashing::verify($passphrase, $compte['empreinte_passphrase'] ?? Hashing::dummyHash())
            && $compte !== null;

        // 🔑 Dans la fenêtre d'après un niveau 3, la passphrase d'avant vaut encore,
        // une fois : le titulaire qui revient l'emporte sur le demandeur. Elle coûte
        // un Argon2id de plus, que l'échéance commune des refus couvre : le
        // chronomètre ne dit pas qu'un compte sort d'un niveau 3.
        $fenetre = null;
        if (!$ok && $compte !== null) {
            $f = $this->stockage->fenetreDuCompte((int) $compte['id']);
            if ($f !== null && self::fenetreOuverte($f, $maintenant) && $f['empreinte_passphrase'] !== null
                && Hashing::verify($passphrase, (string) $f['empreinte_passphrase'])) {
                $fenetre = $f;
            }
        }

        // L'ancienne passphrase ne revient pas, ni ses mots dans un autre ordre :
        // pour qui a le papier, c'est la même. Jugé seulement quand elle est bonne,
        // donc bornée par celle qui est rangée, quoi que pèse la saisie ; le refus
        // ne touche ni au compteur ni à la passphrase.
        if ($ok && $apport !== null) {
            $neuve    = array_unique(explode(' ', $apport));
            $ancienne = array_unique(explode(' ', strtolower($passphrase)));
            sort($neuve);
            sort($ancienne);
            if ($neuve === $ancienne) {
                return ['ok' => false, 'error' => 'passphrase_deja_servie',
                        'message' => Messages::dire($this->langue, 'passphrase.identique')];
            }
        }

        // L'étiquette du niveau 1 est celle où `freinerNiveau2()` cherche la
        // dernière réussite. Les deux portent l'origine, qui seule freine ici.
        $etiquette = !$ok && $fenetre === null && self::motsTousConnus($passphrase)
            ? $this->etiquetteSuspicionL1($nomCompte)
            : $this->etiquetteEchecsL1($nomCompte);
        $this->stockage->tracerTentative($etiquette, $ok || $fenetre !== null, $ip, $maintenant);

        if ($fenetre !== null) {
            return $this->reprendreParTitulaire((int) $compte['id'], $nomCompte, $fenetre, $maintenant, $apport, $refus,
                Reprise::SERRURE_PASSPHRASE, $passphrase);
        }
        if (!$ok) {
            $this->attendreEcheance($debut);

            return $refus;
        }

        // 🔑 La passphrase est consommée par son usage : on en émet une neuve.
        // La laisser valable ferait d'un papier volé une porte permanente, et
        // l'utilisateur croirait son accès rendu alors qu'il resterait partagé.
        $motDePasse     = Device::engendrerMotDePasse();
        $nouvellePhrase = $apport ?? self::engendrerPassphrase($this->langue);

        $this->stockage->commencerTransaction();
        try {
            $this->stockage->remplacerEmpreintes(
                (int) $compte['id'],
                Hashing::hash($motDePasse),
                Hashing::hash($nouvellePhrase),
                (string) $compte['empreinte_passphrase'],
            );
            $this->stockage->revoquerSessions((int) $compte['id']);
            $suite = $this->suivre(new Reprise(
                Reprise::NIVEAU_1, (int) $compte['id'], $nomCompte, $maintenant,
                Reprise::SERRURE_PASSPHRASE, $passphrase, $motDePasse, $nouvellePhrase,
            ));
            $this->stockage->validerTransaction();
        } catch (PassphraseDejaConsommee) {
            // 🔑 Une seconde requête portant la même passphrase est passée pendant
            // notre Argon2id, et elle l'a consommée la première : le perdant reçoit
            // le refus ordinaire, sans qu'aucun secret neuf ne lui soit montré.
            $this->stockage->annulerTransaction();
            $this->attendreEcheance($debut);

            return $refus;
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
            'message'      => Messages::dire($this->langue, 'acces.rendu'),
            'mot_de_passe' => $motDePasse,
            'passphrase'   => $nouvellePhrase,
            'compte'       => $nomCompte,
            'suite'        => $suite,
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
     * `$nouvellePassphrase` : comme au niveau 1, la passphrase apportée qui
     * remplace l'ancienne. Un refus la concernant ne consomme pas le code.
     *
     * 🔑 **Le retour du titulaire a sa propre forme.** Dans la fenêtre d'après un
     * niveau 3, un secret d'avant rend le compte à son titulaire : la réponse porte
     * `titulaire_reprend`, un lot `codes` neuf — l'ancien ne vaut plus, et il n'y a
     * pas de `codes_restants` —, `mot_memorise_a_changer`, `appareils_retires` et
     * `suite`. Une enveloppe qui recopie la forme ordinaire perd le lot neuf, en
     * silence.
     *
     * @return array{ok: bool, error?: string, motif?: string, message: string, mot_de_passe?: string, passphrase?: string, compte?: string, codes_restants?: int, titulaire_reprend?: bool, mot_memorise_a_changer?: bool, codes?: list<string>, appareils_retires?: int, suite?: string}
     */
    public function parCode(
        #[\SensitiveParameter] string $code,
        #[\SensitiveParameter] string $motDerive,
        ?string $ip = null,
        ?int $maintenant = null,
        #[\SensitiveParameter] ?string $nouvellePassphrase = null,
    ): array {
        $this->profil->verifierOrigine($ip);
        $maintenant = $maintenant ?? time();
        $code       = strtolower(trim($code));
        $refus      = ['ok' => false, 'message' => Messages::dire($this->langue, 'l2.refus')];

        if (!Device::estCleDerivee($motDerive)) {
            return ['ok' => false, 'error' => 'invalid_derived_key',
                    'message' => Messages::dire($this->langue, 'mot.non_derive')];
        }
        // Avant tout frein, comme au niveau 1 : la forme ne dit rien du compte.
        $apport = null;
        if ($nouvellePassphrase !== null) {
            $jugee = self::validerPassphraseApportee($nouvellePassphrase);
            if (!$jugee['ok']) {
                return $this->direRefusPassphrase($jugee);
            }
            $apport = $jugee['canonique'];
        }
        if ($ip !== null
            && $this->stockage->compterEchecsIp($ip, $maintenant - $this->fenetreEchecs, Etiquette::PREFIXES) >= $this->maxEchecsIp) {
            return $this->refusFrein();
        }

        // Forme validée avant le moindre calcul : un code qui n'a pas la forme
        // d'un code n'a pas à coûter un HMAC, encore moins un Argon2id.
        if (!self::estFormeCode($code)) {
            usleep($this->delaiRefusUs);

            return $refus;
        }

        $trouve = $this->stockage->trouverCodeParIndex($this->indexRecherche($code));
        // Un code d'avant la reprise d'un niveau 3, encore dans sa fenêtre : il se
        // juge avec le mot mémorisé d'avant, aux mêmes deux Argon2id qu'un code actuel.
        $ancien = $trouve === null ? $this->stockage->trouverAncienCodeParIndex($this->indexRecherche($code)) : null;
        if ($ancien !== null && $ancien['ferme_le'] <= $maintenant) {
            $ancien = null;
        }
        $cible = $trouve ?? $ancien;

        // Le frein par compte avant les deux Argon2id, donc avant toute
        // consommation : un essai freiné ne coûte ni code ni place au quota de
        // l'appelant.
        //
        // 🔴 **Il ne s'applique QU'À un code retrouvé**, et une seule condition
        // en décide. Appliqué à un code introuvable, il consulterait le compteur
        // de l'étiquette fixe — la même pour tous — et refuserait alors le niveau
        // 2 du service entier dès que le plafond est atteint, par n'importe qui.
        // Sans compte, il n'y a pas de compteur à consulter, et le frein par
        // origine tient seul. Les cas ⭐ de `sanity_recovery` le font rougir.
        if ($cible !== null) {
            // Un code d'avant se compte à part : sous l'étiquette du niveau 2, le
            // demandeur la remplirait avec le lot neuf que le niveau 3 lui a remis.
            $etiquette = $trouve !== null
                ? $this->etiquetteEchecsL2((string) $cible['nom_compte'])
                : $this->etiquetteRetourL2((string) $cible['nom_compte']);
            $frein     = $this->freinerNiveau2(
                $etiquette,
                (string) $cible['nom_compte'],
                (int) $cible['compte_id'],
                $maintenant,
                $trouve !== null ? null : ($this->stockage->fenetreDuCompte((int) $cible['compte_id'])['ouverte_le'] ?? null),
            );
            if ($frein !== null) {
                return $frein;
            }
        } else {
            // Seul le frein par ORIGINE la lit ; son contrat est sur la constante.
            $etiquette = Etiquette::PREFIXE_L2_INCONNU;
        }

        // Les deux vérifications sont menées quoi qu'il arrive : s'arrêter à la
        // première échouée dirait, par le temps, laquelle a échoué.
        $codeOk   = Hashing::verify($code, $cible['empreinte_code'] ?? Hashing::dummyHash());
        $motOk    = Hashing::verify($motDerive, $cible['empreinte_mot'] ?? Hashing::dummyHash());
        $ok       = $trouve !== null && !$trouve['deja_utilise'] && $codeOk && $motOk;
        $okAncien = $ancien !== null && $codeOk && $motOk;

        // L'ancienne passphrase ne revient pas telle quelle. Jugé seulement quand
        // les deux facteurs sont bons : l'Argon2id de plus ne se paie que sur un
        // chemin déjà authentifié, et le refus ne touche ni au code ni au
        // compteur. Qui le rejoue sans fin détient déjà de quoi reprendre le compte.
        if ($ok && $apport !== null) {
            $ancienne = $this->stockage->trouverComptePourPassphrase((string) $trouve['nom_compte']);
            if ($ancienne !== null && Hashing::verify($apport, (string) $ancienne['empreinte_passphrase'])) {
                return ['ok' => false, 'error' => 'passphrase_deja_servie',
                        'message' => Messages::dire($this->langue, 'passphrase.identique')];
            }
        }

        // 🔑 Un code introuvable n'est rattaché à AUCUN compte : sa ligne porte
        // `PREFIXE_L2_INCONNU`, la même pour tous. Elle garde son adresse, et
        // c'est ainsi que le frein par origine la voit — sous une étiquette
        // plutôt que sous `null`, qu'aucun filtre de préfixe ne peut compter.
        $this->stockage->tracerTentative($etiquette, $ok || $okAncien, $ip, $maintenant);

        if ($okAncien) {
            $fenetre = $this->stockage->fenetreDuCompte((int) $ancien['compte_id']);
            if ($fenetre !== null && self::fenetreOuverte($fenetre, $maintenant)) {
                return $this->reprendreParTitulaire((int) $ancien['compte_id'], (string) $ancien['nom_compte'],
                    $fenetre, $maintenant, $apport, $refus, Reprise::SERRURE_MOT_MEMORISE, $motDerive);
            }
        }
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
            $nouvellePhrase = $apport ?? self::engendrerPassphrase($this->langue);
            $this->stockage->remplacerEmpreintes(
                (int) $trouve['compte_id'],
                Hashing::hash($motDePasse),
                Hashing::hash($nouvellePhrase),
                null,
            );
            $this->stockage->revoquerSessions((int) $trouve['compte_id']);
            $suite = $this->suivre(new Reprise(
                Reprise::NIVEAU_2, (int) $trouve['compte_id'], (string) $trouve['nom_compte'], $maintenant,
                Reprise::SERRURE_MOT_MEMORISE, $motDerive, $motDePasse, $nouvellePhrase,
            ));
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
            'message'        => Messages::dire($this->langue, 'acces.rendu'),
            'mot_de_passe'   => $motDePasse,
            'passphrase'     => $nouvellePhrase,
            'compte'         => $trouve['nom_compte'],
            'suite'          => $suite,
            'codes_restants' => $this->stockage->compterCodesRestants((int) $trouve['compte_id']),
        ];
    }

    /**
     * Une passphrase neuve, de la longueur du protocole et dans la langue du
     * déploiement, quand l'utilisateur n'en apporte pas.
     *
     * ⚠️ Définie ICI et nulle part ailleurs. Elle était engendrée à deux
     * endroits de ce fichier et à un troisième dans le niveau 3 : trois copies
     * du même choix, qui n'ont aucune raison de rester d'accord. Changer la
     * longueur se fait sur cette ligne, et se répercute partout.
     *
     * 🔑 **La langue décide de la liste tirée**, pas seulement de l'habillage des
     * phrases : `Langue::listeDiceware()` rend le code que `Wordlist` attend, et
     * les deux listes font 7776 mots, donc la force ne change pas avec la langue.
     *
     * ⚠️ **Son défaut est l'anglais, et elle reste statique à dessein** : un
     * intégrateur propose une passphrase à l'inscription, avant qu'aucune
     * `Recovery` n'existe. Appelée sans langue, elle tire donc de la liste
     * anglaise quel que soit le déploiement — les trois chemins de la
     * bibliothèque passent la leur, un appelant externe doit y penser.
     */
    public static function engendrerPassphrase(?Langue $langue = null): string
    {
        return implode(' ', Wordlist::generate(
            self::MOTS_PASSPHRASE,
            ($langue ?? Langue::EN)->listeDiceware(),
        )['words']);
    }

    /**
     * La passphrase qu'un utilisateur apporte — tirée aux dés dans la liste de
     * l'EFF ou dans la liste française — mise sous la forme qu'on range.
     *
     * Forme canonique : minuscules, mots séparés par une espace. C'est elle qu'on
     * hache, qu'on rend et qu'on fait noter : la vérification ne passe pas en
     * minuscules, donc une autre graphie ne rouvrirait rien. Le trait d'union
     * reste dans le mot (`t-shirt` est un mot de la liste anglaise).
     *
     * Exigé : `MOTS_PASSPHRASE` mots au moins, chacun dans l'une des deux listes,
     * aucun deux fois. La répétition coûte une relance de dé, rarement ; elle
     * écarte les saisies les plus pauvres. **Rien ici ne mesure le hasard** : des
     * mots choisis de tête passent, et ne valent pas des mots tirés.
     *
     * 🔑 Le refus dit des positions, jamais un mot : le message part dans des
     * journaux, et un mot cité est un morceau du secret.
     *
     * 🔑 Clé `canonique`, pas `passphrase` : ce retour n'annonce pas un secret
     * neuf à noter, il dit si celui de l'utilisateur est acceptable.
     *
     * @return array{ok: true, canonique: string}|array{ok: false, error: string, motif: string, message: string}
     */
    public static function validerPassphraseApportee(#[\SensitiveParameter] string $saisie): array
    {
        $refus = static fn (string $motif, string $cle, array $valeurs): array
            => ['ok' => false, 'error' => 'passphrase_invalide', 'motif' => $motif, 'valeurs' => $valeurs,
                'cle' => 'passphrase.' . $cle];

        if (strlen($saisie) > self::OCTETS_PASSPHRASE_MAXIMUM) {
            return $refus('trop_longue', 'trop_longue', []);
        }
        $canonique = self::normaliserPassphrase(strtolower($saisie));
        $mots      = $canonique === '' ? [] : explode(' ', $canonique);
        if (count($mots) < self::MOTS_PASSPHRASE) {
            return $refus('trop_courte', 'trop_courte', [self::MOTS_PASSPHRASE]);
        }

        $hors = self::motsHorsListe($mots);
        if ($hors !== []) {
            return $refus(
                'hors_liste',
                count($hors) === 1 ? 'hors_liste_un' : 'hors_liste_plusieurs',
                [count($hors) === 1 ? (string) $hors[0] : implode(', ', $hors)],
            );
        }

        $vus     = [];
        $repetes = [];
        foreach ($mots as $i => $mot) {
            if (isset($vus[$mot])) {
                $repetes[] = $i + 1;
            }
            $vus[$mot] = true;
        }
        if ($repetes !== []) {
            return $refus('mot_repete', 'mot_repete', [implode(', ', $repetes)]);
        }

        return ['ok' => true, 'canonique' => $canonique];
    }

    /**
     * La passphrase telle qu'on la compare : espaces de bord retirés, toute
     * suite d'espaces réduite à un seul. « alpha  beta » et « alpha beta » sont
     * la même passphrase pour qui l'a recopiée.
     *
     * SelfDataGuard scelle la serrure « passphrase » d'un coffre sur la même
     * chaîne : `tests/sanity_couplage_dataguard.php` tient les deux d'accord.
     */
    public static function normaliserPassphrase(#[\SensitiveParameter] string $passphrase): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $passphrase));
    }

    /**
     * Le refus des freins par fenêtre. Un seul texte pour tous : le frein par
     * compte du niveau 2 ne doit pas se distinguer du frein par origine. Le délai
     * annoncé est celui de la fenêtre réglée, pas un nombre recopié.
     *
     * @return array{ok: false, message: string}
     */
    /**
     * Le titulaire revient dans la fenêtre d'après un niveau 3 avec un secret
     * d'avant, et l'emporte. Il reçoit un mot de passe, une passphrase et des
     * codes neufs, et retrouve son mot mémorisé d'avant avec son sel : le
     * serveur ne sait pas en engendrer un, et celui du demandeur doit sortir.
     * Les sessions et les appareils du demandeur tombent. La fenêtre passe
     * reprise, et le litige reste ouvert jusqu'à ce qu'un arbitre le close
     * (`Escalade::cloreFenetre()`) ; jusque-là, le coffre reste en lecture seule.
     *
     * @param array{empreinte_mot: ?string, sel: ?string} $fenetre
     * @return array<string, mixed>
     */
    private function reprendreParTitulaire(
        int $compteId,
        string $nomCompte,
        array $fenetre,
        int $maintenant,
        #[\SensitiveParameter] ?string $apport,
        array $refus,
        string $serrureServie,
        #[\SensitiveParameter] string $secretServi,
    ): array {
        $motDePasse      = Device::engendrerMotDePasse();
        $nouvellePhrase  = $apport ?? self::engendrerPassphrase($this->langue);
        $empreinteMdp    = Hashing::hash($motDePasse);
        $empreintePhrase = Hashing::hash($nouvellePhrase);

        $this->stockage->commencerTransaction();
        try {
            // 🔑 Consommer d'abord : deux retours simultanés du titulaire n'en font
            // qu'un, et le perdant sort avant d'avoir rien écrit.
            if (!$this->stockage->reprendreFenetre($compteId, $maintenant)) {
                $this->stockage->annulerTransaction();
                usleep($this->delaiRefusUs);

                return $refus;
            }
            $this->stockage->reposerSecrets($compteId, $empreinteMdp, $empreintePhrase,
                (string) $fenetre['empreinte_mot'], (string) $fenetre['sel']);
            $codes = $this->emettreCodes($compteId, self::CODES_PAR_LOT, $maintenant);
            $this->stockage->revoquerSessions($compteId);
            $appareils = $this->stockage->revoquerAppareils($compteId);
            $ticket = bin2hex(random_bytes(16));
            $this->stockage->emettreTicket($ticket, $compteId, Reprise::RETOUR_TITULAIRE, $maintenant);
            $suite = $this->suivre(new Reprise(
                Reprise::RETOUR_TITULAIRE, $compteId, $nomCompte, $maintenant,
                $serrureServie, $secretServi, $motDePasse, $nouvellePhrase,
                ticket: $ticket, ticketNiveau3: $fenetre['ticket_niveau3'] ?? null,
            ));
            $this->stockage->validerTransaction();
        } catch (\Throwable $e) {
            $this->stockage->annulerTransaction();

            throw $e;
        }

        return [
            'ok'                     => true,
            'titulaire_reprend'      => true,
            'mot_memorise_a_changer' => true,
            'message'                => Messages::dire($this->langue, 'l3.titulaire_reprend'),
            'mot_de_passe'           => $motDePasse,
            'passphrase'             => $nouvellePhrase,
            'codes'                  => $codes,
            'compte'                 => $nomCompte,
            'appareils_retires'      => $appareils,
            'suite'                  => $suite,
        ];
    }

    /** @param array{ferme_le: int, reprise_le: ?int, close_le: ?int} $f */
    private static function fenetreOuverte(array $f, int $maintenant): bool
    {
        return $f['reprise_le'] === null && $f['close_le'] === null && $f['ferme_le'] > $maintenant;
    }

    /** Attend que `delaiRefusUs` se soit écoulé depuis `$debut` (`hrtime`). */
    private function attendreEcheance(int $debut): void
    {
        $reste = $this->delaiRefusUs - intdiv(hrtime(true) - $debut, 1000);
        if ($reste > 0) {
            usleep($reste);
        }
    }

    private function refusFrein(): array
    {
        return ['ok' => false, 'message' => Messages::dire(
            $this->langue,
            'frein.attendre',
            [Duree::enClair($this->fenetreEchecs, $this->langue)],
        )];
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
     * ⚠️ La route publie ainsi l'empreinte `sel-absent:<chaîne>` de toute chaîne,
     * sous le sel du déploiement. Un autre faux sel fabriqué sous la même clé
     * prend un autre préfixe, sinon un code choisi le rejoue.
     *
     * @throws LogicException si le stockage n'implémente pas SelParCodeInterface
     */
    public function selDeDerivation(#[\SensitiveParameter] string $code): string
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
        $index = $this->indexRecherche($code);
        $vrai  = $this->stockage->selDuCompteParIndexCode($index);
        if ($vrai === null || $vrai === '') {
            // Un code d'avant un niveau 3 se dérive sous le sel que la fenêtre garde.
            // Cherché ici plutôt qu'exigé de l'adaptateur : celui qui ne voit que le
            // lot en place rendait un leurre au titulaire qui revient, sans erreur.
            $ancien = $this->stockage->trouverAncienCodeParIndex($index);
            $vrai   = $ancien === null ? null : ($this->stockage->fenetreDuCompte((int) $ancien['compte_id'])['sel'] ?? null);
        }

        return $vrai !== null && $vrai !== '' ? $vrai : $faux;
    }

    /**
     * Ce code est-il un code d'avant un niveau 3, dans une fenêtre encore ouverte ?
     *
     * 🔑 **Pour le plafond de l'intégrateur, à appeler avant lui.** Un plafond
     * de service compte avant de savoir quel compte est visé, et n'importe qui le
     * remplit avec des codes au hasard. Le demandeur d'un niveau 3 frauduleux y
     * gagne : tant que le plafond refuse, le titulaire ne revient pas, et la
     * fenêtre échoit à son profit. Un code pour lequel ceci rend vrai est un
     * retour, qu'un plafond global n'a pas à refuser ; le frein par compte de
     * `parCode()` le juge ensuite, sous `etiquetteRetourL2()`.
     *
     * Sans oracle : la réponse vraie suppose de détenir un code d'avant, et rien
     * ne dit à qui n'en a pas qu'un compte sort d'un niveau 3. Un HMAC et deux
     * lectures indexées, sans Argon2id : de quoi l'appeler sur chaque requête,
     * avant le plafond.
     *
     * Au niveau 1, le nom arrive avec la requête : `Escalade::fenetre()` répond
     * pour lui, au prix dit par le modèle de menace.
     */
    public function estCodeDeRetour(#[\SensitiveParameter] string $code, ?int $maintenant = null): bool
    {
        $code = strtolower(trim($code));
        if (!self::estFormeCode($code)) {
            return false;
        }
        $ancien = $this->stockage->trouverAncienCodeParIndex($this->indexRecherche($code));
        if ($ancien === null) {
            return false;
        }
        $fenetre = $this->stockage->fenetreDuCompte((int) $ancien['compte_id']);

        return $fenetre !== null && self::fenetreOuverte($fenetre, $maintenant ?? time());
    }

    /**
     * Le code a-t-il la forme de ceux qu'`emettreCodes()` fabrique ?
     *
     * Les intégrateurs qui filtrent avant d'appeler `parCode()` l'appellent au
     * lieu de recopier l'expression : un format changé ici leur parvient.
     */
    public static function estFormeCode(#[\SensitiveParameter] string $code): bool
    {
        return preg_match('/^[a-f0-9]{5}-[a-f0-9]{5}$/', $code) === 1;
    }

    /**
     * Émet un lot de codes neufs et les rend en clair, cette fois seulement.
     *
     * Quarante bits par code. C'est peu contre une attaque hors ligne, mais un
     * code ne vit jamais seul : le mot mémorisé est exigé avec lui, et le
     * rate-limit s'applique. Sa fonction est d'être imprimable, pas d'être un
     * secret maximal.
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
     * La langue de ce déploiement.
     *
     * 🔑 Même raison que `profil()` : `Escalade` compose une `Recovery` et lit
     * la langue ici plutôt que de recevoir la sienne. Une `Escalade` anglaise
     * devant une `Recovery` française rendrait au même titulaire, dans la même
     * procédure, deux moitiés de phrase dans deux langues.
     */
    public function langue(): Langue
    {
        return $this->langue;
    }

    /**
     * Le refus du validateur de passphrase, habillé du texte de cette langue.
     *
     * 🔑 **`validerPassphraseApportee()` est statique et reste sans langue.**
     * Ses appelants, les bancs compris, ne lisent que son `motif` : lui imposer
     * la langue les casserait tous pour un texte qu'ils n'affichent pas. Elle
     * rend donc un identifiant et ses valeurs, et le texte se compose ici, là où
     * la langue existe. `Escalade` passe par cette méthode pour la même raison
     * qu'elle lit `langue()`.
     */
    public function direRefusPassphrase(array $jugee): array
    {
        return [
            'ok'      => false,
            'error'   => $jugee['error'],
            'motif'   => $jugee['motif'],
            'message' => Messages::dire($this->langue, $jugee['cle'], $jugee['valeurs']),
        ];
    }

    /**
     * Index de recherche d'un code — un HMAC, pas un chiffrement.
     *
     * Il permet de retrouver la ligne sans stocker le code, et sans que la base
     * exfiltrée ne rende les codes : reconstituer l'index suppose le sel.
     */
    public function indexRecherche(#[\SensitiveParameter] string $code): string
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
        return Etiquette::sous(Etiquette::PREFIXE_L2, $nomCompte, $this->selDeploiement);
    }

    /**
     * L'étiquette du compteur d'échecs des codes d'avant un niveau 3, pendant la
     * fenêtre. `Etiquette::PREFIXE_L2_RETOUR` dit pourquoi elle n'est pas celle
     * du niveau 2.
     *
     * 🔑 **Publique**, pour la raison dite par `etiquetteEchecsL2()` : un frein
     * posé devant le niveau 2 lit celle-ci pour un code que `estCodeDeRetour()`
     * reconnaît. Sous l'étiquette du niveau 2, il refermerait le retour que ce
     * compteur à part tient ouvert.
     */
    public function etiquetteRetourL2(string $nomCompte): string
    {
        return Etiquette::sous(Etiquette::PREFIXE_L2_RETOUR, $nomCompte, $this->selDeploiement);
    }

    /**
     * L'étiquette des essais du niveau 1 que le classement n'a pas retenus, **et
     * des récupérations réussies** — `freinerNiveau2()` y cherche la dernière
     * pour réarmer la suspension du niveau 2. Ranger les succès ailleurs
     * désarmerait ce réarmement sans faire rougir un banc.
     *
     * 🔑 **Publique exprès**, pour la raison dite par `etiquetteEchecsL2()`, et
     * pour une seconde : une console qui compte les échecs de connexion doit
     * pouvoir écarter ces lignes, qui partagent sa table.
     */
    public function etiquetteEchecsL1(string $nomCompte): string
    {
        return Etiquette::sous(Etiquette::PREFIXE_L1, $nomCompte, $this->selDeploiement);
    }

    /**
     * L'étiquette des essais du niveau 1 dont tous les mots existaient et qui
     * n'ont rien ouvert.
     *
     * 🔑 **Publique exprès** (voir `etiquetteEchecsL2()`) : c'est le compteur
     * qu'un déploiement interroge pour décider qui réveiller, et celui qu'une
     * console doit écarter de ses échecs de connexion. Ce qu'il en fait ne
     * regarde pas le protocole — la bibliothèque n'ouvre ni ne ferme rien
     * d'après lui.
     */
    public function etiquetteSuspicionL1(string $nomCompte): string
    {
        return Etiquette::sous(Etiquette::PREFIXE_SUSPICION, $nomCompte, $this->selDeploiement);
    }

    /**
     * Combien d'essais du niveau 1, dans la fenêtre, avaient tous leurs mots dans
     * les listes sans rien ouvrir. `ESSAIS_PLAUSIBLES_SIGNALES` dit à partir de
     * combien il y a de quoi réveiller quelqu'un.
     *
     * 🔑 **Le déploiement vient le chercher ; il ne lui est pas rendu.** Ce
     * compte ne voyage pas dans la réponse d'une récupération, et c'est une
     * décision : la réponse part sur le réseau, souvent telle quelle. Qui connaît
     * le nom public d'un compte y lirait qu'un tiers cherche en ce moment l'ordre
     * de mots qu'il possède — donc que ce compte est en train d'être perdu. Les
     * listes sont publiques, le nombre d'essais des autres ne l'est pas.
     *
     * ⚠️ **Remplissable par qui connaît le nom**, comme tout compteur de ce
     * niveau : trois requêtes sous un nom public suffisent à le charger. Il
     * informe, et rien de plus ne doit en dépendre — pas un gel, pas un refus,
     * pas une notification qu'un tiers pourrait déclencher en série. Le niveau 2
     * peut s'en permettre un, lui : charger le sien exige un de ses codes.
     */
    public function essaisPlausiblesL1(string $nomCompte, ?int $maintenant = null): int
    {
        $maintenant = $maintenant ?? time();

        return $this->stockage->compterEchecsCompte(
            $this->etiquetteSuspicionL1($nomCompte),
            $maintenant - $this->fenetreEchecs,
        );
    }

    /**
     * Tous les mots de la saisie existent-ils dans l'une des deux listes ?
     *
     * 🔑 **Il classe, il ne refuse rien.** Des mots qui existent tous devant une
     * porte qui ne s'ouvre pas, c'est déductible sans comparer au secret : la
     * liste est publique, donc le calcul est à la portée de la bibliothèque
     * comme de qui attaque. Celui qui se trompe sur l'ordre de mots qu'il
     * possède n'essaie pas au hasard ; celui qui tape n'importe quoi, si.
     *
     * ⚠️ **Et c'est pour cela qu'il ne peut pas refuser.** Rien ne garantit la
     * forme de ce qui est rangé : l'empreinte vient de `remplacerEmpreintes()`,
     * qu'un intégrateur appelle avec la passphrase de son choix, et
     * `MOTS_PASSPHRASE` dit qu'une passphrase plus courte reste valide. Un
     * contrôle de forme qui refuserait enfermerait dehors, en silence, les
     * titulaires dont la passphrase ne ressemble pas à celles que cette
     * bibliothèque engendre.
     *
     * ⚠️ **Délibérément plus permissif que la comparaison** : la casse est
     * abaissée ici alors que la vérification ne l'abaisse pas, pour qu'un
     * changement de normalisation ne puisse pas rendre ce classement faux.
     */
    private static function motsTousConnus(#[\SensitiveParameter] string $saisie): bool
    {
        $mots = $saisie === '' ? [] : explode(' ', self::normaliserPassphrase($saisie));

        return count($mots) >= self::MOTS_MINIMUM_CLASSEMENT && self::motsHorsListe($mots) === [];
    }

    /**
     * Les positions, à partir de 1, des mots qui ne sont dans aucune des deux
     * listes.
     *
     * Sert la validation d'une passphrase apportée et le classement d'un essai
     * qui n'ouvre pas.
     *
     * ⚠️ La casse est abaissée ici, et `validerPassphraseApportee()` comptait
     * sur son propre `strtolower` en amont : les listes sont en minuscules, et
     * aucun des deux appelants ne gagne à refuser un mot pour sa majuscule.
     *
     * @param  list<string> $mots
     * @return list<int>
     */
    private static function motsHorsListe(array $mots): array
    {
        $hors = [];
        foreach ($mots as $i => $mot) {
            if (!Wordlist::inAnyList(strtolower($mot))) {
                $hors[] = $i + 1;
            }
        }

        return $hors;
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
     * le nombre de requêtes servies en parallèle, moins une. Le signalement du
     * niveau 1 a la même borne, sans conséquence : il ne décide d'aucun accès.
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
        ?int $fenetreOuverteLe = null,
    ): ?array {
        // 🔑 **Un compteur ne se réarme que par les gestes de qui tient les secrets
        // qu'il juge.** Pendant la fenêtre d'après un niveau 3, le dernier lot émis
        // et la dernière réussite du niveau 1 sont ceux du demandeur : s'ils
        // réarmaient le compteur des codes d'avant, qui tient aussi la feuille
        // d'avant essaierait le mot sans fin. Celui-là ne se réarme qu'à
        // l'ouverture de la fenêtre ; le retour, qui réussirait sous lui, la ferme.
        $rearmements = $fenetreOuverteLe !== null ? [$fenetreOuverteLe] : array_filter([
            $this->stockage->dateDernierCodeEmis($compteId),
            $this->stockage->dateDerniereReussite($etiquette),
            $this->stockage->dateDerniereReussite($this->etiquetteEchecsL1($nomCompte)),
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
                        'message' => Messages::dire($this->langue, 'l2.suspendu')];
            }
        }

        // Le quart d'heure glissant, borné par le dernier réarmement : le lot neuf du
        // titulaire revenu n'hérite pas des échecs que le demandeur vient de faire
        // sous le même compteur. Borné, pas remplacé : sous le compteur des codes
        // d'avant, que seule l'ouverture de la fenêtre réarme, compter depuis elle
        // ferait d'un frein d'un quart d'heure un refus jusqu'à l'échéance.
        $depuisFrein = $maintenant - $this->fenetreEchecs;
        if ($rearmements !== []) {
            $depuisFrein = max($depuisFrein, max($rearmements) - 1);
        }

        // 🔑 Le message est celui du frein par origine, au mot près, et le délai
        // est payé : un refus qui nomme le compte dirait à qui détient un code que
        // ce code appartient à un compte réel — et le dirait sans payer les deux
        // Argon2id, donc en offrant un oracle gratuit sur le premier facteur.
        if ($this->stockage->compterEchecsCompte($etiquette, $depuisFrein) >= $this->maxEchecsCompte) {
            usleep($this->delaiRefusUs);

            return $this->refusFrein();
        }

        return null;
    }

    /**
     * Frein du niveau 1 : par origine seule.
     *
     * 🔑 **Le frein ne refuse jamais le bon secret, et rien ne se compte par
     * compte ici.** Un compteur par compte visé ferme la seule voie qu'un
     * titulaire puisse emprunter seul — le niveau 2 réclame la feuille de codes,
     * le niveau 3 un humain —, et n'importe qui le remplit sous le nom d'un
     * autre : la table est partagée avec la porte de l'intégrateur. Le compteur
     * du classement, lui, ne décide d'aucun accès.
     *
     * ⚠️ **L'origine est donc le seul frein qui ferme ici, et le profil
     * `tor-onion` n'en a pas.** Derrière un service caché, rien ne borne le
     * nombre d'essais, et chacun paie un Argon2id : seul le plafond d'octets de
     * `parPassphrase()` refuse sans comparer. Lisser le débit de la route revient
     * au déploiement — la bibliothèque est appelée quand le coût est déjà engagé,
     * et une requête ne voit pas ce que font les autres. Le modèle de menace le
     * dit sans le promettre.
     *
     * @return array{ok: bool, message: string}|null
     */
    private function freiner(?string $ip, int $maintenant): ?array
    {
        $depuis = $maintenant - $this->fenetreEchecs;

        if ($ip !== null && $this->stockage->compterEchecsIp($ip, $depuis, Etiquette::PREFIXES) >= $this->maxEchecsIp) {
            return $this->refusFrein();
        }

        return null;
    }

    /**
     * Le sel du déploiement hors de `var_dump()` et `print_r()` : une page de
     * débogage ou une ligne de journal sont les premiers endroits où il
     * atterrirait, et c'est la clé de l'index de tous les codes émis.
     */
    public function __debugInfo(): array
    {
        return ['profil' => $this->profil, 'langue' => $this->langue, 'selDeploiement' => '[masqué]'];
    }
}
