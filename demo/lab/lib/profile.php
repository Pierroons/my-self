<?php
/**
 * MySelf-Lab — profil membre chiffré at-rest (SelfDataGuard).
 *
 * Les champs perso (bio, localisation, lien) sont sérialisés en JSON puis
 * chiffrés XChaCha20-Poly1305 avant stockage. Un dump de la table `profiles` ne
 * révèle que des blobs. Aucune donnée personnelle en clair en base.
 */

declare(strict_types=1);

namespace Pierroons\MySelfLab;

use PDO;

require_once __DIR__ . '/dataguard.php';

final class Profile
{
    private const CHAMPS = ['bio', 'localisation', 'lien'];
    /** Limites par champ. */
    private const LIMITES = ['bio' => 500, 'localisation' => 500, 'lien' => 500];
    /** Champs jamais exposés dans la vue publique d'un membre. Vide depuis que le
     *  mémo perso a migré vers le coffre E2E client (voir MemoVault) : les trois
     *  champs de CHAMPS sont publics par choix, et tout champ qu'on y ajoutera le
     *  sera aussi tant qu'il n'est pas repris ici. */
    private const PRIVES = [];

    /** Le profil déchiffré, ou des champs vides — qu'il n'y en ait pas, ou qu'il ne s'ouvre pas. */
    public static function get(PDO $pdo, int $accountId): array
    {
        return self::lire($pdo, $accountId)['champs'];
    }

    /**
     * Les champs et l'état du profil, pour un seul déchiffrement.
     *
     * `get()` puis `illisible()` déchiffraient deux fois le même blob, et la page
     * d'édition appelle les deux.
     *
     * @return array{champs: array<string, string>, illisible: bool}
     */
    public static function lire(PDO $pdo, int $accountId): array
    {
        $vide = array_fill_keys(self::CHAMPS, '');
        [$present, $clair] = self::clair($pdo, $accountId);
        if ($clair === null) {
            return ['champs' => $vide, 'illisible' => $present];
        }
        $data = json_decode($clair, true);

        return ['champs' => is_array($data) ? array_merge($vide, $data) : $vide, 'illisible' => false];
    }

    /**
     * Ce compte a-t-il un profil que le serveur ne sait pas ouvrir ?
     *
     * 🔑 **L'état se demande à part, il n'habite pas la carte des champs.** Rendu
     * comme une clé de `get()`, il partageait l'espace de noms des champs : un clair
     * portant lui-même `illisible` aurait déclaré une panne qui n'existait pas, et
     * un champ futur du même nom serait entré en collision.
     *
     * 🔑 **Un profil absent et un profil illisible ne sont pas le même état.** Les
     * deux rendent des champs vides, et la page d'édition pré-remplit son formulaire
     * avec ce qu'elle reçoit : sans cette distinction, le titulaire voyait un profil
     * effacé, le retapait, et son chiffré d'origine partait — une clé restaurée
     * ensuite n'aurait plus rien rendu.
     */
    public static function illisible(PDO $pdo, int $accountId): bool
    {
        return self::lire($pdo, $accountId)['illisible'];
    }

    /**
     * Le profil existe-t-il, et son clair — `null` s'il n'y en a pas ou s'il ne s'ouvre pas.
     *
     * @return array{0: bool, 1: ?string}
     */
    private static function clair(PDO $pdo, int $accountId): array
    {
        $stmt = $pdo->prepare('SELECT ciphertext FROM profiles WHERE account_id = ?');
        $stmt->execute([$accountId]);
        $blob = $stmt->fetchColumn();
        if ($blob === false) {
            return [false, null];
        }
        // Un blob est là : la clé qui l'a scellé doit exister, et non être posée
        // à l'instant par la lecture. Cette ligne est atteignable sans session.
        DataGuard::exigerCle();
        try {
            return [true, DataGuard::decrypt((string) $blob, DataGuard::contexteProfil($accountId))];
        } catch (\Throwable $e) {
            error_log(sprintf('profil #%d illisible : %s', $accountId, $e->getMessage()));

            return [true, null];
        }
    }

