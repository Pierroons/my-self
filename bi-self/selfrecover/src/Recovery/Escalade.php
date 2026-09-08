<?php

declare(strict_types=1);

namespace Pierroons\SelfRecover\Recovery;

use Pierroons\SelfRecover\Crypto\Hashing;
use Pierroons\SelfRecover\Device\Device;
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
 * Ce qui se durcit est la PROCÉDURE : au-delà de `$gelSeuil` refus dans la
 * fenêtre glissante, l'ouverture de nouveaux dossiers gèle. Le compte reste
 * entier, connectable, non banni. Un arbitre dégèle.
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
         */
        private readonly int $maxOuverturesService = 20,
    ) {
    }

    /**
     * Étiquette du compteur de service.
     *
     * 🔑 Les compteurs d'ouverture vivent sous des étiquettes à eux, et leurs
     * lignes ne portent AUCUNE adresse. `compterEchecsIp` compte les échecs
     * d'une adresse sans regarder d'où ils viennent : une ligne d'ouverture qui
     * porterait l'adresse consommerait le quota de la connexion ordinaire, du
     * niveau 1, du niveau 2 et de l'enrôlement d'appareil — ouvrir des dossiers
     * chez autrui lui fermerait toutes ses portes, et l'échec redeviendrait
     * l'arme que cette classe existe pour désamorcer.
     *
     * Aucun nom de compte n'entre dans ces étiquettes non plus : l'ouverture est
     * comptée, pas attribuée. Le dépôt, lui, est nominatif — c'est un fait, pas
     * une sonde.
     */
    private const PREFIXE = 'l3:ouvrir:';

    /**
     * L'étiquette d'un compteur d'ouverture — un HMAC sous le sel du déploiement.
     *
     * ⚠️ **Le sel n'est pas là pour cacher, il est là pour EMPÊCHER D'ÉCRIRE.**
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
     */
    private function etiquette(string $quoi): string
    {
        return self::PREFIXE . $this->recovery->indexRecherche($quoi);
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
     * modules ne doit pas rencontrer deux règles.
     */
    public const MOT_DE_PASSE_MINIMUM = 12;

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

    /** L'empreinte que le serveur range, à partir du sésame que le client garde. */
    public static function empreinteSesame(string $sesame): string
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
        $maintenant = $maintenant ?? time();

        // Casse normalisée comme au niveau 1 : sans elle, « Alice » et « alice »
        // tiennent deux compteurs distincts et chacun freine à moitié.
        $nomCompte = strtolower(trim($nomCompte));

        // ⚠️ Une chaîne vide n'est pas une adresse. Un intégrateur qui écrit
        // `$_SERVER['REMOTE_ADDR'] ?? ''` la passerait, et tous les appelants
        // partageraient alors un compteur unique au seuil du client — un plafond
        // global plus bas que celui du service, qui le masquerait.
        $ip = ($ip === null || trim($ip) === '') ? null : trim($ip);

        if (!preg_match('/^[a-f0-9]{64}$/', $empreinteSesame)) {
            return ['ok' => false, 'error' => 'empreinte_invalide',
                    'message' => 'L\'empreinte du sésame est absente ou malformée.'];
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

        $compte = $this->stockage->trouverCompte($nomCompte);
        if ($compte === null) {
            usleep($this->delaiRefusUs);

            return ['ok' => false, 'error' => 'compte_inconnu', 'message' => 'Aucun compte à ce nom.'];
        }
        $compteId = (int) $compte['id'];

        $gel = $this->stockage->gelJusqua($compteId, $maintenant);
        if ($gel > 0) {
            // Même délai que le compte inconnu : sans lui, l'existence se lirait
            // au chronomètre.
            usleep($this->delaiRefusUs);

            return ['ok' => false, 'error' => 'gele',
                    'message' => 'Trop de demandes refusées récemment sur ce compte. La procédure rouvrira le '
                               . gmdate('d/m/Y', $gel) . '. Le compte, lui, fonctionne normalement.'];
        }

        // 🔑 Un dossier déjà ouvert ne redonne PAS son numéro. Le nom de compte
        // est semi-public : le redonner permettrait à quiconque de reprendre la
        // procédure d'un autre. L'appel concurrent est enregistré — c'est un
        // fait que l'arbitre doit voir.
        $existant = $this->stockage->litigeActifDuCompte($compteId, $maintenant);
        if ($existant !== null) {
            $this->stockage->compterDemandeurConcurrent($existant->id);
            usleep($this->delaiRefusUs);

            return ['ok' => false, 'error' => 'deja_ouvert',
                    'message' => 'Une procédure est déjà en cours sur ce compte. '
                               . 'Si c\'est la tienne, reprends-la avec son numéro et ton sésame.'];
        }

        $numero   = self::engendrerNumero();
        $expireLe = $maintenant + $this->ttl;
        $this->stockage->ouvrirLitige($compteId, $numero, $empreinteSesame, $maintenant, $expireLe);

        return ['ok' => true, 'numero' => $numero, 'questions' => self::questions(),
                'expire_le' => $expireLe,
                'message' => 'Garde ton sésame : sans lui, personne ne peut reprendre ce dossier, toi compris.'];
    }

    /**
     * Dépose les réponses et assemble le faisceau pour l'arbitre.
     *
     * @param array<string, string> $reponses
     * @return array{ok: bool, message: string, statut?: string, error?: string}
     */
    public function soumettre(
        string $numero,
        string $sesame,
        array $reponses,
        ?int $maintenant = null,
    ): array {
        $maintenant = $maintenant ?? time();

        $litige = $this->recevable($numero, $sesame, $maintenant);
        if (!is_object($litige)) {
            return $litige;
        }
        if (!$litige->enCours()) {
            return ['ok' => false, 'error' => 'deja_tranche', 'message' => 'Ce dossier a déjà été tranché.'];
        }
        if ($litige->deposeLe > 0 && $maintenant - $litige->deposeLe < $this->attenteDepot) {
            return ['ok' => false, 'error' => 'trop_tot', 'message' => 'Un dépôt par heure. Réessaie plus tard.'];
        }

        $faits = $this->stockage->faitsDuCompte($litige->compteId);
        if ($faits === null) {
            return ['ok' => false, 'error' => 'compte_inconnu', 'message' => 'Aucun compte à ce nom.'];
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
        foreach ($reponses as $cle => $valeur) {
            if (!is_string($valeur) || !mb_check_encoding($valeur, 'UTF-8')
                || !is_string($cle) || !mb_check_encoding($cle, 'UTF-8')) {
                return ['ok' => false, 'error' => 'reponses_invalides',
                        'message' => 'Une réponse n\'est pas du texte valide. Réessaie sans caractère exotique.'];
            }
        }

        $faisceau = $this->faisceau($faits, $reponses, $maintenant);
        $encode   = json_encode($faisceau, JSON_UNESCAPED_UNICODE);
        if ($encode === false) {
            // Le garde-fou du garde-fou : si le contrôle ci-dessus laisse passer
            // quelque chose un jour, on refuse encore plutôt que de ranger du vide.
            return ['ok' => false, 'error' => 'faisceau_illisible',
                    'message' => 'Le dossier n\'a pas pu être assemblé. Réessaie.'];
        }

        $this->stockage->enregistrerFaisceau($litige->id, $encode, $maintenant);

        // 🔑 Journalisé comme un ÉCHEC. Un niveau 3 ne réussit jamais tout seul :
        // s'il comptait comme une réussite, il effacerait l'ardoise des
        // tentatives et deviendrait la voie la moins surveillée du service.
        $this->stockage->tracerTentative('l3:' . $faits['nom_compte'], false, null, $maintenant);

        return ['ok' => true, 'statut' => Litige::A_LIRE,
                'message' => 'Dossier transmis. Un arbitre va le lire et te répondre dans le fil de ce dossier. '
                           . 'Reviens avec ton numéro et ton sésame.'];
    }

    /**
     * L'état d'un dossier, pour son demandeur.
     *
     * ⚠️ Le faisceau NE redescend PAS : il lui dirait quoi répondre la
     * prochaine fois.
     *
     * @return array{ok: bool, message?: string, numero?: string, statut?: string, expire_le?: int, error?: string}
     */
    public function etat(string $numero, string $sesame, ?int $maintenant = null): array
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
        string $sesame,
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
            return ['ok' => false, 'error' => 'introuvable', 'message' => 'Dossier introuvable.'];
        }
        $refus = $this->ecrire($litige, 'admin', $message, $maintenant);

        return $refus ?? ['ok' => true, 'message' => 'Message ajouté au fil.'];
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
     * sans fabriquer de secret ; `refuse` clôt le dossier, compte les refus
     * récents, et gèle la PROCÉDURE au seuil — jamais le compte.
     *
     * @return array{ok: bool, message: string, statut?: string, refus_dans_la_fenetre?: int, gele?: bool, error?: string}
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
                    'message' => 'La décision vaut « accepte » ou « refuse ».'];
        }
        $litige = $this->stockage->trouverLitigeParNumero($numero);
        if ($litige === null) {
            return ['ok' => false, 'error' => 'introuvable', 'message' => 'Dossier introuvable.'];
        }
        if (!$litige->enCours()) {
            return ['ok' => false, 'error' => 'deja_tranche', 'message' => 'Ce dossier a déjà été tranché.'];
        }

        if ($decision === 'accepte') {
            $this->stockage->trancherLitige($litige->id, Litige::ACCEPTE, $par, $maintenant);

            return ['ok' => true, 'statut' => Litige::ACCEPTE,
                    'message' => 'Dossier accepté. Le titulaire repose lui-même ses secrets ; '
                               . 'aucun secret n\'a été fabriqué ici.'];
        }

        $this->stockage->trancherLitige($litige->id, Litige::REFUSE, $par, $maintenant);

        $refus = $this->stockage->compterRefusRecents($litige->compteId, $maintenant - $this->gelFenetre);
        $gele  = false;
        if ($refus >= $this->gelSeuil) {
            $this->stockage->poserGel($litige->compteId, $maintenant + $this->gelDuree, $maintenant);
            $gele = true;
        }

        return ['ok' => true, 'statut' => Litige::REFUSE, 'refus_dans_la_fenetre' => $refus, 'gele' => $gele,
                'message' => $gele
                    ? 'Dossier refusé. L\'ouverture de nouveaux dossiers est gelée ' . (int) ($this->gelDuree / 86400)
                        . ' jours sur ce compte. Le compte n\'est pas touché.'
                    : 'Dossier refusé. Le compte n\'est pas touché.'];
    }

    /** Lève un gel de procédure. La trace du dégel est conservée. */
    public function degeler(string $nomCompte, string $par, ?int $maintenant = null): array
    {
        $maintenant = $maintenant ?? time();
        $nomCompte  = strtolower(trim($nomCompte));

        // Ce chemin distingue « inconnu » de « levé » sans frein ni délai, et
        // c'est assumé : il est réservé à un arbitre, et la qualité d'arbitre se
        // vérifie à l'endpoint — la bibliothèque ne connaît pas les rôles.
        $compte = $this->stockage->trouverCompte($nomCompte);
        if ($compte === null) {
            return ['ok' => false, 'error' => 'compte_inconnu', 'message' => 'Aucun compte à ce nom.'];
        }
        $this->stockage->leverGel((int) $compte['id'], $par, $maintenant);

        return ['ok' => true, 'message' => 'Gel levé. L\'ouverture d\'un dossier est de nouveau possible.'];
    }

    /**
     * Le titulaire repose lui-même ses secrets, après un accord.
     *
     * 🔑 **Aucun mot de passe n'est rendu**, contrairement aux niveaux 1 et 2.
     * C'est la propriété distinctive du niveau : le serveur n'émet rien, il
     * range ce que le titulaire a choisi. La passphrase et les codes, eux, sont
     * engendrés — ils ne peuvent pas venir du client.
     *
     * @return array{ok: bool, message: string, passphrase?: string, codes?: array, error?: string}
     */
    public function reEnroler(
        string $numero,
        string $sesame,
        string $motDePasse,
        string $motDerive,
        string $sel,
        ?int $maintenant = null,
    ): array {
        $maintenant = $maintenant ?? time();

        $litige = $this->recevable($numero, $sesame, $maintenant);
        if (!is_object($litige)) {
            return $litige;
        }
        if ($litige->statut !== Litige::ACCEPTE) {
            return ['ok' => false, 'error' => 'non_accepte', 'message' => 'Ce dossier n\'a pas été accepté.'];
        }
        $long = mb_strlen($motDePasse);
        if ($long < self::MOT_DE_PASSE_MINIMUM || $long > self::MOT_DE_PASSE_MAXIMUM) {
            return ['ok' => false, 'error' => 'mot_de_passe_invalide',
                    'message' => 'Le mot de passe doit faire entre ' . self::MOT_DE_PASSE_MINIMUM
                               . ' et ' . self::MOT_DE_PASSE_MAXIMUM . ' caractères.'];
        }
        if (!Device::estCleDerivee($motDerive)) {
            return ['ok' => false, 'error' => 'invalid_derived_key',
                    'message' => 'Mot mémorisé invalide : la dérivation doit se faire dans le navigateur.'];
        }
        if (!preg_match('/^[a-f0-9]{32}$/', $sel)) {
            return ['ok' => false, 'error' => 'sel_invalide',
                    'message' => 'Le sel du compte est absent ou malformé.'];
        }

        $passphrase = $this->recovery->engendrerPassphrase();

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
            $this->stockage->validerTransaction();
        } catch (\Throwable $e) {
            $this->stockage->annulerTransaction();

            throw $e;
        }

        return ['ok' => true, 'passphrase' => $passphrase, 'codes' => $codes,
                'message' => 'Compte repris. Note ces codes et cette passphrase : ils ne seront pas réaffichés.'];
    }

    /** Efface les dossiers périmés. Rend le nombre effacé. */
    public function purger(?int $maintenant = null): int
    {
        return $this->stockage->purgerLitigesExpires($maintenant ?? time());
    }

    // ── Interne ────────────────────────────────────────────────────────────

    /**
     * Les freins de l'ouverture. Deux compteurs, aucun nom de compte.
     *
     * ⚠️ **Il n'y a délibérément PAS de frein par compte.** Un tel frein
     * fermerait l'ouverture à un titulaire dès qu'un tiers a assez sollicité son
     * compte, sans qu'aucun dossier n'existe — donc sans que rien n'apparaisse à
     * l'arbitre. Le harcèlement d'un compte est déjà borné autrement : le
     * premier dossier tient `$ttl`, le suivant reçoit `deja_ouvert`, et cette
     * collision-là **se compte et se montre** (`compterDemandeurConcurrent`).
     * Un frein silencieux aurait remplacé un fait visible par un mur muet.
     *
     * ⚠️ Un refus unique pour les deux, et sans délai : le message ne cache rien
     * qu'un chronomètre pourrait retrouver, contrairement aux refus qui suivent.
     * L'y ajouter tiendrait un exécutant occupé à chaque requête refusée, ce qui
     * est le levier qu'on retire à l'attaquant.
     *
     * @return array{ok: bool, error: string, message: string}|null
     */
    private function freinerOuverture(?string $ip, int $maintenant): ?array
    {
        $depuis = $maintenant - $this->fenetreOuvertures;
        $refus  = ['ok' => false, 'error' => 'trop_de_demandes',
                   'message' => 'Trop de demandes d\'arbitrage récemment. Réessaie plus tard.'];

        if ($ip !== null
            && $this->stockage->compterEchecsCompte($this->etiquette('@' . $ip), $depuis)
               >= $this->maxOuverturesIp) {
            return $refus;
        }
        if ($this->stockage->compterEchecsCompte($this->etiquette('*'), $depuis)
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
     * @return Litige|array{ok: false, error: string, message: string}
     */
    private function recevable(string $numero, string $sesame, int $maintenant): Litige|array
    {
        $litige = $this->stockage->trouverLitigeParNumero(strtoupper(trim($numero)));

        // Les deux vérifications sont menées quoi qu'il arrive : s'arrêter à la
        // première échouée dirait par le temps ce que le message tait.
        $factice   = str_repeat('0', 64);
        $sesameOk  = hash_equals($litige?->empreinteSesame ?? $factice, self::empreinteSesame($sesame));
        $recevable = $litige !== null && $sesameOk && $sesame !== '';

        if (!$recevable) {
            usleep($this->delaiRefusUs);

            return ['ok' => false, 'error' => 'sesame_invalide', 'message' => 'Numéro ou sésame invalide.'];
        }
        // ⚠️ Un dossier ACCEPTÉ ne périme pas. Le délai borne le temps pendant
        // lequel un dossier reste ouvert sans être instruit ; l'appliquer après
        // l'accord rendrait l'arbitrage humain inutilisable — un arbitre qui
        // prend deux jours pour se décider annulerait son propre travail, et le
        // titulaire devrait tout recommencer. C'est le ré-enrôlement qui ferme
        // le dossier, pas l'horloge.
        if ($litige->statut !== Litige::ACCEPTE && $litige->expire($maintenant)) {
            return ['ok' => false, 'error' => 'expire',
                    'message' => 'Ce dossier a expiré. Il faut en ouvrir un nouveau.'];
        }

        return $litige;
    }

    /** @return array{ok: false, error: string, message: string}|null */
    private function ecrire(Litige $litige, string $auteur, string $message, int $maintenant): ?array
    {
        $texte = trim($message);
        if ($texte === '') {
            return ['ok' => false, 'error' => 'vide', 'message' => 'Le message est vide.'];
        }
        if (mb_strlen($texte) > 2000) {
            return ['ok' => false, 'error' => 'trop_long', 'message' => 'Le message dépasse 2000 caractères.'];
        }
        if ($litige->statut === Litige::CLOS) {
            return ['ok' => false, 'error' => 'clos', 'message' => 'Ce dossier est clos.'];
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
