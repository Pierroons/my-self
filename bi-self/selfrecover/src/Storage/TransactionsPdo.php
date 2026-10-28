<?php

declare(strict_types=1);

namespace Pierroons\SelfRecover\Storage;

use PDO;
use RuntimeException;

/**
 * Les trois méthodes d'atomicité du contrat, pour un adaptateur qui tient une
 * connexion PDO dans `$this->pdo`. `StockagePdo` l'emploie ; un adaptateur
 * d'intégrateur sur PDO le reprend plutôt que d'en écrire une version à lui.
 *
 * 🔑 **On ne valide et on n'annule que ce qu'on a soi-même ouvert.**
 *
 * PDO ne sait pas imbriquer : `beginTransaction()` sur une transaction déjà
 * ouverte lève. La parade évidente — « ne rien faire si une transaction
 * existe déjà » — traite le symptôme et fabrique un défaut pire : le
 * `commit()` qui suit valide alors la transaction de l'APPELANT, à moitié
 * remplie. Une application qui enveloppe son inscription dans sa propre
 * transaction et appelle la bibliothèque au milieu se retrouve avec un
 * travail partiel rendu durable, puis un « There is no active transaction »
 * au moment de son propre rollback — quand il est déjà trop tard.
 *
 * La réponse est le point de reprise : la transaction extérieure reste celle
 * de l'appelant, et nos écritures s'annulent dans son dos sans la toucher.
 * `SAVEPOINT` est du SQL standard, servi par SQLite, PostgreSQL et MySQL.
 *
 * @property PDO $pdo
 */
trait TransactionsPdo
{
    /** Profondeur d'imbrication. 0 = aucune transaction ouverte par nous. */
    private int $profondeur = 0;

    /** Vrai quand la transaction la plus externe est la NÔTRE, pas celle de l'appelant. */
    private bool $transactionAutonome = false;

    private function nomDuPoint(int $profondeur): string
    {
        // ⚠️ Le nom porte l'INSTANCE, pas seulement la profondeur. Deux adaptateurs
        // construits sur la même connexion démarrent tous deux à zéro : avec un nom
        // tiré de la seule profondeur, le `RELEASE` de l'un libère le point de
        // l'autre, et son `ROLLBACK TO` retombe sur un point étranger — une écriture
        // disparaît alors sans qu'aucune exception ne le dise.
        //
        // Le préfixe, lui, sépare nos points de ceux que l'appelant pose.
        return 'selfrecover_' . spl_object_id($this) . '_' . $profondeur;
    }

    public function commencerTransaction(): void
    {
        if ($this->profondeur === 0 && !$this->pdo->inTransaction()) {
            $this->pdo->beginTransaction();
            $this->transactionAutonome = true;
        } else {
            $this->pdo->exec('SAVEPOINT ' . $this->nomDuPoint($this->profondeur + 1));
        }

        $this->profondeur++;
    }

    public function validerTransaction(): void
    {
        if ($this->profondeur === 0) {
            return;   // rien d'ouvert de notre fait : il n'y a rien à valider
        }

        // 🔑 Un état déclaré ouvert qu'on retrouve fermé est une anomalie, pas un
        // cas nominal : SQLite a pu défaire la transaction seul (disque plein,
        // erreur d'E/S), ou l'appelant l'a annulée sur un chemin d'erreur à lui.
        // Se taire ici ferait rendre « accès rendu » sur une base inchangée.
        if (!$this->pdo->inTransaction()) {
            $this->profondeur = 0;
            $this->transactionAutonome = false;

            throw new RuntimeException(
                'La transaction a disparu avant sa validation : rien n\'a été écrit. '
                . 'Cause probable — un échec du moteur, ou un rollback posé par l\'appelant.'
            );
        }

        if ($this->profondeur > 1) {
            // ⚠️ `RELEASE` ne valide rien par lui-même : il abandonne seulement la
            // possibilité de revenir à ce point. Ce qui décide reste le `commit()`
            // de la transaction la plus externe — ou, si elle appartient à
            // l'appelant, la décision de l'appelant.
            try {
                $this->pdo->exec('RELEASE SAVEPOINT ' . $this->nomDuPoint($this->profondeur));
            } finally {
                // ⚠️ Le décompte se fait même si l'ordre a échoué. Sans ce `finally`,
                // une exception laisse la profondeur figée : l'instance ne retrouve
                // jamais le niveau 1, donc `commit()` n'est plus jamais appelé, et
                // tout ce qu'elle écrit ensuite est défait à la fermeture.
                $this->profondeur--;
            }

            return;
        }

        if ($this->transactionAutonome && $this->pdo->inTransaction()) {
            $this->pdo->commit();
        } elseif ($this->pdo->inTransaction()) {
            // Transaction de l'appelant : il décidera. On libère seulement notre
            // point d'entrée, qui resterait sinon ouvert jusqu'à sa décision.
            $point = $this->nomDuPoint(1);

            try {
                $this->pdo->exec('RELEASE SAVEPOINT ' . $point);
            } catch (\PDOException $e) {
                throw $this->pointDisparu($point, $e);
            }
        }

        $this->profondeur = 0;
        $this->transactionAutonome = false;
    }

