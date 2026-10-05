<?php

declare(strict_types=1);

namespace Pierroons\MySelfLab;

use PDO;

require_once __DIR__ . '/dataguard.php';
require_once __DIR__ . '/secret_instance.php';
require_once __DIR__ . '/notify.php';

/**
 * Les drapeaux du challenge : valider une soumission sur-le-champ, et retenir
 * qui est arrivé le premier.
 *
 * 🔑 La valeur d'un drapeau n'est jamais stockée, ni ici ni en base : on garde
 * HMAC(drapeau, secret d'instance). Un vol de la base ne rend donc pas les
 * drapeaux et ne permet pas de les retrouver hors ligne, là où un SHA-256 nu
 * tomberait au dictionnaire dès que le format « FLAG-… » est connu.
 *
 * ⚠️ Ce qui est posé ici ne dit pas si un drapeau EXISTE encore dans les
 * données : l'empreinte survit à la régénération du challenge. Reposer un
 * drapeau remplace son empreinte et laisse les captures précédentes — elles
 * disent qui avait trouvé l'ancien, ce qui reste vrai.
 */
final class Flags
{
    /** Les drapeaux du challenge, dans l'ordre d'affichage. */
    public const CODES = ['FLAG-DM', 'FLAG-E2E'];

    /**
     * Soumissions tolérées par adresse et par heure.
     *
     * Le frein ne protège pas d'une recherche exhaustive — un drapeau de 32
     * octets aléatoires n'est pas devinable, et c'est lui le rempart. Il évite
     * qu'un script bavard remplisse la table et le journal.
     */
    private const PAR_HEURE = 60;

    /** Ce que vaut une capture au classement, à côté des rapports. */
    public const POINTS_DRAPEAU = 150;

    /**
     * L'empreinte qui sert a reconnaitre un drapeau.
     *
     * 🔑 HMAC direct sur le secret d'instance, et NON la derivation Argon2id de
     * DataGuard::hmac(). Deux raisons, l'une de fond et l'autre de duree :
     *
     * Un drapeau est tire au hasard sur 32 octets : il n'a rien d'un mot
     * memorise, donc rien a gagner d'une KDF memoire-dure. Le cout proteje une
     * faible entropie ; ici il n'y en a pas.
     *
     * Surtout, DataGuard derive sa cle sous les constantes Argon2id courantes
     * sans enregistrer de profil (releve par l'audit de SelfDataGuard 0.6.0,
     * demo/lab/lib/dataguard.php:47). Le jour ou ces constantes changeront,
     * toutes les empreintes posees deviendraient muettes d'un coup : plus aucun
     * drapeau reconnu, sans message d'erreur, et personne pour s'en apercevoir
     * avant qu'un chercheur ne se plaigne. Le vrai drapeau serait refuse.
     */
    private static function empreinte(string $drapeau): string
    {
        return hash_hmac(
            'sha256',
            trim($drapeau),
            'flag|' . SecretInstance::lire('.serversecret', 48, SecretInstance::PLANCHER)
        );
    }

    /**
     * Pose ou remplace l'empreinte d'un drapeau. Appelé par l'outillage de
     * préparation du challenge, jamais par une route servie : la valeur en
     * clair ne doit pas transiter par une requête.
     */
    public static function poser(PDO $pdo, string $code, string $drapeau): void
    {
        $pdo->prepare(
            'INSERT INTO flag_digests (code, digest, pose_le) VALUES (?, ?, ?)
             ON CONFLICT(code) DO UPDATE SET digest = excluded.digest, pose_le = excluded.pose_le'
        )->execute([$code, self::empreinte($drapeau), time()]);
    }

    /** Les drapeaux dont l'empreinte est posée, donc validables. */
    public static function posés(PDO $pdo): array
    {
        return $pdo->query('SELECT code FROM flag_digests ORDER BY code')
            ->fetchAll(PDO::FETCH_COLUMN) ?: [];
    }

