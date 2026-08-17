<?php
/**
 * MySelf-Lab — profil membre chiffré at-rest (SelfDataGuard).
 *
 * Les champs perso (bio, localisation, lien) sont sérialisés en JSON puis
 * chiffrés AES-256-GCM avant stockage. Un dump de la table `profiles` ne
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
    /** Champs jamais exposés dans la vue publique d'un membre.
     *  (Le mémo perso a migré vers le coffre E2E client — voir MemoVault.) */
    private const PRIVES = [];

    /** Retourne le profil déchiffré, ou des champs vides. */
    public static function get(PDO $pdo, int $accountId): array
    {
        $stmt = $pdo->prepare('SELECT ciphertext FROM profiles WHERE account_id = ?');
        $stmt->execute([$accountId]);
        $blob = $stmt->fetchColumn();
        $vide = array_fill_keys(self::CHAMPS, '');
        if ($blob === false) {
            return $vide;
        }
        try {
            $json = DataGuard::decrypt($blob);
            $data = json_decode($json, true);
            return is_array($data) ? array_merge($vide, $data) : $vide;
        } catch (\Throwable) {
            return $vide;
        }
    }

    /** Chiffre + persiste le profil. */
    public static function save(PDO $pdo, int $accountId, array $champs): array
    {
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
        $ciphertext = DataGuard::encrypt(json_encode($clean, JSON_UNESCAPED_UNICODE));
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
        $profil = self::get($pdo, (int) $acc['id']);
        foreach (self::PRIVES as $c) {
            unset($profil[$c]);
        }
        return [
            'username' => $acc['username'],
            'profil'   => $profil,
        ];
    }
}
