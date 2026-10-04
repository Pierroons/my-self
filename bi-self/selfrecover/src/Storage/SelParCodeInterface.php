<?php

declare(strict_types=1);

namespace Pierroons\SelfRecover\Storage;

/**
 * Ce que `Recovery::selDeDerivation()` demande en plus de `StorageInterface`.
 *
 * Facultative : un adaptateur qui ne sert pas la route du sel n'a rien à
 * changer. Celui qui la sert l'implémente à côté de `StorageInterface`.
 */
interface SelParCodeInterface
{
    /**
     * Le sel de dérivation du compte auquel appartient ce code, ou `null`.
     *
     * ⚠️ **Code consommé compris.** Rendre `null` pour un code déjà utilisé
     * ferait dire à la route « ce compte vient d'être récupéré ».
     */
    public function selDuCompteParIndexCode(string $indexRecherche): ?string;
}
