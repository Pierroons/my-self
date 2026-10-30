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
     *
     * ⚠️ **Code d'avant un niveau 3 compris, sous le sel d'AVANT.** Le niveau 3
     * remet un lot neuf au demandeur ; les codes du titulaire passent dans les
     * anciens codes de la fenêtre, et son mot mémorisé reste dérivé sous le sel
     * que la fenêtre garde. Ne chercher que le lot en place rend un faux sel au
     * titulaire qui revient : il dérive son mot sous ce sel, et `parCode()` le
     * refuse. `StockagePdo` montre le repli.
     */
    public function selDuCompteParIndexCode(string $indexRecherche): ?string;
}
