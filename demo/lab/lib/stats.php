<?php
/**
 * MySelf-Lab — chiffres publics du lab.
 *
 * 🔑 **Aucune mesure d'audience n'est ajoutée ici.** Pas de compteur de visites,
 * pas de cookie de suivi, pas d'adresse conservée. Tout ce qui suit est agrégé
 * depuis des données déjà collectées pour des raisons fonctionnelles : les
 * tentatives d'authentification alimentent l'anti-brute-force, les rapports
 * viennent du formulaire.
 *
 * C'était la condition pour que ces chiffres existent. Sur un site qui prétend
 * défendre la souveraineté des données, poser un traçage pour afficher un
 * compteur serait la première contradiction qu'un chercheur relèverait.
 */

declare(strict_types=1);

namespace Pierroons\MySelfLab;

use PDO;

final class Stats
{
    /**
     * Les chiffres à afficher, en une seule lecture.
     *
     * Les volumes sont minuscules et SQLite compte vite : pas de cache, qui
     * ajouterait un état à invalider pour un gain nul. À revoir si le lab
     * dépasse quelques milliers de lignes.
     */
    public static function lab(PDO $pdo): array
    {
        // Les comptes de démonstration font partie du décor, pas des visiteurs :
        // les compter gonflerait artificiellement le nombre de chercheurs.
        $chercheurs = (int) $pdo->query(
            "SELECT COUNT(*) FROM accounts WHERE username NOT LIKE 'ctf\_%' ESCAPE '\\'"
        )->fetchColumn();

        $repoussees = (int) $pdo->query(
            'SELECT COUNT(*) FROM login_attempts WHERE success = 0'
        )->fetchColumn();

        $rapports = (int) $pdo->query('SELECT COUNT(*) FROM redteam_reports')->fetchColumn();

        // Un rapport validé sur le mémo = un flag tombé. C'est le seul chiffre
        // qui dise si la promesse centrale tient encore.
        $flags = (int) $pdo->query(
            "SELECT COUNT(*) FROM redteam_reports WHERE status = 'valide' AND target = 'memo'"
        )->fetchColumn();

        // Ancienneté déduite du plus ancien enregistrement plutôt que codée en
        // dur : une date figée finit toujours par mentir après une migration.
        $depuis = $pdo->query('SELECT MIN(created_at) FROM accounts')->fetchColumn();
        $jours = $depuis ? max(1, (int) floor((time() - (int) $depuis) / 86400)) : 1;

        return [
            'chercheurs' => $chercheurs,
            'repoussees' => $repoussees,
            'rapports'   => $rapports,
            'flags'      => $flags,
            'jours'      => $jours,
        ];
    }
}
