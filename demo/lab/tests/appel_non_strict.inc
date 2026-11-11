<?php
/**
 * Un hôte hors du mode strict : ce fichier ne déclare pas `strict_types`, et PHP y
 * convertirait une chaîne numérique, un flottant ou un booléen vers un paramètre
 * `int`. Le moteur doit refuser l'arbitre qui n'est pas un entier, d'où qu'il vienne.
 */

function banNonStrict(PDO $pdo, int $cible, mixed $arbitre): string
{
    try {
        $r = \Pierroons\SelfModerate\Moderate::adminBan($pdo, $cible, $arbitre, motif());

        return $r['ok'] ? 'accepté' : $r['message'];
    } catch (TypeError $e) {
        return str_contains($e->getMessage(), 'identifiant entier') ? 'type' : get_class($e) . ' : ' . $e->getMessage();
    }
}
