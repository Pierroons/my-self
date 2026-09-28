<?php

declare(strict_types=1);

namespace Pierroons\SelfRecover;

/**
 * Une durée en secondes, dite comme un message la dit.
 *
 * Les messages de refus annoncent un délai tiré d'un paramètre du constructeur
 * (`fenetreEchecs`, `attenteDepot`, `gelDuree`…) : un intégrateur qui règle la
 * fenêtre voit le message suivre, au lieu d'un « 15 minutes » resté en dur.
 */
final class Duree
{
    /**
     * La plus grande unité qui tombe juste — « 7 jours », « 1 heure »,
     * « 15 minutes » —, sinon des minutes arrondies au-dessus : un délai annoncé
     * ne doit jamais être plus court que celui qu'on impose.
     */
    public static function enClair(int $secondes): string
    {
        foreach ([[86400, 'jour', 'jours'], [3600, 'heure', 'heures'], [60, 'minute', 'minutes']] as [$unite, $un, $plusieurs]) {
            if ($secondes >= $unite && $secondes % $unite === 0) {
                $n = intdiv($secondes, $unite);

                return $n . ' ' . ($n > 1 ? $plusieurs : $un);
            }
        }
        $n = max(1, intdiv($secondes + 59, 60));

        return $n . ' ' . ($n > 1 ? 'minutes' : 'minute');
    }
}
