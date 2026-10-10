<?php

declare(strict_types=1);

namespace Pierroons\SelfRecover\Recovery;

use Pierroons\SelfRecover\Crypto\Hashing;
use Pierroons\SelfRecover\Device\Device;
use Pierroons\SelfRecover\Duree;
use Pierroons\SelfRecover\Etiquette;
use Pierroons\SelfRecover\Messages;
use Pierroons\SelfRecover\Langue;
use Pierroons\SelfRecover\Storage\StorageInterface;

/**
 * Niveau 3 — la récupération quand il ne reste plus aucun secret.
 *
 * Les deux premiers niveaux demandent quelque chose : la passphrase, ou un code
 * plus le mot mémorisé. Ici la personne n'a plus rien, par définition. On ne
 * peut donc pas l'authentifier : on rassemble des faits, et un humain tranche.
 *
 * 🔑 **Un refus ne touche jamais au compte.** Un refus dit « ce demandeur ne m'a
 * pas convaincu », pas « ce compte est illégitime ». Si le demandeur était un
 * imposteur, supprimer détruirait le compte de sa victime ; s'il était le
 * titulaire mal jugé, ça punirait un innocent. Et un attaquant incapable de
 * voler un compte pourrait le faire effacer en accumulant des refus — l'échec
 * deviendrait une arme.
 *
 * Ce qui peut se durcir est la PROCÉDURE, et c'est un arbitre qui le décide :
 * `geler()` ferme l'ouverture de nouveaux dossiers, `degeler()` la rouvre. Le
 * compte reste entier, connectable, non banni.
 *
 * ⚠️ **« Le compte n'est pas touché » ne veut pas dire « le titulaire ne perd
 * rien ».** La phrase est vraie : les secrets sont intacts, la connexion
 * ordinaire fonctionne. Mais qui arrive ici n'a plus ni mot de passe, ni
 * passphrase, ni feuille de codes — fermer sa procédure, c'est fermer sa
 * dernière porte.
 *
 * 🔑 **Rien ici ne rend un secret.** Accepter ouvre la porte ; c'est le
 * titulaire qui repose ses secrets par `reEnroler()`. Câbler une
 * réinitialisation au geste d'acceptation reconstruirait exactement le chemin
 * automatique que ce niveau existe pour éviter.
 *
 * ⚠️ Cette classe ne connaît ni HTTP, ni sessions, ni la qualité d'arbitre de
 * qui l'appelle. Vérifier qu'un appelant a le droit de trancher appartient à
 * l'application : c'est elle qui a des sessions et des rôles.
 */
final class Escalade
{
    public function __construct(
        private readonly StorageInterface $stockage,
        /**
         * Émission des codes de récupération après un ré-enrôlement.
         *
         * Composé plutôt que réimplémenté : « combien de codes, sous quel index
         * de recherche » est déjà défini une fois, et le redéfinir ici en ferait
         * une seconde définition qui diverge le jour où le critère change.
         */
        private readonly Recovery $recovery,
        /** Durée de vie d'un dossier. Au-delà, il faut en ouvrir un neuf. */
        private readonly int $ttl = 86400,
        /**
         * Délai laissé au titulaire pour revenir APRÈS un accord, sésame en main.
         *
         * 🔑 Passé ce délai, la place se libère. Sans lui, un accord non repris
         * fermait le niveau 3 pour toujours : `ouvrir()` refuse tant qu'un
         * litige est actif, un accepté ne périmait jamais, et la seule clôture
         * exige le sésame — précisément ce que la personne n'a plus quand elle
         * revient. La sortie passait par un `UPDATE` en base.
         *
         * Ce que l'expiration coûte : l'arbitrage est à refaire. Ce qu'elle ne
         * coûte pas : l'accès, puisqu'un nouveau litige redevient ouvrable.
         */
        private readonly int $ttlAccepte = 604800,
        /** Attente imposée entre deux dépôts de réponses, contre le tâtonnement. */
        private readonly int $attenteDepot = 3600,
        /** Refus dans la fenêtre à partir desquels l'ouverture gèle. */
        private readonly int $gelSeuil = 3,
        /** La fenêtre glissante sur laquelle on compte les refus. */
        private readonly int $gelFenetre = 2592000,
        /** Durée du gel de procédure. Le compte, lui, n'est pas touché. */
        private readonly int $gelDuree = 604800,
        /** Délai appliqué aux refus, pour aplatir ce que le message tait. */
        private readonly int $delaiRefusUs = 300000,
        /** Fenêtre glissante sur laquelle les freins d'ouverture comptent. */
        private readonly int $fenetreOuvertures = 3600,
        /** Ouvertures depuis une même adresse, quand l'appelant en fournit une. */
        private readonly int $maxOuverturesIp = 10,
        /**
         * Ouvertures sur tout le service.
         *
         * 🔑 C'est ce plafond qui tient quand l'adresse ne dit rien — derrière un
         * service caché, où l'appelant passe `null` faute d'information à
         * compter. Il est grossier par nature : il ralentit tout le monde
         * ensemble, et c'est le prix d'un frein qui tienne sans adresses.
         *
         * ⚠️ **Il n'est lu que là où ce prix achète quelque chose**, c'est-à-dire
         * quand `ProfilDeploiement::adresseDiscriminante()` rend `false`. Sous
         * `clearweb`, où le profil EXIGE une adresse, le frein par adresse mord
         * toujours : ce plafond n'ajoutait aucune protection, seulement un
         * interrupteur général que des requêtes anonymes suffisaient à tirer pour
         * tous les comptes à la fois.
         */
        private readonly int $maxOuverturesService = 20,
        /**
         * Messages qu'un dossier accepte **du demandeur**. L'arbitre n'est pas
         * borné : son canal est ce dont il a besoin quand le fil se remplit.
         *
         * 🔑 La clé est le DOSSIER, jamais le nom du compte. Un plafond par
         * compte serait une porte qu'un tiers ferme en ouvrant un dossier chez
         * autrui ; un plafond par dossier ne coûte qu'à qui détient le sésame de
         * ce dossier-là.
         */
        private readonly int $maxMessagesLitige = 100,
        /** Attente imposée entre deux messages d'un même dossier. */
        private readonly int $attenteMessage = 10,
    ) {
    }

    /**
     * La langue de ce déploiement, celle de la `Recovery` composée.
     *
     * 🔑 Même raison que le profil, lu au même endroit : deux copies du même
     * choix n'ont aucune raison de rester d'accord.
     */
    private function langue(): Langue
    {
        return $this->recovery->langue();
    }

    /**
     * L'étiquette sous laquelle le dépôt d'un faisceau est journalisé.
     *
     * 🔑 **Publique exprès**, pour la raison dite par
     * `Recovery::etiquetteEchecsL2()` : une console qui compte les échecs de
     * connexion doit écarter ces lignes, qui partagent sa table et n'ont rien
     * d'une tentative d'authentification.
     */
    public function etiquetteDepot(string $nomCompte): string
    {
        return Etiquette::PREFIXE_L3_DEPOT . $this->recovery->indexRecherche($nomCompte);
    }