    /**
     * Valide une soumission et enregistre la capture.
     *
     * @return array{ok: bool, message: string, code?: string, premier?: bool, rang?: int}
     */
    public static function soumettre(PDO $pdo, string $drapeau, string $handle, ?string $ip): array
    {
        $drapeau = trim($drapeau);
        if ($drapeau === '' || strlen($drapeau) > 200) {
            return ['ok' => false, 'message' => tc('Soumission vide ou trop longue.')];
        }

        $ipHash = DataGuard::hmac($ip ?? 'unknown', 'flag-ip');

        // Même raison que dans `Redteam::deposer()` : l'empreinte ne sert qu'au
        // frein, et un HMAC d'adresse se renverse pour qui lit la clé d'instance
        // — ce que permettent les mêmes droits que cette base.
        $pdo->prepare('UPDATE flag_captures SET ip_hash = NULL WHERE captured_at < ? AND ip_hash IS NOT NULL')
            ->execute([time() - 3600]);

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM flag_captures WHERE ip_hash = ? AND captured_at >= ?');
        $stmt->execute([$ipHash, time() - 3600]);
        if ((int) $stmt->fetchColumn() >= self::PAR_HEURE) {
            return ['ok' => false, 'message' => tc('Trop de soumissions récentes. Réessaie dans une heure.')];
        }

        // Comparaison en temps constant contre chaque empreinte posée. Le nombre
        // de drapeaux est connu et public : il n'y a rien à cacher sur leur
        // nombre, seulement sur leur valeur.
        $candidat = self::empreinte($drapeau);
        $trouve = null;
        foreach ($pdo->query('SELECT code, digest FROM flag_digests') as $ligne) {
            if (hash_equals((string) $ligne['digest'], $candidat)) {
                $trouve = (string) $ligne['code'];
            }
        }

        if ($trouve === null) {
            // Une soumission fausse n'est pas enregistrée : la table des captures
            // ne doit porter que des réussites, sinon le frein par adresse se
            // déclencherait sur les tâtonnements légitimes.
            return ['ok' => false, 'message' => tc('Ce n\'est pas un drapeau du challenge.')];
        }

        $handle = trim($handle);
        if ($handle !== '' && (strlen($handle) > 40 || preg_match('/[\x00-\x1f]/', $handle))) {
            return ['ok' => false, 'message' => tc('Pseudo invalide.')];
        }

        $deja = $pdo->prepare('SELECT COUNT(*) FROM flag_captures WHERE code = ?');
        $deja->execute([$trouve]);
        $rang = (int) $deja->fetchColumn() + 1;

        $pdo->prepare(
            'INSERT INTO flag_captures (code, handle, ip_hash, captured_at) VALUES (?, ?, ?, ?)'
        )->execute([$trouve, $handle !== '' ? $handle : null, $ipHash, time()]);

        // Apres l'ecriture et sans condition de succes : un canal muet ne doit pas
        // faire perdre une capture. Meme regle que pour les rapports.
        Notify::drapeauValide($trouve, $rang, $handle);

        return [
            'ok'      => true,
            'code'    => $trouve,
            'premier' => $rang === 1,
            'rang'    => $rang,
            'message' => $rang === 1
                ? tc('Premier sang. Personne n\'avait sorti celui-là avant toi.')
                : tc('Drapeau valide.'),
        ];
    }

    /**
     * L'état public du tableau : par drapeau, le nombre de captures et le
     * premier à l'avoir sorti.
     *
     * Aucune date de capture autre que celle du premier n'est rendue : savoir
     * quand chacun a trouvé dessinerait les habitudes de travail des
     * participants, ce qui ne regarde personne.
     */
    public static function tableau(PDO $pdo): array
    {
        $out = [];
        foreach (self::CODES as $code) {
            $stmt = $pdo->prepare(
                'SELECT handle, captured_at FROM flag_captures WHERE code = ? ORDER BY captured_at ASC LIMIT 1'
            );
            $stmt->execute([$code]);
            $premier = $stmt->fetch() ?: null;

            $n = $pdo->prepare('SELECT COUNT(*) FROM flag_captures WHERE code = ?');
            $n->execute([$code]);

            $out[] = [
                'code'     => $code,
                'captures' => (int) $n->fetchColumn(),
                'premier'  => $premier ? [
                    'handle' => $premier['handle'] ?: null,
                    'quand'  => (int) $premier['captured_at'],
                ] : null,
            ];
        }

        return $out;
    }

    /** Points gagnés par pseudo sur les captures, pour le classement. */
    public static function pointsParPseudo(PDO $pdo): array
    {
        $out = [];
        $rows = $pdo->query(
            "SELECT handle, COUNT(*) AS n FROM flag_captures WHERE handle IS NOT NULL AND handle != '' GROUP BY handle"
        )->fetchAll();
        foreach ($rows as $r) {
            $out[(string) $r['handle']] = (int) $r['n'] * self::POINTS_DRAPEAU;
        }

        return $out;
    }
}