    /**
     * 🔑 **Deux composants ne peuvent pas entrelacer leurs transactions sur une
     * même connexion, et aucun code ne peut le rattraper.** `RELEASE` libère le
     * point nommé ET tous ceux posés après lui : si un second adaptateur a ouvert
     * le sien entre-temps, notre validation emporte le sien, et son annulation
     * tombe sur un point qui n'existe plus.
     *
     * C'est la sémantique de SQL, pas un défaut d'ici — une base ne sait pas
     * imbriquer deux fils de transaction indépendants sur une connexion unique.
     * Ce qui est de notre ressort, c'est que l'échec le DISE : `no such savepoint`
     * nu enverrait chercher un bug dans le stockage.
     *
     * La règle qui l'évite : un seul adaptateur par connexion, ou des appels qui
     * ne s'entrelacent pas.
     */
    private function pointDisparu(string $point, \PDOException $cause): RuntimeException
    {
        return new RuntimeException(
            "Le point de reprise « {$point} » n'existe plus. Cause la plus probable : "
            . 'un autre composant a validé ou annulé sa propre transaction sur cette '
            . 'connexion entre-temps, ce qui libère aussi les points posés après le '
            . "sien. Un seul adaptateur par connexion, ou pas d'appels entrelacés.",
            0,
            $cause,
        );
    }

    public function annulerTransaction(): void
    {
        if ($this->profondeur === 0) {
            return;
        }

        if ($this->profondeur > 1) {
            $point = $this->nomDuPoint($this->profondeur);

            try {
                // Les deux ordres comptent : `ROLLBACK TO` défait les écritures mais
                // GARDE le point, qui resterait ouvert jusqu'à la fin de la
                // transaction extérieure.
                $this->pdo->exec('ROLLBACK TO SAVEPOINT ' . $point);
                $this->pdo->exec('RELEASE SAVEPOINT ' . $point);
            } catch (\PDOException $e) {
                throw $this->pointDisparu($point, $e);
            } finally {
                $this->profondeur--;
            }

            return;
        }

        if ($this->transactionAutonome && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        } elseif ($this->pdo->inTransaction()) {
            // 🔑 La transaction est celle de l'appelant et nous devons annuler.
            // On ne la défait pas — ce n'est pas la nôtre — mais on ne peut pas
            // non plus se taire : l'appelant validerait nos écritures ratées avec
            // les siennes. Le point de reprise posé à l'entrée porte exactement
            // nos écritures, donc on revient à lui.
            $point = $this->nomDuPoint(1);

            try {
                $this->pdo->exec('ROLLBACK TO SAVEPOINT ' . $point);
                $this->pdo->exec('RELEASE SAVEPOINT ' . $point);
            } catch (\PDOException $e) {
                $this->profondeur = 0;
                $this->transactionAutonome = false;

                throw $this->pointDisparu($point, $e);
            }
        }

        $this->profondeur = 0;
        $this->transactionAutonome = false;
    }
}