    /**
     * L'étiquette d'un compteur d'ouverture — un HMAC sous le sel du déploiement.
     *
     * ⚠️ **Le sel n'est pas là pour cacher, il est là pour EMPÊCHER D'ÉCRIRE.**
     * Le raisonnement en entier est dans `Etiquette`, qui porte le calcul des trois
     * chemins. Celui-ci y arrive par `Recovery::indexRecherche()` plutôt qu'en
     * appelant `Etiquette::sous()` : passer le sel demanderait de l'exposer par un
     * accesseur, c'est-à-dire de livrer un secret de service pour éviter une
     * indirection. Ce qui suit ne garde que ce qui est propre au niveau 3.
     * `compterEchecsCompte()` compte des lignes par étiquette, dans une table où
     * les tentatives de connexion atterrissent aussi — et un nom de compte
     * soumis y arrive tel quel, sans contrôle de forme, depuis une route
     * publique. Une étiquette devinable serait donc un compteur que n'importe
     * qui remplit : vingt requêtes sur la page de connexion, sous le nom
     * `l3:ouvrir:*`, et plus personne n'ouvre de dossier. Mesuré avant d'être
     * corrigé, pas supposé.
     *
     * Sous HMAC, l'étiquette suppose le sel du déploiement, qui vit hors du
     * webroot. Le préfixe reste en clair pour que les consoles sachent quoi ne
     * pas afficher ; il ne suffit à personne pour viser un compteur.
     *
     * 🔑 Même raisonnement que `Recovery::indexRecherche()`, et même primitive :
     * retrouver une ligne sans que la connaître permette de la fabriquer.
     *
     * 🔑 **Et ces lignes ne portent AUCUNE adresse.** `compterEchecsIp()` pèse
     * ensemble toutes les portes de cette bibliothèque : une ligne d'ouverture
     * qui portait l'adresse consommerait le quota du niveau 1, du niveau 2 et de
     * l'enrôlement d'appareil — ouvrir des dossiers chez autrui lui fermerait
     * ses propres portes, et l'échec redeviendrait l'arme que cette classe
     * existe pour désamorcer. Aucun nom de compte n'y entre non plus :
     * l'ouverture est comptée, pas attribuée. Le dépôt, lui, est nominatif —
     * c'est un fait, pas une sonde.
     */
    private function etiquette(string $quoi): string
    {
        return Etiquette::PREFIXE_L3_OUVRIR . $this->recovery->indexRecherche($quoi);
    }

    /**
     * Plancher de longueur du mot de passe que le titulaire choisit.
     *
     * ⚠️ C'est le SEUL endroit du protocole où un humain choisit son mot de
     * passe : les niveaux 1 et 2 en engendrent un. Sans cette garde, une requête
     * portant `password: ""` range l'empreinte de la chaîne vide, et le compte
     * s'ouvre ensuite avec un mot de passe vide.
     *
     * La longueur n'est pas de l'entropie — douze caractères identiques
     * franchissent la barre. C'est un plancher contre le pire, pas une mesure,
     * et c'est le même que celui de SelfDataGuard : un utilisateur des deux
     * modules ne doit pas rencontrer deux règles. `sanity_couplage_dataguard`
     * tient les deux valeurs d'accord. ⚠️ L'unité diffère : ici des caractères
     * (`mb_strlen`), là-bas des octets (`strlen`) — un mot de passe accentué
     * franchit plus tôt le plancher de SelfDataGuard.
     */
    public const MOT_DE_PASSE_MINIMUM = 12;

    /** Longueur maximale d'un message du fil d'un litige, en caractères. */
    public const MESSAGE_MAXIMUM = 2000;

    /**
     * Longueur maximale d'une réponse au questionnaire, en caractères.
     *
     * ⚠️ Les trois réponses sont **rangées en base** dans le faisceau, sous
     * `declaratif.*.declare`, et montrées telles quelles à un arbitre. Sans
     * borne, le fil avait la sienne (`MESSAGE_MAXIMUM`) et le questionnaire
     * aucune : trois champs suffisaient à ranger plusieurs mégaoctets par
     * dossier, et à rendre la console illisible.
     *
     * La valeur est large exprès — une année, un mois et un mot tiennent en
     * quelques caractères, et la marge laisse un intégrateur poser ses propres
     * libellés sans buter dessus.
     */
    public const REPONSE_MAXIMUM = 500;

    /** Au-delà, on refuse : un Argon2id sur une entrée démesurée se paie en mémoire. */
    public const MOT_DE_PASSE_MAXIMUM = 4096;

    /**
     * Les trois questions posées au demandeur.
     *
     * Elles portent sur ce que le serveur sait déjà, et sur rien d'autre : une
     * question dont la réponse n'est vérifiable nulle part ne mesure que
     * l'aplomb. Aucune ne demande de secret — c'est la définition du niveau.
     *
     * @return list<array{cle: string, texte: string}>
     */
    public static function questions(): array
    {
        return [
            ['cle' => 'annee_creation', 'texte' => 'En quelle année as-tu créé ce compte ?'],
            ['cle' => 'mois_connexion', 'texte' => 'Quel mois t\'es-tu connecté pour la dernière fois ? (AAAA-MM)'],
            ['cle' => 'frequence',      'texte' => 'À quelle fréquence utilises-tu ce compte ? (souvent / parfois / rare)'],
        ];
    }

    /**
     * Un numéro de dossier lisible, que le demandeur recopie.
     *
     * ⚠️ Aléatoire et jamais séquentiel : un compteur dirait combien de
     * personnes ont tout perdu ce mois-ci à qui saurait en ouvrir deux. Huit
     * octets, pas trois — le sésame garde l'accès, mais un numéro court reste
     * énumérable et rien n'oblige à le rendre devinable.
     */
    public static function engendrerNumero(): string
    {
        return 'LIT-' . strtoupper(bin2hex(random_bytes(8)));
    }

    /**
     * Un sésame de dossier — 256 bits d'aléa, rendu une seule fois au demandeur.
     *
     * 🔑 **Tire-le ici, ne l'invente pas.** `ouvrir()` ne reçoit que l'empreinte
     * du sésame : elle contrôle sa FORME, et aucune forme ne distingue un tirage
     * de 256 bits d'un compteur haché. Un sésame devinable rend le dossier
     * d'autrui reprenable — déposer son faisceau, écrire à l'arbitre, et, si
     * l'arbitre accorde, reposer les secrets du compte. C'est la seule pièce du
     * niveau 3 dont la bibliothèque ne peut pas vérifier la qualité, parce
     * qu'elle n'en voit jamais le préimage.
     */
    public static function engendrerSesame(): string
    {
        return bin2hex(random_bytes(32));
    }