    /**
     * Chiffre et persiste le profil.
     *
     * `$remplacerIllisible` est la **décision humaine** que demande un profil
     * qu'on ne sait plus ouvrir : sans elle, l'enregistrement est refusé pour que
     * la seule copie d'une donnée ne parte pas par mégarde ; avec elle, le
     * titulaire acte la perte et repart d'un profil neuf. Refuser dans les deux cas
     * enfermerait le compte — et un tiers qui obtient une écriture pourrait y mettre
     * n'importe qui, définitivement.
     */
    public static function save(PDO $pdo, int $accountId, array $champs, bool $remplacerIllisible = false): array
    {
        // La bio et le lien sont publics : un ban qui coupe la parole publique
        // les coupe aussi, comme un message du forum.
        $etat = Moderate::getReputation($pdo, $accountId);
        if ($etat['banned']) {
            return ['ok' => false, 'error' => 'compte_banni', 'message' => sprintf(
                'Compte banni jusqu\'au %s : le profil public se modifie de nouveau à la fin de la peine.',
                date('d/m/Y H:i', $etat['banned_until'])
            )];
        }
        $clean = [];
        foreach (self::CHAMPS as $c) {
            $v = trim((string) ($champs[$c] ?? ''));
            if (mb_strlen($v) > self::LIMITES[$c]) {
                return ['ok' => false, 'error' => 'trop_long', 'message' => "Le champ '$c' dépasse " . self::LIMITES[$c] . ' caractères.'];
            }
            $clean[$c] = $v;
        }
        // LAB-04 : le champ « lien » ne doit accepter qu'un protocole web sûr. h() échappe les
        // guillemets mais PAS le préfixe « javascript: » → on filtre le schéma en amont.
        if ($clean['lien'] !== '' && !self::lienSur($clean['lien'])) {
            return ['ok' => false, 'error' => 'lien_invalide',
                    'message' => 'Le lien doit commencer par http:// ou https:// (ou rester vide).'];
        }
        $illisible = self::illisible($pdo, $accountId);
        if (!$remplacerIllisible && $illisible) {
            return ['ok' => false, 'error' => 'profil_illisible',
                    'message' => 'Ton profil existe mais le serveur ne sait pas le lire en ce moment. '
                                 . 'Il n\'est pas remplacé. Préviens un arbitre ; si tu préfères repartir '
                                 . 'd\'un profil neuf, confirme le remplacement — l\'ancien sera perdu.'];
        }

        if ($remplacerIllisible && $illisible) {
            // Un acte irréversible du titulaire se trace : sans cette ligne, le seul
            // chiffré d'un profil partait sans que rien ne dise quand ni par qui.
            error_log(sprintf('profil #%d illisible remplacé par son titulaire — l\'ancien chiffré est perdu', $accountId));
        }

        $ciphertext = DataGuard::encrypt(json_encode($clean, JSON_UNESCAPED_UNICODE), DataGuard::contexteProfil($accountId));
        $pdo->prepare(
            'INSERT INTO profiles (account_id, ciphertext, updated_at) VALUES (?, ?, ?)
             ON CONFLICT(account_id) DO UPDATE SET ciphertext = excluded.ciphertext, updated_at = excluded.updated_at'
        )->execute([$accountId, $ciphertext, time()]);
        return ['ok' => true];
    }

    /** Schéma de lien autorisé (rejette javascript:, data:, vbscript:…). */
    public static function lienSur(string $url): bool
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        return in_array($scheme, ['http', 'https'], true);
    }

    /** Profil public d'un membre par username (déchiffré pour affichage). */
    public static function getByUsername(PDO $pdo, string $username): ?array
    {
        $stmt = $pdo->prepare('SELECT id, username FROM accounts WHERE username = ?');
        $stmt->execute([strtolower(trim($username))]);
        $acc = $stmt->fetch();
        if (!$acc) {
            return null;
        }
        // La vue publique ne révèle jamais les champs privés (ex. mémo perso).
        $lu = self::lire($pdo, (int) $acc['id']);
        $profil = $lu['champs'];
        foreach (self::PRIVES as $c) {
            unset($profil[$c]);
        }
        return [
            'username'  => $acc['username'],
            'profil'    => $profil,
            // Un profil illisible ne s'affiche pas comme un profil vide : le visiteur
            // conclurait que le membre n'a rien écrit.
            'illisible' => $lu['illisible'],
        ];
    }
}
