<?php

declare(strict_types=1);

/**
 * MySelf-Lab — le niveau 3, côté application.
 *
 * 🔑 **Ce fichier ne décide plus rien.** Depuis le 07/09/2026, le protocole du
 * niveau 3 vit dans `Pierroons\SelfRecover\Recovery\Escalade` : le dossier, le
 * sésame, le faisceau, l'arbitrage et le gel. Ce qui reste ici est ce que la
 * bibliothèque ne peut pas savoir — les codes HTTP que les endpoints rendent, la
 * qualité d'arbitre lue dans la session, et les noms de champs que le front
 * attend depuis qu'il existe.
 *
 * ⚠️ Ce qui a disparu en chemin, et c'est le motif de la bascule : cette classe
 * **supprimait le compte dès le premier refus**. Un refus dit « ce demandeur ne
 * m'a pas convaincu », pas « ce compte est illégitime ». Si le demandeur était
 * un imposteur, on détruisait le compte de sa victime ; s'il était le titulaire
 * mal jugé, on punissait un innocent. Et un attaquant incapable de voler un
 * compte pouvait le faire effacer en accumulant des refus. Ce qui peut se
 * durcir désormais est la procédure, jamais le compte : il reste entier.
 *
 * ⚠️ Aucun compteur ne durcit de lui-même : les refus sont comptés et
 * **signalés** à l'arbitre (`gel_suggere`), qui pose le gel par `adminFreeze()`
 * et le lève. Pourquoi cette décision ne peut pas revenir au compteur :
 * cf. `adminFreeze()`.
 */

namespace Pierroons\MySelfLab;

use PDO;
use Pierroons\SelfRecover\Duree;
use Pierroons\SelfRecover\Recovery\Escalade;
use Pierroons\SelfRecover\ProfilDeploiement;
use Pierroons\SelfRecover\Recovery\Recovery;

require_once __DIR__ . '/StockageSelfRecover.php';
require_once __DIR__ . '/i18n.php';
require_once __DIR__ . '/reponse.php';

final class RecoverL3
{
    /**
     * Le code HTTP qui correspond à un refus de la bibliothèque.
     *
     * La bibliothèque ne rend pas de code HTTP — elle ne sait pas qu'elle est
     * servie par du HTTP. La correspondance vit donc ici, dans la couche qui en
     * fait quelque chose.
     */
    private const CODES = [
        'empreinte_invalide' => 400,
        'sel_invalide'       => 400,
        'invalid_derived_key' => 400,
        'vide'               => 400,
        'reponses_invalides' => 400,
        'faisceau_illisible' => 500,
        'trop_long'          => 400,
        'decision_inconnue'  => 400,
        'sesame_invalide'    => 403,
        'compte_inconnu'     => 404,
        'introuvable'        => 404,
        'expire'             => 410,
        'accord_perime'      => 410,
        'deja_tranche'       => 409,
        'fil_plein'          => 429,
        'trop_rapide'        => 429,
        'reponse_trop_longue' => 400,
        'non_accepte'        => 409,
        'clos'               => 409,
        'aucun_litige'       => 409,
        'passphrase_deja_servie' => 409,
        'passphrase_egale_mot_de_passe' => 400,
        'mot_de_passe_invalide' => 400,
        'ouverture_refusee'  => 403,
        'trop_tot'           => 429,
        'trop_de_demandes'   => 429,
    ];

    private static function escalade(PDO $pdo): Escalade
    {
        $stockage = new StockageSelfRecover($pdo);

        return new Escalade(
            $stockage,
            new Recovery(
                $stockage,
                Auth::siteSalt(),
                ProfilDeploiement::CLEARWEB,
                langueSelfRecover(),
                fenetreEchecs: Auth::LOGIN_WINDOW,
                maxEchecsCompte: Auth::LOGIN_MAX_FAILS,
                maxEchecsIp: Auth::LOGIN_MAX_FAILS_PER_IP,
            ),
        );
    }

