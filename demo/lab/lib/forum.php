<?php
/**
 * MySelf-Lab — logique forum (threads + posts).
 */

declare(strict_types=1);

namespace Pierroons\MySelfLab;

use PDO;

final class Forum
{
    public const CATEGORIES = [
        'general'        => 'Général',
        'libre'          => 'Logiciel libre',
        'rgpd'           => 'RGPD & vie privée',
        'autohebergement'=> 'Auto-hébergement',
        'crypto'         => 'Chiffrement',
    ];

    public static function listThreads(PDO $pdo, ?string $categorie = null, int $limit = 50): array
    {
        $sql = '
            SELECT t.id, t.titre, t.categorie, t.created_at,
                   a.username AS auteur,
                   (SELECT COUNT(*) FROM posts p WHERE p.thread_id = t.id) AS nb_posts,
                   (SELECT MAX(p.created_at) FROM posts p WHERE p.thread_id = t.id) AS last_post_at
              FROM threads t
              JOIN accounts a ON a.id = t.account_id
        ';
        $params = [];
        if ($categorie !== null && isset(self::CATEGORIES[$categorie])) {
            $sql .= ' WHERE t.categorie = ?';
            $params[] = $categorie;
        }
        $sql .= ' ORDER BY COALESCE(last_post_at, t.created_at) DESC LIMIT ?';
        $params[] = $limit;
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function getThread(PDO $pdo, int $threadId): ?array
    {
        $stmt = $pdo->prepare('
            SELECT t.id, t.titre, t.categorie, t.created_at, a.username AS auteur
              FROM threads t JOIN accounts a ON a.id = t.account_id
             WHERE t.id = ?
        ');
        $stmt->execute([$threadId]);
        return $stmt->fetch() ?: null;
    }

    public static function listPosts(PDO $pdo, int $threadId): array
    {
        $stmt = $pdo->prepare('
            SELECT p.id, p.account_id, p.contenu, p.created_at, a.username AS auteur
              FROM posts p JOIN accounts a ON a.id = p.account_id
             WHERE p.thread_id = ?
             ORDER BY p.created_at ASC
        ');
        $stmt->execute([$threadId]);
        return $stmt->fetchAll();
    }

    public static function createThread(PDO $pdo, int $accountId, string $titre, string $categorie, string $premierMessage): array
    {
        $titre = trim($titre);
        $premierMessage = trim($premierMessage);
        if (mb_strlen($titre) < 3 || mb_strlen($titre) > 150) {
            return ['ok' => false, 'error' => 'titre_invalide', 'message' => 'Titre : 3 à 150 caractères.'];
        }
        if (!isset(self::CATEGORIES[$categorie])) {
            $categorie = 'general';
        }
        if (mb_strlen($premierMessage) < 1) {
            return ['ok' => false, 'error' => 'message_vide', 'message' => 'Le premier message ne peut pas être vide.'];
        }
        $now = time();
        $pdo->prepare('INSERT INTO threads (account_id, titre, categorie, created_at) VALUES (?, ?, ?, ?)')
            ->execute([$accountId, $titre, $categorie, $now]);
        $threadId = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO posts (thread_id, account_id, contenu, created_at) VALUES (?, ?, ?, ?)')
            ->execute([$threadId, $accountId, $premierMessage, $now]);
        return ['ok' => true, 'thread_id' => $threadId];
    }

    public static function createPost(PDO $pdo, int $accountId, int $threadId, string $contenu): array
    {
        $contenu = trim($contenu);
        if (mb_strlen($contenu) < 1) {
            return ['ok' => false, 'error' => 'message_vide', 'message' => 'Le message ne peut pas être vide.'];
        }
        if (mb_strlen($contenu) > 5000) {
            return ['ok' => false, 'error' => 'message_trop_long', 'message' => 'Message limité à 5000 caractères.'];
        }
        if (!self::getThread($pdo, $threadId)) {
            return ['ok' => false, 'error' => 'thread_introuvable'];
        }
        $pdo->prepare('INSERT INTO posts (thread_id, account_id, contenu, created_at) VALUES (?, ?, ?, ?)')
            ->execute([$threadId, $accountId, $contenu, time()]);
        return ['ok' => true, 'post_id' => (int) $pdo->lastInsertId()];
    }
}
