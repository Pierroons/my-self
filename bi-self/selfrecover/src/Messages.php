<?php

declare(strict_types=1);

namespace Pierroons\SelfRecover;

/**
 * Ce que cette bibliothèque dit, dans la langue du déploiement.
 *
 * ── Pourquoi un catalogue, et pas un crochet ────────────────────────────────
 *
 * Un module voisin a résolu le même problème par un `setTranslator(?callable)`,
 * et trois de ses défauts sont la raison de ce fichier :
 *
 * — il est **statique**, donc une seule langue par processus : c'était déjà un
 *   réglage de déploiement, mal rangé ;
 * — il reçoit une **fonction**, la seule chose qu'on puisse lâcher sur un arbre
 *   de réponse. Une passphrase qui traverserait un traducteur récursif
 *   ressortirait traduite, et le titulaire noterait un secret qui n'ouvre plus
 *   rien ;
 * — sa clé est **la phrase française elle-même**, donc il rate en silence : une
 *   virgule déplacée dans le code source et le dictionnaire ne répond plus. Son
 *   propre `Motif inconnu de cette plateforme.` manquait au dictionnaire sans
 *   que personne le voie.
 *
 * Ici l'intégrateur passe un **code de langue** au constructeur, jamais une
 * fonction ; les clés sont des identifiants ; et une clé absente **lève** au
 * lieu de rendre la clé ou une chaîne vide. Un message manquant doit faire du
 * bruit à l'écriture, pas se découvrir en production.
 *
 * ⚠️ **Rien ici ne traduit une valeur.** Les paliers du faisceau (`souvent`,
 * `parfois`, `rare`) sont des identifiants et voyagent tels quels — `rare`
 * appartient aussi à la liste de mots anglaise, et le traduire dans un arbre de
 * réponse pourrait corrompre une passphrase. Ce qui se traduit est nommé champ
 * par champ, au point de rendu.
 */
final class Messages
{
    /**
     * Le texte de `$cle` dans `$langue`, les `%s` et `%d` remplis par `$valeurs`.
     *
     * 🔑 **Une clé inconnue lève.** C'est le défaut qu'on ferme : un catalogue
     * qui rendrait la clé, ou la chaîne vide, laisserait un message manquant
     * passer pour un message vide — et personne ne lit un message vide comme un
     * défaut. `sanity_messages.php` interroge ce tableau par réflexion plutôt que
     * de recopier ses clés.
     */
    public static function dire(Langue $langue, string $cle, array $valeurs = []): string
    {
        if (!isset(self::CATALOGUE[$cle])) {
            throw new \InvalidArgumentException(
                "Messages : la clé « $cle » n'est pas au catalogue. Une clé inconnue ne doit pas "
                . 'rendre une chaîne vide : elle se verrait en production et nulle part avant.'
            );
        }

        $entree = self::CATALOGUE[$cle];
        if (!isset($entree[$langue->value])) {
            throw new \InvalidArgumentException(
                "Messages : la clé « $cle » n'a pas de texte en « {$langue->value} »."
            );
        }

        $gabarit = $entree[$langue->value];

        return $valeurs === [] ? $gabarit : vsprintf($gabarit, $valeurs);
    }

    /** Les clés du catalogue, pour les contrôles qui le parcourent. */
    public static function cles(): array
    {
        return array_keys(self::CATALOGUE);
    }

    /** Le texte brut d'une clé, sans remplissage — pour les contrôles. */
    public static function brut(Langue $langue, string $cle): ?string
    {
        return self::CATALOGUE[$cle][$langue->value] ?? null;
    }