    /**
     * Les règles du gel dites en clair, pour les écrans d'arbitrage.
     *
     * @return array{seuil: int, fenetre: string, duree: string}
     */
    public static function reglesDuGel(PDO $pdo): array
    {
        $r = self::escalade($pdo)->reglesDuGel();

        return ['seuil' => $r['seuil'], 'fenetre' => Duree::enClair($r['fenetre'], langueSelfRecover()), 'duree' => Duree::enClair($r['duree'], langueSelfRecover())];
    }

    /** Ajoute le code HTTP au refus rendu par la bibliothèque. */
    private static function http(array $r): array
    {
        if (($r['ok'] ?? false) === true) {
            return $r;
        }
        $r['code'] = self::CODES[$r['error'] ?? ''] ?? 400;

        // Tout le niveau 3 passe par ici : un seul endroit à tenir.
        return refus_publiable($r);
    }

    /**
     * La forme de rendu de chaque question, ajoutée par `questions()`.
     *
     * ⚠️ Les trois valeurs de fréquence sont celles que le faisceau compare
     * (`Escalade::faisceau()`) : les changer ici, ou en ajouter une, fait
     * diverger toute réponse qui l'emploie.
     */
    private const FORME = [
        'annee_creation' => ['type' => 'year'],
        'mois_connexion' => ['type' => 'month'],
        'frequence'      => ['type' => 'select', 'options' => ['souvent', 'parfois', 'rare']],
    ];

    /**
     * Les questions de contexte, sous les noms que la page attend.
     *
     * 🔑 La bibliothèque rend `cle` et `texte` ; `public/dispute.php` lit `key`,
     * `label`, `type` et `options`. Sans ce renommage, la page affiche des
     * libellés vides et soumet un formulaire **sans aucune réponse** — et un
     * faisceau sans réponse arrive en « diverge » devant l'arbitre.
     *
     * Le renommage vit ici parce que c'est le rôle de ce relais : `dispute_number`
     * est déjà un renommage de `numero`. Toucher la bibliothèque obligerait ses
     * autres intégrateurs à suivre.
     *
     * @return list<array{key: string, label: string, type: string, options?: list<string>}>
     */
    public static function questions(): array
    {
        $sortie = [];
        foreach (Escalade::questions() as $q) {
            $cle = (string) ($q['cle'] ?? '');
            $sortie[] = array_merge(
                ['key' => $cle, 'label' => (string) ($q['texte'] ?? ''), 'type' => 'text'],
                self::FORME[$cle] ?? [],
            );
        }

        return $sortie;
    }

    public static function init(PDO $pdo, string $username, string $claimHash, ?string $ip = null): array
    {
        $r = self::escalade($pdo)->ouvrir($username, strtolower(trim($claimHash)), $ip);
        if (($r['ok'] ?? false) !== true) {
            // Cette route est publique et sans authentification. Un nom inconnu,
            // un dossier déjà ouvert et une procédure gelée y reçoivent un seul
            // refus, `ouverture_refusee` : distinguer les deux derniers
            // apprendrait qu'un TIERS a une récupération en cours. Son message,
            // le même pour les trois, dit au titulaire où est son recours.
            if (($r['error'] ?? '') === 'ouverture_refusee') {
                return [
                    'ok'      => false,
                    'error'   => 'refuse',
                    'code'    => 409,
                    'message' => (string) $r['message'],
                ];
            }
            return self::http($r);
        }

        // Le front lit `dispute_number` depuis qu'il existe ; le renommer
        // casserait une procédure en cours dans un navigateur qui a gardé son
        // sésame en `localStorage`.
        return [
            'ok'             => true,
            'dispute_number' => $r['numero'],
            // Sous les noms de la page, pas ceux de la bibliothèque : cf. questions().
            'questions'      => self::questions(),
            'expires_at'     => $r['expire_le'],
            'message'        => $r['message'],
            'note'           => 'Garde ce numéro ET ton sésame : sans les deux, personne ne peut '
                              . 'reprendre ce dossier, toi compris.',
        ];
    }

