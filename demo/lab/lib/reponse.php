<?php

declare(strict_types=1);

// Vit à part de `bootstrap.php` exprès : les bancs chargent `auth.php` et
// `recover_l3.php` SANS bootstrap, qui ouvre une session et pose des en-têtes.
// Mettre cette fonction là-bas aurait rendu les douze bancs du lab
// infonctionnels — et l'aurait fait savoir par une erreur fatale, pas par un
// refus clair.

/**
 * Ce qu'un refus a le droit de publier, et rien d'autre.
 *
 * 🔑 Les enveloppes du lab rendaient la réponse de la bibliothèque **telle
 * quelle** sur échec (`Auth::recoverByPassphrase`, `RecoverL3::init`…), et les
 * endpoints la passaient à `json_out()`. Toute clé ajoutée en amont sortait
 * donc sur une route publique **sans que personne ne l'ait décidé**. Le cas
 * s'est présenté : un premier jet de SelfRecover 0.11.0 joignait au refus du
 * niveau 1 un compteur d'essais plausibles, qui disait à qui connaît un nom
 * public qu'un tiers était en train de perdre ce compte. Il a été retiré à la
 * source — par l'audit de la bibliothèque, pas par une garde d'ici.
 *
 * Le contrat des refus est court, parce que les pages n'en lisent pas plus :
 * `ok`, `error`, `message`, et le `code` HTTP que les enveloppes ajoutent. Une
 * clé qui devra sortir un jour s'ajoute ici, explicitement, après qu'on a
 * décidé qu'elle peut être publique.
 *
 * 🔑 `motif` en fait partie, et il y est AVANT que le lab en ait besoin. Il
 * accompagne `passphrase_invalide` et dit où une passphrase apportée est
 * fautive — `trop_courte`, `hors_liste`, `mot_repete` —, jamais quel mot : son
 * contrat le garantit, parce que ces messages partent dans des journaux. Le lab
 * ne propose pas encore ce chemin ; l'omettre reviendrait à ce qu'il refuse un
 * jour une passphrase sans dire ce qui cloche, et personne ne se souviendrait
 * d'un filtre écrit ici. C'est le défaut silencieux que cette garde ferme, pris
 * dans l'autre sens.
 *
 * ⚠️ Ne filtre QUE les refus. Un succès porte des secrets à usage unique
 * (passphrase, codes de récupération) dont la liste varie par route : les
 * filtrer ici les perdrait en silence, ce qui est exactement le défaut que le
 * lab vient de corriger sur la reprise de compte.
 *
 * @param array<string, mixed> $r
 * @param list<string>         $enPlus clés supplémentaires, décidées par l'appelant
 * @return array<string, mixed>
 */
function refus_publiable(array $r, array $enPlus = []): array
{
    if (($r['ok'] ?? false) === true) {
        return $r;
    }

    return array_intersect_key(
        $r,
        array_flip(array_merge(['ok', 'error', 'message', 'motif', 'code'], $enPlus))
    );
}
