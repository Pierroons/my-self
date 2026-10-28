<?php

declare(strict_types=1);

namespace Pierroons\SelfRecover\Recovery;

/**
 * Ce qui suit une récupération acceptée — typiquement le coffre de
 * SelfDataGuard, par le pont `Pierroons\SelfRecover\Pont\CoffreSuitLaReprise`.
 *
 * `suivre()` est appelé **dans la transaction de SelfRecover**, après ses
 * écritures et avant sa validation, sur chaque chemin qui change un secret du
 * compte ou l'état de la fenêtre d'après un niveau 3. Sur une même connexion,
 * ce qu'il écrit est donc validé ou annulé avec la récupération elle-même.
 *
 * Ce qu'il rend est rangé sous `suite` dans la réponse de la récupération : un
 * mot que l'intégrateur montre, comme « faite » ou « en_retard ».
 *
 * ⚠️ **Une exception annule la récupération et remonte.** C'est le geste
 * d'une suite qui doit l'empêcher ; pour un défaut de ce qu'elle suit, elle
 * ne lève pas. Refuser une récupération parce que le coffre est en panne
 * fermerait au titulaire sa dernière porte, alors qu'un coffre en retard se
 * rattrape par la serrure que la récupération n'a pas touchée.
 */
interface SuiteDeReprise
{
    public function suivre(Reprise $reprise): string;
}