    public static function submit(
        PDO $pdo,
        string $number,
        string $claimSecret,
        array $answers,
        ?string $ip = null,
    ): array {
        $r = self::escalade($pdo)->soumettre($number, $claimSecret, $answers);
        if (($r['ok'] ?? false) !== true) {
            return self::http($r);
        }

        return ['ok' => true, 'dispute_number' => strtoupper(trim($number)),
                'status' => $r['statut'], 'message' => $r['message']];
    }

    /**
     * Le fil. `$message === null` vaut lecture (le front interroge en boucle).
     *
     * 🔑 L'arbitre est établi par la SESSION, jamais par le corps de la requête :
     * `$isAdmin` arrive de `require_admin()`, côté endpoint. La bibliothèque, elle,
     * ne connaît pas les rôles — c'est pourquoi elle expose deux chemins distincts
     * et que le choix se fait ici.
     */
    public static function chat(
        PDO $pdo,
        string $number,
        string $claimSecret,
        ?string $message,
        bool $isAdmin,
        ?string $ip = null,
    ): array {
        $esc = self::escalade($pdo);

        if ($isAdmin) {
            if ($message !== null && trim($message) !== '') {
                $ecrit = $esc->repondre($number, $message);
                if (($ecrit['ok'] ?? false) !== true) {
                    return self::http($ecrit);
                }
            }
            $litige = (new StockageSelfRecover($pdo))->trouverLitigeParNumero(strtoupper(trim($number)));
            if ($litige === null) {
                return self::http(['ok' => false, 'error' => 'introuvable', 'message' => 'Dossier introuvable.']);
            }

            return ['ok' => true, 'status' => $litige->statut,
                    'messages' => (new StockageSelfRecover($pdo))->messagesDuLitige($litige->id)];
        }

        $r = $esc->fil($number, $claimSecret, $message);
        if (($r['ok'] ?? false) !== true) {
            return self::http($r);
        }

        return ['ok' => true, 'status' => $r['statut'], 'messages' => $r['messages']];
    }

    /** Ce que la console d'arbitrage affiche. Jamais l'IP du demandeur. */
    public static function adminList(PDO $pdo): array
    {
        return ['ok' => true, 'disputes' => self::escalade($pdo)->litiges(100)];
    }

    /**
     * La décision. Le front envoie `grant` / `refuse` depuis qu'il existe.
     *
     * ⚠️ Un refus ne supprime pas le compte et ne gèle rien : il compte. La
     * réponse porte `gel_suggere`, que la console montre à l'arbitre pour qu'il
     * décide — le gel se pose par `adminFreeze()`.
     *
     * 🔑 La clé `gele` de la bibliothèque vaut toujours `false` : une console
     * qui s'y fie n'affiche jamais rien, sans erreur pour le dire. Elle n'est
     * pas relayée ici, et `gel_suggere` la remplace.
     */
    public static function adminDecide(PDO $pdo, string $number, string $decision, string $par = 'admin',
                                      ?int $maintenant = null): array
    {
        $r = self::escalade($pdo)->trancher(
            $number,
            $decision === 'grant' ? 'accepte' : ($decision === 'refuse' ? 'refuse' : $decision),
            $par,
            $maintenant,
        );
        if (($r['ok'] ?? false) !== true) {
            return self::http($r);
        }

        return ['ok' => true, 'decision' => $r['statut'], 'message' => $r['message'],
                'refus_dans_la_fenetre' => $r['refus_dans_la_fenetre'] ?? null,
                'gel_suggere' => $r['gel_suggere'] ?? false];
    }