    /** L'empreinte que le serveur range, à partir du sésame que le client garde. */
    public static function empreinteSesame(#[\SensitiveParameter] string $sesame): string
    {
        return hash('sha256', $sesame);
    }

    /**
     * Ouvre un dossier pour ce compte.
     *
     * Le client engendre le sésame et n'en envoie que l'empreinte : le serveur
     * ne détient jamais de quoi reprendre le dossier de quelqu'un.
     *
     * ⚠️ **Cette porte dit si un compte existe, et aucune formulation ne peut le
     * taire.** Le niveau 1 s'en sort par un refus unique, le niveau 2 en ne
     * demandant aucun identifiant ; ici la réponse utile EST la distinction —
     * un succès rend un numéro de dossier, un nom inconnu ne peut pas en rendre.
     * Ce qui s'oppose à l'énumération n'est donc pas le silence mais le COÛT :
     * les freins ci-dessous, et une preuve de travail que l'intégrateur pose
     * devant la route.
     *
     * 🔑 **Elle ne dit rien de plus.** Un nom inconnu, un dossier déjà ouvert, une
     * procédure gelée reçoivent le même refus, au même délai : la route est
     * publique, et nommer l'un d'eux apprendrait à n'importe qui qu'un tiers a une
     * récupération en cours, ou en a eu de refusées.
     *
     * 🔑 `$ip` vaut `null` quand l'adresse ne dit rien de l'appelant — derrière
     * un service caché, où tout arrive de la même adresse, la passer ferait d'un
     * frein par client un plafond global au seuil du client, plus bas que le
     * plafond de service et le masquant. Passer `null` laisse le plafond de
     * service gouverner seul, ce qui est le comportement voulu là-bas.
     *
     * @return array{ok: bool, message: string, numero?: string, questions?: array, expire_le?: int, error?: string}
     */
    public function ouvrir(
        string $nomCompte,
        string $empreinteSesame,
        ?string $ip = null,
        ?int $maintenant = null,
    ): array {
        $this->recovery->profil()->verifierOrigine($ip);
        $maintenant = $maintenant ?? time();

        // Casse normalisée comme au niveau 1 : sans elle, « Alice » et « alice »
        // tiennent deux compteurs distincts et chacun freine à moitié.
        $nomCompte = strtolower(trim($nomCompte));

        // ⚠️ Une chaîne vide n'est pas une adresse. Un intégrateur qui écrit
        // `$_SERVER['REMOTE_ADDR'] ?? ''` la passerait, et tous les appelants
        // partageraient alors un compteur unique au seuil du client — un plafond
        // global plus bas que celui du service, qui le masquerait.
        $ip = ($ip === null || trim($ip) === '') ? null : trim($ip);

        // ⚠️ La FORME seule. Elle ne dit rien de l'entropie du préimage, que la
        // bibliothèque ne voit jamais : `engendrerSesame()` dit pourquoi le
        // sésame se tire là et ne s'invente pas.
        if (!preg_match('/^[a-f0-9]{64}$/', $empreinteSesame)) {
            return ['ok' => false, 'error' => 'empreinte_invalide',
                    'message' => Messages::dire($this->langue(), 'l3.empreinte_invalide')];
        }

        // 🔑 Freiner AVANT de chercher le compte. Après, le frein ne mordrait
        // que sur les comptes existants, et être freiné deviendrait à son tour
        // la réponse qu'on refuse de donner.
        if ($frein = $this->freinerOuverture($ip, $maintenant)) {
            return $frein;
        }

        // Tracé avant la recherche, donc identique que le compte existe ou non.
        // Une ligne par compteur, et l'adresse dans l'étiquette, jamais dans la
        // colonne : ces lignes ne doivent alimenter aucun autre frein.
        $this->stockage->tracerTentative($this->etiquette('*'), false, null, $maintenant);
        if ($ip !== null) {
            $this->stockage->tracerTentative($this->etiquette('@' . $ip), false, null, $maintenant);
        }

        $refus = ['ok' => false, 'error' => 'ouverture_refusee',
                  'message' => Messages::dire($this->langue(), 'l3.ouverture_refusee')];

        // 🔑 Les trois refus ne font pas le même travail — un dossier déjà ouvert
        // compte le demandeur, donc écrit. Un délai ajouté après ce travail en
        // garderait l'écart ; ils tiennent une échéance commune.
        $debut  = hrtime(true);
        $compte = $this->stockage->trouverCompte($nomCompte);
        if ($compte === null) {
            $this->attendreEcheance($debut);

            return $refus;
        }
        $compteId = (int) $compte['id'];

        if ($this->stockage->gelJusqua($compteId, $maintenant) > 0) {
            $this->attendreEcheance($debut);

            return $refus;
        }

        // 🔑 Un dossier déjà ouvert ne redonne PAS son numéro. Le nom de compte
        // est semi-public : le redonner permettrait à quiconque de reprendre la
        // procédure d'un autre. L'appel concurrent est enregistré — c'est un
        // fait que l'arbitre doit voir.
        $existant = $this->stockage->litigeActifDuCompte($compteId, $maintenant);
        // Un accord que personne n'est venu reprendre a une fin : on le clôt
        // ici, à la première demande qui bute dessus, et la place se libère.
        // Le stockage ne sait pas le faire — il ignore `ttlAccepte` —, et lui
        // apprendre demanderait d'élargir le contrat que tout intégrateur
        // implémente. La date de décision qu'il rend déjà suffit.
        if ($existant !== null && $this->accordPerime($existant, $maintenant)) {
            $this->stockage->cloreLitige($existant->id, $maintenant);
            $existant = null;
        }
        if ($existant !== null) {
            $this->stockage->compterDemandeurConcurrent($existant->id);
            $this->attendreEcheance($debut);

            return $refus;
        }

        $numero   = self::engendrerNumero();
        $expireLe = $maintenant + $this->ttl;

        // 🔑 Le refus d'insérer est le MÊME fait que le dossier lu plus haut, vu
        // un instant plus tard : une autre demande a pris la place entre les deux.
        // Sans ce chemin, les deux ouvertures aboutissaient, la lecture suivante
        // ne gardait que la dernière, et le compteur de demandeurs concurrents —
        // ce que l'arbitre a de plus utile — restait à zéro.
        if (!$this->stockage->ouvrirLitige($compteId, $numero, $empreinteSesame, $maintenant, $expireLe)) {
            $concurrent = $this->stockage->litigeActifDuCompte($compteId, $maintenant);
            if ($concurrent !== null) {
                $this->stockage->compterDemandeurConcurrent($concurrent->id);
            }
            $this->attendreEcheance($debut);

            return $refus;
        }

        return ['ok' => true, 'numero' => $numero, 'questions' => self::questions(),
                'expire_le' => $expireLe,
                'message' => Messages::dire($this->langue(), 'l3.sesame_garde')];
    }

    /**
     * Dépose les réponses et assemble le faisceau pour l'arbitre.
     *
     * @param array<string, string> $reponses
     * @return array{ok: bool, message: string, statut?: string, error?: string}
     */
    public function soumettre(
        string $numero,
        #[\SensitiveParameter] string $sesame,
        array $reponses,
        ?int $maintenant = null,
    ): array {
        $maintenant = $maintenant ?? time();

        $litige = $this->recevable($numero, $sesame, $maintenant);
        if (!is_object($litige)) {
            return $litige;
        }
        if (!$litige->enCours()) {
            return ['ok' => false, 'error' => 'deja_tranche',
                    'message' => Messages::dire($this->langue(), 'l3.deja_tranche_reponses')];
        }
        if ($litige->deposeLe > 0 && $maintenant - $litige->deposeLe < $this->attenteDepot) {
            return ['ok' => false, 'error' => 'trop_tot', 'message' => Messages::dire($this->langue(), 'l3.depot_trop_tot',
                        [Duree::enClair($this->attenteDepot - ($maintenant - $litige->deposeLe), $this->langue())])];
        }

        $faits = $this->stockage->faitsDuCompte($litige->compteId);
        if ($faits === null) {
            return ['ok' => false, 'error' => 'compte_inconnu', 'message' => Messages::dire($this->langue(), 'compte.inconnu')];
        }

        // ⚠️ Une réponse en UTF-8 invalide fait rendre `false` à `json_encode`,
        // et `(string) false` vaut la chaîne VIDE : le faisceau se rangeait
        // alors sans faisceau, sans erreur, et l'arbitre tranchait sur rien.
        // Trouvé par `fuzz_escalade.php`, pas par une relecture — c'est le
        // genre de chemin qu'on ne pense pas à écrire.
        //
        // On refuse plutôt que de substituer les octets fautifs : ces réponses
        // sont montrées telles quelles à un humain qui décide, et les corriger
        // en silence lui ferait lire autre chose que ce qui a été soumis.
        //
        // 🔑 Réduit d'abord aux clés que `questions()` pose. `faisceau()` n'en
        // lit que trois de toute façon : une clé de plus n'était pas rangée,
        // mais elle était validée, et la boucle devenait donc un levier de
        // calcul qu'une requête remplit à volonté. Ce qui est écarté ici
        // n'aurait rien changé au dossier de l'arbitre.
        $attendues = array_column(self::questions(), 'cle');
        $reponses  = array_intersect_key($reponses, array_flip($attendues));

        foreach ($reponses as $cle => $valeur) {
            if (!is_string($valeur) || !mb_check_encoding($valeur, 'UTF-8')
                || !is_string($cle) || !mb_check_encoding($cle, 'UTF-8')) {
                return ['ok' => false, 'error' => 'reponses_invalides',
                        'message' => Messages::dire($this->langue(), 'l3.reponse_invalide')];
            }
            // ⚠️ Avant l'assemblage, pas après : le faisceau RANGE ces valeurs
            // sous `declaratif.*.declare`, et c'est un arbitre qui les lit.
            if (mb_strlen($valeur) > self::REPONSE_MAXIMUM) {
                return ['ok' => false, 'error' => 'reponse_trop_longue',
                        'message' => Messages::dire($this->langue(), 'l3.reponse_trop_longue', [self::REPONSE_MAXIMUM])];
            }
        }

        $faisceau = $this->faisceau($faits, $reponses, $maintenant);
        $encode   = json_encode($faisceau, JSON_UNESCAPED_UNICODE);
        if ($encode === false) {
            // Le garde-fou du garde-fou : si le contrôle ci-dessus laisse passer
            // quelque chose un jour, on refuse encore plutôt que de ranger du vide.
            return ['ok' => false, 'error' => 'faisceau_illisible',
                    'message' => Messages::dire($this->langue(), 'l3.faisceau_echec')];
        }

        $this->stockage->enregistrerFaisceau($litige->id, $encode, $maintenant);

        // 🔑 Journalisé comme un ÉCHEC. Un niveau 3 ne réussit jamais tout seul :
        // s'il comptait comme une réussite, il effacerait l'ardoise des
        // tentatives et deviendrait la voie la moins surveillée du service.
        //
        // ⚠️ Sous étiquette, comme tout ce qui entre dans cette table :
        // `purgerEchecs()` l'efface sous ce préfixe, à la rétention que le
        // déploiement règle. Le nom, lui, n'y est pas en clair — c'est ce que
        // `etiquetteDepot()` tient. Une console qui n'écarte que le préfixe
        // d'ouverture ne voit pas ces lignes : chaque dossier légitime se
        // compterait parmi ses échecs de connexion.
        $this->stockage->tracerTentative(
            $this->etiquetteDepot((string) $faits['nom_compte']), false, null, $maintenant,
        );

        return ['ok' => true, 'statut' => Litige::A_LIRE,
                'message' => Messages::dire($this->langue(), 'l3.transmis')];
    }

    /**
     * L'état d'un dossier, pour son demandeur.
     *
     * ⚠️ Le faisceau NE redescend PAS : il lui dirait quoi répondre la
     * prochaine fois.
     *
     * @return array{ok: bool, message?: string, numero?: string, statut?: string, expire_le?: int, error?: string}
     */
    public function etat(string $numero, #[\SensitiveParameter] string $sesame, ?int $maintenant = null): array
    {
        $maintenant = $maintenant ?? time();

        $litige = $this->recevable($numero, $sesame, $maintenant);
        if (!is_object($litige)) {
            return $litige;
        }

        return ['ok' => true, 'numero' => $litige->numero, 'statut' => $litige->statut,
                'expire_le' => $litige->expireLe];
    }

    /**
     * Le fil avec l'arbitre, côté demandeur. `$message` à `null` : lecture seule.
     *
     * @return array{ok: bool, statut?: string, messages?: array, error?: string, message?: string}
     */
    public function fil(
        string $numero,
        #[\SensitiveParameter] string $sesame,
        ?string $message = null,
        ?int $maintenant = null,
    ): array {
        $maintenant = $maintenant ?? time();

        $litige = $this->recevable($numero, $sesame, $maintenant);
        if (!is_object($litige)) {
            return $litige;
        }

        if ($message !== null && trim($message) !== '') {
            $refus = $this->ecrire($litige, 'demandeur', $message, $maintenant);
            if ($refus !== null) {
                return $refus;
            }
        }

        return ['ok' => true, 'statut' => $litige->statut,
                'messages' => $this->stockage->messagesDuLitige($litige->id)];
    }

    /**
     * Le fil côté arbitre. Pas de sésame — l'application a déjà établi le rôle.
     *
     * @return array{ok: bool, error?: string, message?: string}
     */
    public function repondre(string $numero, string $message, ?int $maintenant = null): array
    {
        $maintenant = $maintenant ?? time();

        $litige = $this->stockage->trouverLitigeParNumero($numero);
        if ($litige === null) {
            return ['ok' => false, 'error' => 'introuvable', 'message' => Messages::dire($this->langue(), 'l3.introuvable')];
        }
        $refus = $this->ecrire($litige, 'admin', $message, $maintenant);

        return $refus ?? ['ok' => true, 'message' => Messages::dire($this->langue(), 'l3.message_ajoute')];
    }

    /** Les dossiers pour la console d'arbitrage. */
    public function litiges(int $limite = 100): array
    {
        return $this->stockage->listerLitiges($limite);
    }

    /**
     * La décision humaine. `accepte` ou `refuse`, rien d'autre.
     *
     * 🔑 Aucune des deux branches ne touche au compte. `accepte` ouvre la porte
     * sans fabriquer de secret ; `refuse` enregistre la décision et compte les
     * refus récents.
     *
     * 🔑 **Un refus ne gèle rien de lui-même.** Le compteur rend un
     * `gel_suggere` que l'arbitre lit, et c'est `geler()` qui pose le gel.
     * ⚠️ Le recâbler sur ce compteur rouvrirait la porte qu'il ferme : les refus
     * se comptent **sous le compte visé**, pas sous le demandeur, et l'empreinte
     * du sésame est choisie par l'appelant — un tiers qui connaît un nom affiché
     * le remplit donc à volonté.
     *
     * @return array{ok: bool, message: string, statut?: string, refus_dans_la_fenetre?: int, gele?: bool, gel_suggere?: bool, error?: string}
     */
    public function trancher(
        string $numero,
        string $decision,
        string $par,
        ?int $maintenant = null,
    ): array {
        $maintenant = $maintenant ?? time();

        if ($decision !== 'accepte' && $decision !== 'refuse') {
            return ['ok' => false, 'error' => 'decision_inconnue',
                    'message' => Messages::dire($this->langue(), 'l3.decision_invalide')];
        }
        $litige = $this->stockage->trouverLitigeParNumero($numero);
        if ($litige === null) {
            return ['ok' => false, 'error' => 'introuvable', 'message' => Messages::dire($this->langue(), 'l3.introuvable')];
        }
        if (!$litige->enCours()) {
            return ['ok' => false, 'error' => 'deja_tranche', 'message' => Messages::dire($this->langue(), 'l3.deja_tranche')];
        }

        if ($decision === 'accepte') {
            $this->stockage->trancherLitige($litige->id, Litige::ACCEPTE, $par, $maintenant);

            return ['ok' => true, 'statut' => Litige::ACCEPTE,
                    'message' => Messages::dire($this->langue(), 'l3.accepte',
                        [Duree::enClair($this->ttlAccepte, $this->langue())])];
        }

        $this->stockage->trancherLitige($litige->id, Litige::REFUSE, $par, $maintenant);

        $refus = $this->stockage->compterRefusRecents($litige->compteId, $maintenant - $this->gelFenetre);

        // `gele` reste à `false` plutôt que de disparaître : un intégrateur qui
        // la lit garde une clé qui dit vrai, et le contrat ne casse pas sur un
        // correctif. Ce qui informe désormais est `gel_suggere`.
        return ['ok' => true, 'statut' => Litige::REFUSE, 'refus_dans_la_fenetre' => $refus,
                'gele' => false, 'gel_suggere' => $refus >= $this->gelSeuil,
                'message' => $refus >= $this->gelSeuil
                    ? Messages::dire($this->langue(), 'l3.refuse_acharnement',
                        [$refus, Duree::enClair($this->gelFenetre, $this->langue())])
                    : Messages::dire($this->langue(), 'l3.refuse')];
    }

    /**
     * Les règles du gel, pour qu'un écran d'arbitre les annonce sans les recopier.
     *
     * @return array{seuil: int, fenetre: int, duree: int}
     */
    public function reglesDuGel(): array
    {
        return ['seuil' => $this->gelSeuil, 'fenetre' => $this->gelFenetre, 'duree' => $this->gelDuree];
    }

    /**
     * Gèle l'ouverture de nouveaux dossiers, sur décision d'un arbitre.
     *
     * 🔑 **Le seul chemin qui pose un gel.** Le contrat de
     * `StorageInterface::poserGel()` dit pourquoi aucun compteur ne le
     * déclenche à sa place. Un arbitre se trompe aussi, mais il se trompe en
     * sachant qu'il décide, et `degeler()` le lève.
     *
     * ⚠️ Il ferme la PROCÉDURE, jamais le compte : les secrets ne sont pas
     * touchés et la connexion ordinaire continue. Ce que ça coûte à un titulaire
     * authentique mal jugé est réel — il n'a plus de dernier recours pendant
     * `$gelDuree` —, et c'est pourquoi la durée est bornée et la levée immédiate.
     *
     * Comme `degeler()`, ce chemin est réservé à un arbitre et la qualité
     * d'arbitre se vérifie à l'endpoint : la bibliothèque ne connaît pas les
     * rôles. Il distingue donc « inconnu » de « gelé » sans frein ni délai.
     *
     * @return array{ok: bool, message: string, jusqua?: int, gele_par?: string, error?: string}
     */
    public function geler(string $nomCompte, string $par, ?int $maintenant = null): array
    {
        $maintenant = $maintenant ?? time();
        $nomCompte  = strtolower(trim($nomCompte));

        $compte = $this->stockage->trouverCompte($nomCompte);
        if ($compte === null) {
            return ['ok' => false, 'error' => 'compte_inconnu', 'message' => Messages::dire($this->langue(), 'compte.inconnu')];
        }

        $jusqua = $maintenant + $this->gelDuree;
        $this->stockage->poserGel((int) $compte['id'], $jusqua, $maintenant, $par);

        return ['ok' => true, 'jusqua' => $jusqua, 'gele_par' => $par,
                'message' => Messages::dire($this->langue(), 'l3.gele',
                                            [Duree::enClair($this->gelDuree, $this->langue())])];
    }

    /**
     * Lève un gel de procédure. La trace du dégel est conservée.
     *
     * ⚠️ **L'historique des refus n'est pas remis à zéro, et c'est voulu.** Ce
     * n'est pas un verrou : c'est un fait que l'arbitre lit dans le faisceau
     * (`contexte.refus_precedents`) et dans `gel_suggere`. Rien ne repose un gel
     * sans qu'un humain le demande, donc une levée tient.
     */
    public function degeler(string $nomCompte, string $par, ?int $maintenant = null): array
    {
        $maintenant = $maintenant ?? time();
        $nomCompte  = strtolower(trim($nomCompte));

        // Ce chemin distingue « inconnu » de « levé » sans frein ni délai, et
        // c'est assumé : il est réservé à un arbitre, et la qualité d'arbitre se
        // vérifie à l'endpoint — la bibliothèque ne connaît pas les rôles.
        $compte = $this->stockage->trouverCompte($nomCompte);
        if ($compte === null) {
            return ['ok' => false, 'error' => 'compte_inconnu', 'message' => Messages::dire($this->langue(), 'compte.inconnu')];
        }
        $this->stockage->leverGel((int) $compte['id'], $par, $maintenant);

        return ['ok' => true, 'message' => Messages::dire($this->langue(), 'l3.degele')];
    }

    /**
     * Clôt le litige en cours d'un compte, sur décision d'un arbitre.
     *
     * 🔑 **C'est la sortie de qui a perdu son sésame.** Sans elle, un accord
     * rendu bloquait le niveau 3 jusqu'à son échéance — sept jours par défaut —
     * et le seul recours immédiat était un `UPDATE` en base. Le délai reste, en
     * filet ; ce chemin-ci rend la main tout de suite à qui le demande.
     *
     * ⚠️ Il ne rend AUCUN accès : il libère la place. Le titulaire rouvre un
     * litige et l'arbitrage est à refaire — un arbitre qui abandonne ne décide
     * pas à la place de celui qui tranchera.
     *
     * Comme `degeler()`, ce chemin est réservé à un arbitre et la qualité
     * d'arbitre se vérifie à l'endpoint : la bibliothèque ne connaît pas les
     * rôles. Il distingue donc « inconnu » de « clos » sans frein ni délai.
     */
    public function abandonner(string $nomCompte, string $par, ?int $maintenant = null): array
    {
        $maintenant = $maintenant ?? time();
        $nomCompte  = strtolower(trim($nomCompte));

        $compte = $this->stockage->trouverCompte($nomCompte);
        if ($compte === null) {
            return ['ok' => false, 'error' => 'compte_inconnu', 'message' => Messages::dire($this->langue(), 'compte.inconnu')];
        }

        $litige = $this->stockage->litigeActifDuCompte((int) $compte['id'], $maintenant);
        if ($litige === null) {
            return ['ok' => false, 'error' => 'aucun_litige',
                    'message' => Messages::dire($this->langue(), 'l3.aucune_procedure')];
        }

        $this->stockage->cloreLitige($litige->id, $maintenant);

        return ['ok' => true, 'numero' => $litige->numero, 'statut_precedent' => $litige->statut,
                'abandonne_par' => $par,
                'message' => Messages::dire($this->langue(), 'l3.close')];
    }

    /**
     * Le titulaire repose lui-même ses secrets, après un accord.
     *
     * 🔑 **Aucun mot de passe n'est rendu**, contrairement aux niveaux 1 et 2.
     * C'est la propriété distinctive du niveau : le serveur n'émet rien, il
     * range ce que le titulaire a choisi. Les codes, eux, sont engendrés : ils
     * ne viennent jamais du client. La passphrase est engendrée, ou apportée par
     * le titulaire (`$nouvellePassphrase`, jugée par
     * `Recovery::validerPassphraseApportee()`) ; elle est rendue sous la forme
     * rangée, celle qu'il doit noter.
     *
     * @return array{ok: bool, message: string, passphrase?: string, codes?: array, error?: string, motif?: string}
     */
    public function reEnroler(
        string $numero,
        #[\SensitiveParameter] string $sesame,
        #[\SensitiveParameter] string $motDePasse,
        #[\SensitiveParameter] string $motDerive,
        string $sel,
        ?int $maintenant = null,
        #[\SensitiveParameter] ?string $nouvellePassphrase = null,
    ): array {
        $maintenant = $maintenant ?? time();

        $litige = $this->recevable($numero, $sesame, $maintenant);
        if (!is_object($litige)) {
            return $litige;
        }
        if ($litige->statut !== Litige::ACCEPTE) {
            return ['ok' => false, 'error' => 'non_accepte', 'message' => Messages::dire($this->langue(), 'l3.non_accepte')];
        }
        $long = mb_strlen($motDePasse);
        if ($long < self::MOT_DE_PASSE_MINIMUM || $long > self::MOT_DE_PASSE_MAXIMUM) {
            return ['ok' => false, 'error' => 'mot_de_passe_invalide',
                    'message' => Messages::dire($this->langue(), 'l3.mot_de_passe_taille',
                                                [self::MOT_DE_PASSE_MINIMUM, self::MOT_DE_PASSE_MAXIMUM])];
        }
        if (!Device::estCleDerivee($motDerive)) {
            return ['ok' => false, 'error' => 'invalid_derived_key',
                    'message' => Messages::dire($this->langue(), 'mot.non_derive')];
        }
        if (!Recovery::estSelCompte($sel)) {
            return ['ok' => false, 'error' => 'sel_invalide',
                    'message' => Messages::dire($this->langue(), 'l3.sel_invalide')];
        }
        $apport = null;
        if ($nouvellePassphrase !== null) {
            $jugee = Recovery::validerPassphraseApportee($nouvellePassphrase);
            if (!$jugee['ok']) {
                return $this->recovery->direRefusPassphrase($jugee);
            }
            // Deux serrures identiques n'en font qu'une, pour SelfDataGuard comme
            // pour qui les trouverait écrites ensemble.
            if ($jugee['canonique'] === strtolower(Recovery::normaliserPassphrase($motDePasse))) {
                return ['ok' => false, 'error' => 'passphrase_egale_mot_de_passe',
                        'message' => Messages::dire($this->langue(), 'l3.passphrase_egale_mdp')];
            }
            $ancienne = $this->stockage->trouverComptePourPassphrase($litige->nomCompte);
            if ($ancienne !== null && Hashing::verify($jugee['canonique'], (string) $ancienne['empreinte_passphrase'])) {
                return ['ok' => false, 'error' => 'passphrase_deja_servie',
                        'message' => Messages::dire($this->langue(), 'passphrase.identique')];
            }
            $apport = $jugee['canonique'];
        }

        $passphrase = $apport ?? $this->recovery->engendrerPassphrase();

        // 🔑 Tout ou rien : les trois empreintes et le sel ne sont pas quatre
        // informations mais une seule, et l'émission des codes purge le lot
        // précédent avant d'écrire le neuf. Une interruption au milieu laisserait
        // un compte que rien ne récupère.
        $this->stockage->commencerTransaction();
        try {
            $this->stockage->reposerSecrets(
                $litige->compteId,
                Hashing::hash($motDePasse),
                Hashing::hash($passphrase),
                Hashing::hash($motDerive),
                $sel,
            );
            $codes = $this->recovery->emettreCodes($litige->compteId, Recovery::CODES_PAR_LOT, $maintenant);
            $this->stockage->cloreLitige($litige->id, $maintenant);
            $this->stockage->revoquerSessions($litige->compteId);
            // 🔑 Et les appareils. On arrive ici après avoir TOUT perdu, et « tout
            // perdu » veut souvent dire « quelqu'un d'autre l'a ». Un appareil
            // enrôlé ouvre le compte sur une signature seule — `cloreDefi()` ne
            // vérifie pas le mot mémorisé —, donc le laisser vivre reviendrait à
            // reposer tous les secrets en gardant la porte la plus directe ouverte,
            // pendant que le titulaire croit avoir refermé.
            $appareilsRetires = $this->stockage->revoquerAppareils($litige->compteId);
            $this->stockage->validerTransaction();
        } catch (\Throwable $e) {
            $this->stockage->annulerTransaction();

            throw $e;
        }

        $avis = $appareilsRetires > 0
            ? Messages::dire($this->langue(), 'l3.avis_appareils', [$appareilsRetires])
            : '';

        return ['ok' => true, 'passphrase' => $passphrase, 'codes' => $codes,
                'appareils_retires' => $appareilsRetires,
                'message' => Messages::dire($this->langue(), 'l3.compte_repris', [$avis])];
    }

    /**
     * Un accord rendu, jamais repris, et dont le délai est passé.
     *
     * ⚠️ Un litige sans date de décision n'est jamais périmé de ce fait : mieux
     * vaut une place occupée à tort qu'un accord annulé sur une donnée absente.
     */
    private function accordPerime(Litige $litige, int $maintenant): bool
    {
        return $litige->statut === Litige::ACCEPTE
            && $litige->trancheLe !== null
            && $litige->trancheLe + $this->ttlAccepte <= $maintenant;
    }

    /**
     * Efface les litiges périmés. Rend le nombre effacé.
     *
     * 🔴 **PERSONNE NE L'APPELLE À TA PLACE. C'est au déploiement de le faire,
     * périodiquement.** La bibliothèque n'a ni horloge ni planificateur : rien
     * dans `src/` n'appelle cette méthode. Sans appel périodique, les dossiers
     * périmés s'empilent avec leur empreinte de sésame — `expires_at` les rend
     * inactifs, il n'efface rien.
     *
     * Une unité `systemd` prête à poser vit dans `deploy/bi-self/`
     * (`selfrecover-purger.{service,timer}`), et l'outil qu'elle lance est
     * `bi-self/selfrecover/tools/purger.php` ; un cron ou un planificateur applicatif font le
     * même travail. La cadence n'a pas
     * besoin d'être fine : une fois par jour suffit, puisque ce qu'on efface est
     * déjà sans effet.
     *
     * ⚠️ **Elle n'efface pas tout** : deux statuts y survivent exprès, et
     * `StockagePdo::purgerLitigesExpires()` dit lesquels et pourquoi, à côté de
     * sa clause. Appeler cette méthode ne borne donc pas la table : la rétention
     * de ces deux statuts est une décision de déploiement.
     */
    public function purger(?int $maintenant = null): int
    {
        return $this->stockage->purgerLitigesExpires($maintenant ?? time());
    }

    // ── Interne ────────────────────────────────────────────────────────────

    /** Attend que `delaiRefusUs` se soit écoulé depuis `$debut` (`hrtime`). */
    private function attendreEcheance(int $debut): void
    {
        $reste = $this->delaiRefusUs - intdiv(hrtime(true) - $debut, 1000);
        if ($reste > 0) {
            usleep($reste);
        }
    }

    /**
     * Les freins de l'ouverture. Deux compteurs, aucun nom de compte.
     *
     * ⚠️ **Il n'y a délibérément PAS de frein par compte.** Un tel frein
     * fermerait l'ouverture à un titulaire dès qu'un tiers a assez sollicité son
     * compte, sans qu'aucun dossier n'existe — donc sans que rien n'apparaisse à
     * l'arbitre. Le harcèlement d'un compte est déjà borné autrement : le
     * premier dossier tient `$ttl`, le suivant reçoit le refus unique, et cette
     * collision-là **se compte et se montre** (`compterDemandeurConcurrent`).
     * Un frein silencieux aurait remplacé un fait visible par un mur muet.
     *
     * ⚠️ Un refus unique pour les deux, et sans délai : le message ne cache rien
     * qu'un chronomètre pourrait retrouver, contrairement aux refus qui suivent.
     * L'y ajouter tiendrait un exécutant occupé à chaque requête refusée, ce qui
     * est le levier qu'on retire à l'attaquant.
     *
     * 🔑 **Le plafond de service est conditionné au profil.** Le détail est au
     * constructeur, avec ce que son absence de condition coûtait ; ici il suffit
     * de savoir que le frein par adresse et lui ne travaillent jamais ensemble —
     * l'un sert là où l'autre n'a rien à mesurer.
     *
     * @return array{ok: bool, error: string, message: string}|null
     */
    private function freinerOuverture(?string $ip, int $maintenant): ?array
    {
        $depuis = $maintenant - $this->fenetreOuvertures;
        $refus  = ['ok' => false, 'error' => 'trop_de_demandes',
                   'message' => Messages::dire($this->langue(), 'l3.trop_de_demandes')];

        if ($ip !== null
            && $this->stockage->compterEchecsCompte($this->etiquette('@' . $ip), $depuis)
               >= $this->maxOuverturesIp) {
            return $refus;
        }
        // 🔑 Le plafond de service n'est LU que là où l'adresse ne discrimine
        // rien. Sa ligne, elle, continue de s'écrire dans les deux cas : le
        // signal reste disponible pour une console, c'est la décision qui s'en
        // retire.
        if (!$this->recovery->profil()->adresseDiscriminante()
            && $this->stockage->compterEchecsCompte($this->etiquette('*'), $depuis)
               >= $this->maxOuverturesService) {
            return $refus;
        }

        return null;
    }

    /**
     * Le dossier, si le numéro et le sésame ouvrent et qu'il n'a pas expiré.
     *
     * Rend le `Litige` ou le tableau de refus — les appelants testent
     * `is_object()`. Un refus unique pour « numéro faux » et « sésame faux » :
     * les distinguer dirait à un attaquant qu'un numéro existe.
     *
     * 🔑 **Les trois refus tiennent une échéance commune.** `expire` et
     * `accord_perime` ne sont atteintes **que si le sésame est bon** : les
     * laisser partir sans délai faisait dire au chronomètre ce que le message
     * unique tait — qu'un numéro existe et que son sésame ouvre.
     *
     * ⚠️ **L'échéance est prise AVANT la recherche**, donc le délai borne le
     * total au lieu de s'ajouter au travail. La déplacer après l'y ajoute, et le
     * temps de réponse se remet à dépendre de ce que la recherche a trouvé.
     *
     * @return Litige|array{ok: false, error: string, message: string}
     */
    private function recevable(string $numero, #[\SensitiveParameter] string $sesame, int $maintenant): Litige|array
    {
        $debut  = hrtime(true);
        $litige = $this->stockage->trouverLitigeParNumero(strtoupper(trim($numero)));

        // Les deux vérifications sont menées quoi qu'il arrive : s'arrêter à la
        // première échouée dirait par le temps ce que le message tait.
        $factice   = str_repeat('0', 64);
        $sesameOk  = hash_equals($litige?->empreinteSesame ?? $factice, self::empreinteSesame($sesame));
        $recevable = $litige !== null && $sesameOk && $sesame !== '';

        // 🔑 UNE seule porte de sortie pour les trois refus, qui prend l'échéance
        // avec elle. Écrite trois fois, elle s'oubliait deux fois : seule la
        // première attendait, et les deux autres ne sont atteintes que si le
        // sésame est bon.
        $refuser = function (string $motif, string $message) use ($debut): array {
            $this->attendreEcheance($debut);

            return ['ok' => false, 'error' => $motif, 'message' => $message];
        };

        if (!$recevable) {
            return $refuser('sesame_invalide', 'Numéro ou sésame invalide.');
        }
        // ⚠️ Un dossier ACCEPTÉ ne périme pas. Le délai borne le temps pendant
        // lequel un dossier reste ouvert sans être instruit ; l'appliquer après
        // l'accord rendrait l'arbitrage humain inutilisable — un arbitre qui
        // prend deux jours pour se décider annulerait son propre travail, et le
        // titulaire devrait tout recommencer. C'est le ré-enrôlement qui ferme
        // le dossier, pas l'horloge.
        if ($litige->statut !== Litige::ACCEPTE && $litige->expire($maintenant)) {
            return $refuser('expire', 'Ce litige a expiré. Il faut en ouvrir un nouveau.');
        }
        // L'accord, lui, tient `ttlAccepte` après la décision — pas le TTL
        // d'instruction, qui ne vaut que tant que personne n'a tranché.
        if ($this->accordPerime($litige, $maintenant)) {
            return $refuser(
                'accord_perime',
                'L\'accord rendu sur ce litige a expiré faute d\'avoir été repris. '
                . 'Ouvre un nouveau litige : l\'arbitrage sera à refaire.'
            );
        }

        return $litige;
    }

    /** @return array{ok: false, error: string, message: string}|null */
    private function ecrire(Litige $litige, string $auteur, string $message, int $maintenant): ?array
    {
        $texte = trim($message);
        if ($texte === '') {
            return ['ok' => false, 'error' => 'vide', 'message' => Messages::dire($this->langue(), 'l3.message_vide')];
        }
        if (mb_strlen($texte) > self::MESSAGE_MAXIMUM) {
            return ['ok' => false, 'error' => 'trop_long', 'message' => Messages::dire($this->langue(), 'l3.message_trop_long', [self::MESSAGE_MAXIMUM])];
        }
        // Un dossier clos ne reçoit plus rien, de personne : il est terminé.
        if ($litige->statut === Litige::CLOS) {
            return ['ok' => false, 'error' => 'clos', 'message' => Messages::dire($this->langue(), 'l3.clos')];
        }
        // ⚠️ `REFUSE` ferme le fil au DEMANDEUR, pas à l'arbitre, et les deux
        // moitiés sont nécessaires. Un dossier refusé n'est jamais clos par
        // `trancher()`, donc le détenteur du sésame continuait d'écrire à
        // l'arbitre qui venait de l'éconduire. Mais fermer les deux sens
        // laissait l'arbitre **refuser sans pouvoir expliquer** — mesuré — et
        // sur le dernier recours d'une personne qui n'a plus aucun secret, un
        // refus muet est le pire des deux défauts.
        //
        // ⚠️ Un dossier `ACCEPTE` reste ouvert aux deux : le fil est le seul
        // canal pendant les jours que dure la reprise des secrets.
        if ($litige->statut === Litige::REFUSE && $auteur === 'demandeur') {
            return ['ok' => false, 'error' => 'clos',
                    'message' => Messages::dire($this->langue(), 'l3.tranche_pas_de_message')];
        }

        // 🔑 Les deux freins ne portent que sur le DEMANDEUR. L'arbitre est
        // authentifié par l'application ; le détenteur du sésame ne l'est pas,
        // et c'est lui dont le fil borne le coût.
        //
        // ⚠️ **Ce que ces deux freins coûtent, et qui n'est pas borné** : ils
        // lisent tout le fil du dossier avant de pouvoir refuser, car
        // `messagesDuLitige()` n'a ni COUNT ni LIMIT. Donc une tentative
        // refusée pour cadence paie quand même la lecture — au plus
        // `maxMessagesLitige` messages de `MESSAGE_MAXIMUM` caractères, mais à
        // la cadence que la route accepte, et cette classe n'a pas de route.
        // Borner cette cadence appartient au lissage de débit du déploiement —
        // le modèle de menace le nomme.
        if ($auteur === 'demandeur') {
            $siens = array_values(array_filter(
                $this->stockage->messagesDuLitige($litige->id),
                static fn (array $m): bool => $m['auteur'] === 'demandeur',
            ));
            if (count($siens) >= $this->maxMessagesLitige) {
                // ⚠️ Ce plafond ne se libère PAS : il compte les messages du
                // demandeur, et une réponse d'arbitre n'en retire aucun. Ne pas
                // conseiller d'attendre — ce serait le seul conseil qui ne
                // marche pas. L'arbitre, lui, écrit encore : le dossier n'est
                // pas muet, c'est ce côté-ci qui est plein.
                return ['ok' => false, 'error' => 'fil_plein',
                        'message' => Messages::dire($this->langue(), 'l3.fil_plein')];
            }
            $dernier = $siens === [] ? null : (int) $siens[count($siens) - 1]['ecrit_le'];
            if ($dernier !== null && $maintenant - $dernier < $this->attenteMessage) {
                return ['ok' => false, 'error' => 'trop_rapide',
                        'message' => Messages::dire($this->langue(), 'l3.message_trop_tot',
                        [Duree::enClair($this->attenteMessage - ($maintenant - $dernier), $this->langue())])];
            }
        }

        $this->stockage->ajouterMessageLitige($litige->id, $auteur, $texte, $maintenant);

        return null;
    }

    /**
     * Le faisceau : des faits bruts, jamais un score.
     *
     * 🔑 Trois états, et le troisième est celui qui compte : `concorde`,
     * `diverge`, et **`indisponible`**. Sans lui, un déploiement qui n'enregistre
     * pas les connexions fait marquer « ne concorde pas » à une réponse honnête,
     * et le dossier d'une personne légitime arrive à charge devant l'arbitre.
     * C'est pourquoi `faitsDuCompte()` rend `null` et jamais zéro.
     *
     * ⚠️ Aucune addition, aucune moyenne, aucun pourcentage. Un score inviterait
     * à décider sans lire, et c'est un humain qui doit décider ici.
     *
     * @param array{id: int, nom_compte: string, cree_le: int, derniere_connexion: int|null, nombre_connexions: int|null} $faits
     * @param array<string, string> $reponses
     */
    /**
     * Ce qu'un adaptateur ajoute au faisceau, ramené à ce qui s'encode.
     *
     * ⚠️ **Un fait local illisible ne doit pas fermer la porte.** Ce niveau
     * s'adresse à quelqu'un qui n'a plus aucun secret : c'est le dernier
     * recours. Une valeur qu'`json_encode` refuse — un octet hors UTF-8 venu
     * d'une colonne héritée, un flottant infini, un objet — ferait échouer
     * l'assemblage, à chaque tentative, pour toujours, sur ce compte. Le refus
     * serait déterministe et le message dirait « réessaie ».
     *
     * Une valeur illisible devient donc `null`, ce que le faisceau sait déjà
     * dire : « le serveur ne sait pas ». Un fait manquant coûte à l'arbitre ;
     * un dossier qui ne s'assemble jamais coûte le compte.
     *
     * @param  mixed $faitsLocaux ce que l'adaptateur a rendu, sans garantie
     * @return array<string, string|int|bool|null>
     */
    private static function assainir(mixed $faitsLocaux): array
    {
        if (!is_array($faitsLocaux)) {
            return [];
        }
        $propre = [];
        foreach ($faitsLocaux as $cle => $valeur) {
            if (!is_string($cle) || !mb_check_encoding($cle, 'UTF-8')) {
                continue;
            }
            $propre[$cle] = match (true) {
                $valeur === null, is_bool($valeur)      => $valeur,
                is_int($valeur)                         => $valeur,
                is_float($valeur) && is_finite($valeur) => $valeur,
                is_string($valeur) && mb_check_encoding($valeur, 'UTF-8') => $valeur,
                default                                 => null,
            };
        }

        return $propre;
    }

    private function faisceau(array $faits, array $reponses, int $maintenant): array
    {
        $etat = static function (?string $reel, string $declare): array {
            if ($reel === null) {
                return ['etat' => 'indisponible', 'reel' => null, 'declare' => $declare];
            }

            return [
                'etat'    => strcasecmp(trim($reel), trim($declare)) === 0 ? 'concorde' : 'diverge',
                'reel'    => $reel,
                'declare' => $declare,
            ];
        };

        $annee = gmdate('Y', $faits['cree_le']);
        $mois  = $faits['derniere_connexion'] === null ? null : gmdate('Y-m', $faits['derniere_connexion']);

        // Les paliers de fréquence. Un compteur `null` ne vaut pas « rare » : il
        // vaut « on ne sait pas », et c'est ce que le faisceau doit dire.
        $freq = null;
        if ($faits['nombre_connexions'] !== null) {
            $n    = $faits['nombre_connexions'];
            $freq = $n >= 30 ? 'souvent' : ($n >= 5 ? 'parfois' : 'rare');
        }

        return [
            'contexte' => [
                'compte_cree_le'     => gmdate('Y-m-d', $faits['cree_le']),
                'derniere_connexion' => $faits['derniere_connexion'] === null
                    ? null : gmdate('Y-m-d', $faits['derniere_connexion']),
                'nombre_connexions'  => $faits['nombre_connexions'],
                'codes_l2_restants'  => $this->stockage->compterCodesRestants($faits['id']),
                'refus_precedents'   => $this->stockage->compterRefusRecents(
                    $faits['id'],
                    $maintenant - $this->gelFenetre,
                ),
                // 🔑 Les faits du déploiement sous leur propre clé, jamais mêlés
                // aux précédents : un adaptateur ne doit pas pouvoir rendre un
                // « refus_precedents » de son cru sous les yeux de l'arbitre.
                // La bibliothèque ne les interprète pas — c'est l'arbitre qui lit.
                'local'              => self::assainir($faits['faits_locaux'] ?? []),
            ],
            'declaratif' => [
                'annee_creation' => $etat($annee, (string) ($reponses['annee_creation'] ?? '')),
                'mois_connexion' => $etat($mois, (string) ($reponses['mois_connexion'] ?? '')),
                'frequence'      => $etat($freq, (string) ($reponses['frequence'] ?? '')),
            ],
            // 🔑 Dit à l'arbitre ce que ce dossier NE PEUT PAS prouver. Derrière
            // un service caché, toutes les requêtes viennent de la même adresse :
            // il n'existe aucun signal passif, et le taire laisserait croire
            // qu'un faisceau déclaratif complet vaut une preuve.
            'avertissement' => 'Les réponses ci-dessus sont déclaratives et devinables. Elles orientent la '
                             . 'conversation, elles ne prouvent rien. Un déploiement qui n\'enregistre pas les '
                             . 'connexions rend « indisponible », ce qui n\'est pas une divergence.',
        ];
    }
}