    /**
     * ⚠️ **Chaque clé porte les DEUX langues.** Une entrée qui n'en aurait
     * qu'une lèverait au rendu, dans la langue qui manque et seulement là — donc
     * chez l'intégrateur qui l'a choisie, et pas chez nous. Le banc l'interdit.
     *
     * Les unités de durée sont dans ce catalogue : `Duree::enClair()` les y
     * demande, donc un délai interpolé dans un message suit sa langue.
     *
     * 🔑 **Une clé par phrase**, partagée par tous les sites qui la disent : une
     * correction les touche toutes à la fois.
     *
     * @var array<string, array<string, string>>
     */
    private const CATALOGUE = [
        // ── Durées ──────────────────────────────────────────────────────────
        'duree.jour'      => ['fr' => '%d jour',     'en' => '%d day'],
        'duree.jours'     => ['fr' => '%d jours',    'en' => '%d days'],
        'duree.heure'     => ['fr' => '%d heure',    'en' => '%d hour'],
        'duree.heures'    => ['fr' => '%d heures',   'en' => '%d hours'],
        'duree.minute'    => ['fr' => '%d minute',   'en' => '%d minute'],
        'duree.minutes'   => ['fr' => '%d minutes',  'en' => '%d minutes'],
        'duree.seconde'   => ['fr' => '%d seconde',  'en' => '%d second'],
        'duree.secondes'  => ['fr' => '%d secondes', 'en' => '%d seconds'],

        // ── Niveau 1 et niveau 2 : ce que lit le titulaire ──────────────────
        'l1.refus' => [
            'fr' => 'Identifiant ou passphrase incorrect.',
            'en' => 'Incorrect username or passphrase.',
        ],
        'l2.refus' => [
            'fr' => 'Code ou mot mémorisé incorrect.',
            'en' => 'Incorrect code or memorized word.',
        ],
        'frein.attendre' => [
            'fr' => 'Trop de tentatives. Réessaie dans %s.',
            'en' => 'Too many attempts. Try again in %s.',
        ],
        'l2.suspendu' => [
            'fr' => "Trop d'essais manqués : la récupération par code est suspendue pour ce compte. "
                  . 'Récupère ton accès par ta passphrase.',
            'en' => 'Too many failed attempts: code recovery is suspended for this account. '
                  . 'Recover your access with your passphrase.',
        ],
        'acces.rendu' => [
            'fr' => 'Accès rendu. Ton mot de passe et ta passphrase ont été remplacés : note-les, '
                  . 'les anciens ne valent plus rien et ceux-ci ne seront pas réaffichés. '
                  . 'Le mot mémorisé, lui, ne change pas.',
            'en' => 'Access restored. Your password and passphrase have been replaced: write them down, '
                  . 'the old ones are now worthless and these will not be shown again. '
                  . 'Your memorized word is unchanged.',
        ],
        'mot.non_derive' => [
            'fr' => 'Mot mémorisé invalide : la dérivation doit se faire dans le navigateur.',
            'en' => 'Invalid memorized word: derivation must happen in the browser.',
        ],

        // ── La passphrase soumise, et celle qu'on apporte ───────────────────
        //
        // ⚠️ Les clés qui suivent `passphrase.` reprennent les MOTIFS que
        // `validerPassphraseApportee()` rend. Elle reste sans langue — son motif
        // est un identifiant, et le texte se compose là où la langue existe.
        'passphrase.trop_longue_soumise' => [
            'fr' => 'La passphrase soumise dépasse %d octets. Vérifie ce qui a été collé.',
            'en' => 'The submitted passphrase exceeds %d bytes. Check what was pasted.',
        ],
        'passphrase.identique' => [
            'fr' => "La nouvelle passphrase doit différer de celle qu'elle remplace.",
            'en' => 'The new passphrase must differ from the one it replaces.',
        ],
        'passphrase.trop_longue' => [
            'fr' => 'La passphrase apportée est trop longue.',
            'en' => 'The passphrase you brought is too long.',
        ],
        'passphrase.trop_courte' => [
            'fr' => 'Il faut au moins %d mots, séparés par des espaces.',
            'en' => 'At least %d words are needed, separated by spaces.',
        ],
        'passphrase.hors_liste_un' => [
            'fr' => "Le mot n° %s n'est dans aucune des deux listes, anglaise et française : "
                  . "vérifie l'orthographe, sans accent.",
            'en' => 'Word no. %s is in neither list, English nor French: '
                  . 'check the spelling, without accents.',
        ],
        'passphrase.hors_liste_plusieurs' => [
            'fr' => 'Les mots n° %s ne sont dans aucune des deux listes, anglaise et française : '
                  . "vérifie l'orthographe, sans accent.",
            'en' => 'Words no. %s are in neither list, English nor French: '
                  . 'check the spelling, without accents.',
        ],
        'passphrase.mot_repete' => [
            'fr' => 'Un mot revient (n° %s) : relance les dés pour celui-là.',
            'en' => 'A word repeats (no. %s): roll the dice again for that one.',
        ],

        // ── Enrôlement et reconnaissance d'un appareil ──────────────────────
        'appareil.refus' => [
            'fr' => 'Compte ou mot mémorisé incorrect.',
            'en' => 'Incorrect account or memorized word.',
        ],
        'appareil.refus_defi' => [
            'fr' => 'Appareil ou mot mémorisé incorrect.',
            'en' => 'Incorrect device or memorized word.',
        ],
        'appareil.session_requise' => [
            'fr' => 'Enrôler un appareil demande une session ouverte du titulaire. '
                  . 'Qui a perdu son accès passe par la récupération.',
            'en' => 'Enrolling a device requires an open session of the account holder. '
                  . 'Whoever has lost their access goes through recovery.',
        ],
        'appareil.donnees_invalides' => [
            'fr' => "Données d'enrôlement invalides.",
            'en' => 'Invalid enrolment data.',
        ],
        'appareil.cle_invalide' => [
            'fr' => 'Clé publique invalide.',
            'en' => 'Invalid public key.',
        ],
        'appareil.enrole' => [
            'fr' => 'Appareil enrôlé. Sa clé vit dans ce navigateur, chiffrée par ton '
                  . 'mot mémorisé : un autre navigateur, ou des données de site effacées, '
                  . 'demanderont un nouvel enrôlement.',
            'en' => 'Device enrolled. Its key lives in this browser, encrypted by your '
                  . 'memorized word: another browser, or cleared site data, '
                  . 'will require a new enrolment.',
        ],
        'appareil.donnees_incompletes' => [
            'fr' => 'Données incomplètes.',
            'en' => 'Incomplete data.',
        ],
        'appareil.defi_invalide' => [
            'fr' => 'Challenge invalide ou expiré.',
            'en' => 'Invalid or expired challenge.',
        ],
        // ⚠️ Cette entrée porte une CONSIGNE : le mot de passe rendu ne sera pas
        // réaffiché. `sanity_annonce_secrets.php` l'exige dans les deux langues,
        // et remonte jusqu'ici depuis le retour qui la compose.
        'appareil.reconnu' => [
            'fr' => 'Appareil reconnu. Note ton nouveau mot de passe : il ne sera pas réaffiché. '
                  . 'Ta passphrase et tes codes papier, eux, ne changent pas.',
            'en' => 'Device recognized. Write down your new password: it will not be shown again. '
                  . 'Your passphrase and paper codes are unchanged.',
        ],

        // ── Niveau 3 : l'arbitrage ──────────────────────────────────────────
        'compte.inconnu' => [
            'fr' => 'Aucun compte à ce nom.',
            'en' => 'No account by that name.',
        ],
        'l3.empreinte_invalide' => [
            'fr' => "L'empreinte du sésame est absente ou malformée.",
            'en' => 'The claim secret digest is missing or malformed.',
        ],
        'l3.ouverture_refusee' => [
            'fr' => "Aucune procédure n'a pu être ouverte pour ce nom. Si c'est ton compte et "
                  . "qu'une procédure y est déjà en cours ou suspendue, un administrateur peut la "
                  . 'clore ou lever la suspension.',
            'en' => 'No procedure could be opened for that name. If it is your account and a '
                  . 'procedure is already under way or suspended on it, an administrator can '
                  . 'close it or lift the suspension.',
        ],
        'l3.sesame_garde' => [
            'fr' => 'Garde ton sésame : sans lui, personne ne peut reprendre ce litige, '
                  . "toi compris — et il faudra en ouvrir un nouveau.",
            'en' => 'Keep your claim secret: without it nobody can resume this dispute, '
                  . 'you included — and a new one would have to be opened.',
        ],
        'l3.deja_tranche_reponses' => [
            'fr' => "Ce dossier a déjà été tranché : il n'accepte plus de réponses.",
            'en' => 'This dispute has already been decided: it accepts no further answers.',
        ],
        'l3.depot_trop_tot' => [
            'fr' => 'Dépôt trop rapproché du précédent. Réessaie dans %s.',
            'en' => 'Too soon after the previous submission. Try again in %s.',
        ],
        'l3.reponse_invalide' => [
            'fr' => "Une réponse n'est pas du texte valide. Réessaie sans caractère exotique.",
            'en' => 'One answer is not valid text. Try again without unusual characters.',
        ],
        'l3.reponse_trop_longue' => [
            'fr' => 'Une réponse dépasse %d caractères.',
            'en' => 'One answer exceeds %d characters.',
        ],
        'l3.faisceau_echec' => [
            'fr' => "Le dossier n'a pas pu être assemblé. Réessaie.",
            'en' => 'The dispute could not be assembled. Try again.',
        ],
        'l3.transmis' => [
            'fr' => 'Dossier transmis. Un arbitre va le lire et te répondre dans le fil de ce dossier. '
                  . 'Reviens avec ton numéro et ton sésame.',
            'en' => 'Dispute submitted. An arbitrator will read it and answer in this dispute’s thread. '
                  . 'Come back with your number and your claim secret.',
        ],
        'l3.introuvable' => [
            'fr' => 'Dossier introuvable.',
            'en' => 'Dispute not found.',
        ],
        'l3.message_ajoute' => [
            'fr' => 'Message ajouté au fil.',
            'en' => 'Message added to the thread.',
        ],
        'l3.decision_invalide' => [
            'fr' => 'La décision vaut « accepte » ou « refuse ».',
            'en' => 'The decision is either “accept” or “refuse”.',
        ],
        'l3.deja_tranche' => [
            'fr' => 'Ce dossier a déjà été tranché.',
            'en' => 'This dispute has already been decided.',
        ],
        'l3.accepte' => [
            'fr' => 'Litige accepté. Le titulaire repose lui-même ses secrets ; aucun secret '
                  . "n'a été fabriqué ici. Il a %s pour revenir avec son sésame ; "
                  . "passé ce délai, l'accord tombe et la procédure est à refaire.",
            'en' => 'Dispute accepted. The account holder re-posts their own secrets; no secret '
                  . 'was created here. They have %s to come back with their claim secret; '
                  . 'after that the agreement lapses and the procedure must be done again.',
        ],
        'l3.refuse_acharnement' => [
            'fr' => "Dossier refusé. Ce compte porte %d refus sur les %s écoulés : si tu juges qu'il "
                  . "y a acharnement, c'est à toi de geler l'ouverture. Aucun gel n'a été posé.",
            'en' => 'Dispute refused. This account carries %d refusals over the past %s: if you judge '
                  . 'it to be harassment, freezing the opening is yours to decide. No freeze was placed.',
        ],
        'l3.refuse' => [
            'fr' => 'Dossier refusé. Les secrets du compte ne sont pas modifiés.',
            'en' => 'Dispute refused. The account’s secrets are unchanged.',
        ],
        'l3.gele' => [
            'fr' => 'Ouverture gelée %s. Les secrets du compte ne sont pas modifiés, et la levée est immédiate.',
            'en' => 'Opening frozen for %s. The account’s secrets are unchanged, and lifting it is immediate.',
        ],
        'l3.degele' => [
            'fr' => "Gel levé. L'ouverture d'un dossier est de nouveau possible.",
            'en' => 'Freeze lifted. Opening a dispute is possible again.',
        ],
        'l3.aucune_procedure' => [
            'fr' => 'Aucune procédure en cours sur ce compte.',
            'en' => 'No procedure under way on this account.',
        ],
        'l3.close' => [
            'fr' => 'Procédure close. Le titulaire peut en ouvrir une nouvelle ; '
                  . "l'arbitrage sera à refaire.",
            'en' => 'Procedure closed. The account holder may open a new one; '
                  . 'the arbitration will have to be done again.',
        ],
        'l3.non_accepte' => [
            'fr' => "Ce dossier n'a pas été accepté.",
            'en' => 'This dispute was not accepted.',
        ],
        'l3.mot_de_passe_taille' => [
            'fr' => 'Le mot de passe doit faire entre %d et %d caractères.',
            'en' => 'The password must be between %d and %d characters.',
        ],
        'l3.sel_invalide' => [
            'fr' => 'Le sel du compte est absent ou malformé.',
            'en' => 'The account salt is missing or malformed.',
        ],
        'l3.passphrase_egale_mdp' => [
            'fr' => 'La passphrase doit différer du mot de passe.',
            'en' => 'The passphrase must differ from the password.',
        ],
        // ⚠️ Porte une CONSIGNE, dans les deux langues : voir `appareil.reconnu`.
        'l3.compte_repris' => [
            'fr' => 'Compte repris. Note ces codes et cette passphrase : ils ne seront pas réaffichés.%s',
            'en' => 'Account recovered. Write down these codes and this passphrase: '
                  . 'they will not be shown again.%s',
        ],
        'l3.avis_appareils' => [
            'fr' => ' %d appareil(s) enrôlé(s) ont été retirés : réenrôle celui que tu utilises.',
            'en' => ' %d enrolled device(s) were removed: re-enrol the one you are using.',
        ],
        'l3.trop_de_demandes' => [
            'fr' => "Trop de demandes d'arbitrage récemment. Réessaie plus tard.",
            'en' => 'Too many arbitration requests recently. Try again later.',
        ],
        'l3.message_vide' => [
            'fr' => 'Le message est vide.',
            'en' => 'The message is empty.',
        ],
        'l3.message_trop_long' => [
            'fr' => 'Le message dépasse %d caractères.',
            'en' => 'The message exceeds %d characters.',
        ],
        'l3.clos' => [
            'fr' => 'Ce dossier est clos.',
            'en' => 'This dispute is closed.',
        ],
        'l3.tranche_pas_de_message' => [
            'fr' => 'Ce dossier a été tranché : il ne reçoit plus de message.',
            'en' => 'This dispute has been decided: it receives no further messages.',
        ],
        'l3.fil_plein' => [
            'fr' => 'Ce dossier a atteint le nombre de messages que tu peux y écrire. '
                  . "L'arbitre peut encore te répondre ; pour en écrire d'autres, "
                  . 'il faudra un nouveau dossier.',
            'en' => 'This dispute has reached the number of messages you may write in it. '
                  . 'The arbitrator can still answer you; to write more, '
                  . 'a new dispute will be needed.',
        ],
        'l3.message_trop_tot' => [
            'fr' => 'Message trop rapproché du précédent. Réessaie dans %s.',
            'en' => 'Too soon after the previous message. Try again in %s.',
        ],
        'l3.faisceau_avertissement' => [
            'fr' => 'Les réponses ci-dessus sont déclaratives et devinables. Elles orientent la '
                  . "conversation, elles ne prouvent rien. Un déploiement qui n'enregistre pas les "
                  . "connexions rend « indisponible », ce qui n'est pas une divergence.",
            'en' => 'The answers above are declarative and guessable. They steer the '
                  . 'conversation, they prove nothing. A deployment that does not record '
                  . 'sign-ins returns “unavailable”, which is not a divergence.',
        ],
    ];
}
