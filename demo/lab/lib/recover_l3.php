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
 * compte pouvait le faire effacer en accumulant des refus. Ce qui se durcit
 * désormais est la procédure : trois refus en trente jours gèlent l'ouverture
 * sept jours, et le compte reste entier.
 */

namespace Pierroons\MySelfLab;

use PDO;
use Pierroons\SelfRecover\Recovery\Escalade;
use Pierroons\SelfRecover\Recovery\Recovery;

require_once __DIR__ . '/StockageSelfRecover.php';

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
        'deja_ouvert'        => 409,
        'deja_tranche'       => 409,
        'non_accepte'        => 409,
        'clos'               => 409,
        'gele'               => 429,
        'trop_tot'           => 429,
        'trop_de_demandes'   => 429,
    ];

    private static function escalade(PDO $pdo): Escalade
    {
        $stockage = new StockageSelfRecover($pdo);

        return new Escalade($stockage, new Recovery($stockage, Auth::siteSalt()));
    }

    /** Ajoute le code HTTP au refus rendu par la bibliothèque. */
    private static function http(array $r): array
    {
        if (($r['ok'] ?? false) === true) {
            return $r;
        }
        $r['code'] = self::CODES[$r['error'] ?? ''] ?? 400;

        return $r;
    }

    /** Les questions de contexte — le front les affiche telles quelles. */
    public static function questions(): array
    {
        return Escalade::questions();
    }

    public static function init(PDO $pdo, string $username, string $claimHash, ?string $ip = null): array
    {
        $r = self::escalade($pdo)->ouvrir($username, strtolower(trim($claimHash)), $ip);
        if (($r['ok'] ?? false) !== true) {
            return self::http($r);
        }

        // Le front lit `dispute_number` depuis qu'il existe ; le renommer
        // casserait une procédure en cours dans un navigateur qui a gardé son
        // sésame en `localStorage`.
        return [
            'ok'             => true,
            'dispute_number' => $r['numero'],
            'questions'      => $r['questions'],
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
     * ⚠️ Un refus ne supprime plus le compte. La réponse porte `gele` pour que
     * la console puisse le dire à l'arbitre : trois refus dans la fenêtre gèlent
     * l'ouverture, et il vaut mieux qu'il l'apprenne au moment où il tranche.
     */
    public static function adminDecide(PDO $pdo, string $number, string $decision, string $par = 'admin'): array
    {
        $r = self::escalade($pdo)->trancher(
            $number,
            $decision === 'grant' ? 'accepte' : ($decision === 'refuse' ? 'refuse' : $decision),
            $par,
        );
        if (($r['ok'] ?? false) !== true) {
            return self::http($r);
        }

        return ['ok' => true, 'decision' => $r['statut'], 'message' => $r['message'],
                'refus_dans_la_fenetre' => $r['refus_dans_la_fenetre'] ?? null,
                'gele' => $r['gele'] ?? false];
    }

    /** Lève un gel de procédure. Réservé à un arbitre, garde posée par l'endpoint. */
    public static function adminUnfreeze(PDO $pdo, string $username, string $par = 'admin'): array
    {
        return self::http(self::escalade($pdo)->degeler(strtolower(trim($username)), $par));
    }

    /**
     * Le titulaire repose lui-même ses secrets.
     *
     * 🔑 Aucun mot de passe n'est rendu : il vient du navigateur, il n'y retourne
     * pas. La passphrase et les codes, eux, sont engendrés — ils ne peuvent pas
     * venir du client.
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
