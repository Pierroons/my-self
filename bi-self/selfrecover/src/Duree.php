<?php

declare(strict_types=1);

namespace Pierroons\SelfRecover;

/**
 * Une durée en secondes, dite comme un message la dit.
 *
 * Les messages de refus annoncent un délai tiré d'un paramètre du constructeur
 * (`fenetreEchecs`, `attenteDepot`, `gelDuree`…) : un intégrateur qui règle la
 * fenêtre voit le message suivre, au lieu d'un « 15 minutes » resté en dur.
 *
 * ⚠️ **La langue se passe en paramètre, parce que cette classe est statique.**
 * Ses unités viennent de `Messages`, jamais d'une chaîne écrite ici : une sortie
 * interpolée dans un message traduit suit ainsi la langue de la phrase qui la
 * porte.
 */
final class Duree
{
    /**
     * La plus grande unité qui tombe juste — « 7 jours », « 1 heure »,
     * « 15 minutes » —, sinon des minutes arrondies au-dessus : un délai annoncé
     * ne doit jamais être plus court que celui qu'on impose.
     *
     * ⚠️ **Sous la minute, les secondes.** Les messages qui annoncent un RESTE
     * (`attenteDepot` moins l'écoulé, l'attente entre deux messages) y passent
     * couramment, et l'arrondi y disait « 1 minute » : jamais plus court que le
     * délai réel, mais jusqu'à soixante fois plus long, ce qui fait patienter
     * pour rien.
     */
    public static function enClair(int $secondes, Langue $langue): string
    {
        foreach ([[86400, 'jour'], [3600, 'heure'], [60, 'minute']] as [$unite, $nom]) {
            if ($secondes >= $unite && $secondes % $unite === 0) {
                $n = intdiv($secondes, $unite);

                return Messages::dire($langue, 'duree.' . $nom . ($n > 1 ? 's' : ''), [$n]);
            }
        }
        if ($secondes < 60) {
            $n = max(1, $secondes);

            return Messages::dire($langue, 'duree.seconde' . ($n > 1 ? 's' : ''), [$n]);
        }
        $n = intdiv($secondes + 59, 60);

        return Messages::dire($langue, 'duree.minute' . ($n > 1 ? 's' : ''), [$n]);
    }
}