    /**
     * Clôt la procédure en cours d'un compte sans la trancher.
     *
     * 🔑 C'est la sortie devant un dossier ouvert par quelqu'un d'autre que le
     * titulaire. Refuser serait l'action naturelle, et c'est le piège : chaque
     * refus compte sur le compte VISÉ. Un tiers qui ouvre assez de dossiers
     * fait donc monter un compteur qui n'est pas le sien, et l'arbitre voit
     * s'afficher une suggestion de gel contre une victime. Le gel ne s'arme
     * plus tout seul depuis la 0.12.0, mais le signal, lui, est toujours
     * remplissable par un tiers : c'est pourquoi la sortie reste l'abandon.
     *
     * L'abandon libère la place sans toucher à ce compteur. Il ne rend aucun
     * accès : le titulaire rouvre un dossier et l'arbitrage est à refaire.
     *
     * Réservé à un arbitre, garde posée par l'endpoint.
     *
     * ⚠️ La trace est posée ICI, pas par la bibliothèque : `cloreLitige($litigeId,
     * $quand)` ne reçoit pas l'auteur de l'abandon. Qui supprime l'`UPDATE`
     * ci-dessous fait disparaître l'abandon des archives sans un bruit.
     */
    public static function adminAbandon(PDO $pdo, string $username, string $par = 'admin'): array
    {
        $r = self::escalade($pdo)->abandonner(strtolower(trim($username)), $par);
        if (($r['ok'] ?? false) !== true) {
            return self::http($r);
        }

        $pdo->prepare('UPDATE disputes SET abandonne_par = ?, abandonne_le = ? WHERE dispute_number = ?')
            ->execute([$par, time(), (string) $r['numero']]);

        return $r;
    }

    /** Lève un gel de procédure. Réservé à un arbitre, garde posée par l'endpoint. */
    public static function adminUnfreeze(PDO $pdo, string $username, string $par = 'admin',
                                        ?int $maintenant = null): array
    {
        return self::http(self::escalade($pdo)->degeler(strtolower(trim($username)), $par, $maintenant));
    }

    /**
     * Gèle l'ouverture de nouveaux dossiers, sur décision de l'arbitre.
     *
     * 🔑 **Le seul chemin qui pose un gel, et il doit le rester.** Le gel ne
     * peut pas s'armer sur le compteur de refus : les refus se comptent sur le
     * compte VISÉ, et ouvrir un dossier ne demande qu'un nom affiché — un tiers
     * remplirait donc ce compteur et ferait fermer, par l'arbitre lui-même, le
     * dernier recours de sa victime. Le compteur informe (`gel_suggere`), ce
     * chemin décide.
     *
     * ⚠️ Il ferme la PROCÉDURE, pas le compte. Mais au niveau 3 celui qui
     * arrive n'a plus ni mot de passe, ni passphrase, ni feuille de codes : un
     * gel laisse donc une personne dehors tant qu'il court. La durée est bornée
     * et `adminUnfreeze()` le lève immédiatement.
     *
     * Réservé à un arbitre, garde posée par l'endpoint.
     */
    public static function adminFreeze(PDO $pdo, string $username, string $par = 'admin',
                                      ?int $maintenant = null): array
    {
        // `poserGel()` prend l'auteur du gel et l'adaptateur l'écrit dans
        // `gele_par` au moment de la pose : rien à ranger après coup.
        return self::http(self::escalade($pdo)->geler(strtolower(trim($username)), $par, $maintenant));
    }

    /**
     * Le titulaire repose lui-même ses secrets.
     *
     * 🔑 Aucun mot de passe n'est rendu : il vient du navigateur, il n'y retourne
     * pas. La passphrase et les codes, eux, sont engendrés : le lab ne propose
     * pas d'apporter la passphrase.
     */
    public static function reset(
        PDO $pdo,
        string $number,
        string $claimSecret,
        string $password,
        string $recoveryDerivedKey,
        string $recoverySalt,
    ): array {
        $r = self::escalade($pdo)->reEnroler(
            $number,
            $claimSecret,
            $password,
            strtolower(trim($recoveryDerivedKey)),
            $recoverySalt,
        );
        if (($r['ok'] ?? false) !== true) {
            return self::http($r);
        }

        return [
            'ok'          => true,
            'message'     => $r['message'],
            'credentials' => ['passphrase' => $r['passphrase'], 'recovery_codes' => $r['codes']],
            'note'        => 'Ton mot de passe est celui que tu viens de choisir : le serveur ne l\'a pas '
                           . 'fabriqué et ne le renvoie pas.',
        ];
    }
}
