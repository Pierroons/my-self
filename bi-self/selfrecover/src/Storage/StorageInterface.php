<?php

declare(strict_types=1);

namespace Pierroons\SelfRecover\Storage;

use Pierroons\SelfRecover\Device\Appareil;
use Pierroons\SelfRecover\Recovery\Litige;

/**
 * Contrat de persistance du protocole SelfRecover.
 *
 * 🔑 **Pourquoi une interface et non du SQL.** Les consommateurs de cette
 * bibliothèque n'ont pas le même schéma : l'un nomme sa colonne `recovery_hash`,
 * l'autre `pass_hash`. Imposer des tables obligerait à migrer des bases servies,
 * ce qui est un chantier étranger au protocole. Chaque application fournit donc
 * son adaptateur et garde son schéma.
 *
 * L'implémentation est responsable des requêtes préparées, de l'atomicité, et
 * de la création de son schéma.
 *
 * ── L'unité des instants, une fois pour toutes ──────────────────────────────
 *
 * 🔑 **Tout paramètre et tout retour typé `int` qui porte un instant compte les
 * SECONDES depuis le 1er janvier 1970 UTC** — la valeur de `time()`, jamais des
 * millisecondes, jamais une date lisible. Cela vaut pour tous les porteurs de
 * ce contrat : `$quand`, `$depuis`, `$avant`, `$maintenant`, `$expireLe`,
 * `$jusqua`, et les champs d'horodatage de `Litige`.
 *
 * Le dire ici n'est pas une politesse. `int` ne distingue pas les secondes des
 * millisecondes, et surtout il ne distingue pas un instant d'un nombre qui n'en
 * est pas : si la colonne de votre base est restée en `TEXT`, le cast rend le
 * millésime — `(int) '2026-07-12 08:00:00'` vaut `2026`. Le typage est satisfait,
 * aucune ligne n'échoue, et un dossier se retrouve daté de janvier 1970.
 *
 * `Litige` refuse désormais ces valeurs à la construction
 * (`Litige::PLANCHER_EPOQUE`). Les autres paramètres ne sont pas gardés : ils
 * traversent votre adaptateur sans repasser par un objet de valeur. **Vérifiez
 * donc le TYPE DÉCLARÉ de vos colonnes de date**, pas seulement ce qu'elles
 * contiennent aujourd'hui — une colonne `TEXT` qui reçoit des entiers depuis
 * l'origine se comporte correctement jusqu'au jour où quelque chose y écrit une
 * date lisible.
 */
interface StorageInterface
{
    /**
     * Échecs récents attribués à cette IP, pour freiner l'énumération.
     *
     * ⚠️ **Ne comptez que les lignes dont l'étiquette commence par l'un de
     * `$prefixes`.** La table ne nous appartient pas : votre page de connexion y
     * range ses propres échecs, sous l'étiquette que vous avez choisie. Les
     * compter ici ferme la récupération de qui vient d'oublier son mot de passe
     * — il l'a tapé faux cinq fois, il arrive avec la bonne passphrase, et le
     * frein la refuse. Ce n'est pas une attaque, c'est le chemin pour lequel
     * cette bibliothèque existe.
     *
     * `$prefixes` est toujours `Etiquette::PREFIXES`, la liste close de ce que
     * cette bibliothèque écrit. Les portes y sont pesées **ensemble**, parce que
     * ce frein borne le coût Argon2id d'une origine : les séparer laisserait
     * alterner entre elles pour payer deux fois moins.
     */
    public function compterEchecsIp(string $ip, int $depuis, array $prefixes): int;

    /**
     * Trace une tentative, réussie ou non.
     *
     * ⚠️ L'étiquette ne doit pas révéler l'existence du compte : quand rien n'a
     * été trouvé, elle ne reprend jamais ce qui a été soumis.
     *
     * 🔑 **Elle porte un préfixe de `Etiquette::PREFIXES` dès qu'un frein la
     * relit — y compris quand aucun compte n'est en cause.** Une étiquette nulle
     * échappe au filtre de préfixes du frein par origine : en SQL,
     * `NULL LIKE 'l2:%'` vaut `NULL`, donc la ligne sort du compte. Pour ce cas,
     * le préfixe est fixe et sans empreinte : il ne distingue pas deux essais et
     * ne dit l'existence de rien.
     *
     * ⚠️ **En retour, l'implémentation réserve cet espace de noms** : aucun
     * compte ne doit pouvoir s'appeler comme un préfixe, sinon ses propres
     * échecs se mêlent à ceux que le frein compte.
     * `Etiquette::empieteSurUnCompteur()` est là pour ça, à appeler au moment où
     * un nom est choisi.
     */
    public function tracerTentative(?string $etiquette, bool $succes, ?string $ip, int $quand): void;

    /**
     * Empreinte du mot mémorisé pour ce compte, et son identifiant.
     *
     * @return array{id: int, empreinte_mot: string}|null
     */
    public function trouverCompte(string $nomCompte): ?array;

    /** Enrôle ou remplace l'appareil pour ce compte. */
    public function enregistrerAppareil(int $compteId, string $credentialId, string $clePubliqueB64url, int $quand): void;

    public function trouverAppareil(string $credentialId): ?Appareil;

    public function purgerDefisExpires(int $avant): void;

    public function enregistrerDefi(string $defi, string $credentialId, int $quand): void;

    /** Le défi existe-t-il, pour cet appareil, et n'a-t-il pas expiré ? */
    public function defiEnCours(string $defi, string $credentialId, int $depuis): bool;

    /**
     * Retire le défi.
     *
     * ⚠️ Usage unique : appelé dès la vérification faite, succès ou échec. Un
     * défi rejouable annule l'intérêt de le tirer au hasard.
     */
    public function consommerDefi(string $defi): void;

    /** Remplace le mot de passe du compte par cette empreinte. */
    public function remplacerEmpreinteMotDePasse(int $compteId, string $empreinte): void;

    /**
     * Révoque les sessions ouvertes du compte.
     *
     * 🔑 Une récupération qui laisse vivre les sessions existantes ne reprend
     * pas le compte : elle le partage avec qui l'occupait.
     */
    public function revoquerSessions(int $compteId): void;

    /**
     * Retire tous les appareils enrôlés du compte. Rend le nombre retiré.
     *
     * 🔑 **Le niveau 3 s'atteint après avoir tout perdu, et « tout perdu » veut
     * souvent dire « quelqu'un d'autre l'a ».** Un appareil enrôlé ouvre le
     * compte sur une signature seule — `cloreDefi()` ne vérifie pas le mot
     * mémorisé —, donc reposer ses secrets sans retirer les appareils laisse
     * intacte la porte la plus directe, et son titulaire croit avoir refermé.
     *
     * Les niveaux 1 et 2 ne le font pas : qui présente un papier, ou un code
     * ET son mot mémorisé, a prouvé quelque chose ; effacer ses appareils y
     * serait une punition sans motif.
     */
    public function revoquerAppareils(int $compteId): int;

    // ── Récupération de niveau 1 : passphrase diceware ──────────────────────

    /** Échecs récents visant ce compte précis, en plus du compteur par IP. */
    public function compterEchecsCompte(string $nomCompte, int $depuis): int;

    /**
     * Empreinte de la passphrase pour ce compte, et la date où elle a été émise.
     *
     * `emise_le` est facultatif — un déploiement qui ne le tient pas rend `null`,
     * jamais zéro : zéro se lirait « émise le 1er janvier 1970 », et le calcul
     * d'âge afficherait cinquante-six ans à quelqu'un qui vient de s'inscrire.
     *
     * 🔑 **Cette date n'expire rien, et c'est une décision, pas un oubli.** Une
     * passphrase de récupération sert quand tout le reste est perdu, parfois des
     * années après avoir été rangée. La faire expirer tuerait le secours au
     * moment précis où il sert, et son détenteur ne pourrait pas le savoir avant
     * d'essayer — il n'y a pas d'email pour le prévenir, c'est tout le propos du
     * protocole. La date informe : « émise il y a N ans », dans un espace
     * personnel ou devant un arbitre. Ce qui borne le vol d'un papier est
     * ailleurs, et c'est l'usage unique — `parPassphrase()` la consomme.
     *
     * ⚠️ Elle ne sert pas non plus à durcir automatiquement une récupération
     * ancienne. Il faudrait pour cela distinguer un contexte connu d'un contexte
     * inconnu, et derrière un service caché il n'y a aucun contexte : toutes les
     * requêtes partagent une adresse. La bibliothèque refuserait un titulaire
     * légitime sur un signal qui n'existe pas.
     *
     * @return array{id: int, empreinte_passphrase: string, emise_le?: int|null}|null
     */
    public function trouverComptePourPassphrase(string $nomCompte): ?array;

    /**
     * Remplace mot de passe ET passphrase en une fois.
     *
     * 🔑 Une récupération de niveau 1 consomme la passphrase : la laisser
     * valable après usage ferait d'un vol de papier une porte permanente.
     *
     * ⚠️ **Une implémentation qui tient une date d'émission la refait ici.** Une
     * passphrase neuve est émise aujourd'hui : garder l'ancienne date ferait
     * vieillir un papier qui vient d'être imprimé, et l'affichage « émise il y a
     * quatre ans » porterait sur un secret d'hier. Avec `reposerSecrets()`, ce
     * sont les deux seuls endroits du protocole où la passphrase est réécrite ;
     * l'inscription est le troisième, et elle appartient à l'application.
     */
    public function remplacerEmpreintes(int $compteId, string $empreinteMotDePasse, string $empreintePassphrase): void;

    // ── Récupération de niveau 2 : code de récupération + mot mémorisé ──────

    /** Efface les codes du compte — une régénération périme l'ancien papier. */
    public function purgerCodes(int $compteId): void;

    /**
     * Enregistre un code : son index de recherche et son empreinte.
     *
     * ⚠️ `indexRecherche` n'est pas un secret mais ne doit pas être réversible :
     * c'est un HMAC du code sous le sel du déploiement. Il retrouve le compte
     * sans qu'aucun identifiant soit demandé — donc sans champ où éprouver
     * l'existence d'un compte.
     */
    public function enregistrerCode(int $compteId, string $indexRecherche, string $empreinteCode, int $quand): void;

    /**
     * Retrouve un code non consommé par son index de recherche.
     *
     * @return array{code_id: int, empreinte_code: string, deja_utilise: bool, compte_id: int, nom_compte: string, empreinte_mot: string}|null
     */
    public function trouverCodeParIndex(string $indexRecherche): ?array;

    /**
     * Marque le code consommé. Un code de récupération ne sert qu'une fois.
     *
     * ⚠️ **La garde appartient à cette écriture, pas à l'appelant.**
     * `Recovery::parCode()` a lu `deja_utilise` avant les deux Argon2id : entre
     * cette lecture et ici, une seconde requête portant le même code a eu tout le
     * temps de passer. Un `UPDATE` sans condition sur l'état les laisse réussir
     * toutes les deux. **Lève** si l'écriture ne porte pas sur exactement une
     * ligne encore libre, par un `CodeDejaConsomme` — un type à lui, pour que
     * l'appelant ne confonde pas cette course avec une panne de la base.
     */
    public function consommerCode(int $codeId, int $quand): void;

    /** Combien de codes restent utilisables pour ce compte. */
    public function compterCodesRestants(int $compteId): int;

    /**
     * Date d'émission du code le plus récent de ce compte, ou `null` s'il n'en a
     * aucun.
     *
     * Avec `dateDerniereReussite()`, elle donne le point de réarmement du frein
     * du niveau 2 : émettre un lot de codes remet son compteur à zéro, sans rien
     * effacer du journal.
     */
    public function dateDernierCodeEmis(int $compteId): ?int;

    /** Date de la dernière tentative RÉUSSIE sous cette étiquette, ou `null`. */
    public function dateDerniereReussite(string $etiquette): ?int;

    // ── Récupération de niveau 3 : dossier et arbitrage humain ─────────────
    //
    // 🔑 Aucune de ces opérations ne touche au compte. Un refus clôt un dossier,
    // il ne supprime ni ne bannit : un refus dit « ce demandeur ne m'a pas
    // convaincu », pas « ce compte est illégitime ». Si le demandeur était un
    // imposteur, supprimer détruirait le compte de sa victime ; s'il était le
    // titulaire mal jugé, ça punirait un innocent. Et un attaquant incapable de
    // voler un compte pourrait le faire effacer en accumulant des refus —
    // l'échec deviendrait une arme. Ce qui peut se durcir est la PROCÉDURE, par
    // le gel ci-dessous — et c'est un arbitre qui le décide, plus un compteur.

    /** Le dossier portant ce numéro, quel que soit son état. */
    public function trouverLitigeParNumero(string $numero): ?Litige;

    /**
     * Le dossier encore recevable de ce compte, s'il en existe un.
     *
     * ⚠️ **Sa condition d'activité et celle d'`ouvrirLitige()` sont la même
     * règle, écrite deux fois.** L'une lit, l'autre refuse d'insérer ; si elles
     * divergent, un compte peut porter deux dossiers actifs, ou n'en plus
     * ouvrir aucun. `sanity_escalade.php` les confronte sur les deux cas qui
     * les séparent — un dossier courant, et un dossier expiré.
     */
    public function litigeActifDuCompte(int $compteId, int $maintenant): ?Litige;

    /**
     * Ouvre un dossier **si le compte n'en a aucun d'actif**, et dit si elle l'a fait.
     *
     * 🔑 **Le test et l'écriture sont une seule opération.** Lire puis insérer
     * laisse deux requêtes concurrentes passer toutes les deux : la lecture
     * suivante ne garde que le dernier dossier, et le compteur de demandeurs
     * concurrents — le fait le plus utile à l'arbitre — reste à zéro.
     *
     * Un index unique ne peut pas tenir cet invariant à la place : « actif »
     * dépend de l'instant (`expires_at`), et une condition d'index ne se
     * réévalue pas avec l'horloge. C'est donc l'insertion qui porte la
     * condition, en une instruction que le moteur ne peut pas entrelacer.
     *
     * @return bool `false` quand un dossier actif occupait déjà la place ; l'appelant
     *              compte alors la collision et refuse, comme pour un dossier lu.
     */
    public function ouvrirLitige(
        int $compteId,
        string $numero,
        string $empreinteSesame,
        int $quand,
        int $expireLe,
    ): bool;

    /** Une tentative d'ouverture pendant qu'un dossier court : un fait pour l'arbitre. */
    public function compterDemandeurConcurrent(int $litigeId): void;

    /** Range le faisceau et fait passer le dossier en attente de lecture. */
    public function enregistrerFaisceau(int $litigeId, string $faisceauJson, int $quand): void;

    /** Écrit la décision, son auteur et sa date. Ne touche pas au compte. */
    public function trancherLitige(int $litigeId, string $statut, string $par, int $quand): void;

    /**
     * Clôt le dossier et rend son sésame inutilisable.
     *
     * ⚠️ Le sésame est à usage unique : un dossier repris après le
     * ré-enrôlement rouvrirait une porte que le titulaire croit refermée.
     */
    public function cloreLitige(int $litigeId, int $quand): void;

    /**
     * Combien de dossiers REFUSÉS sur ce compte depuis cet instant.
     *
     * ⚠️ On compte les dossiers, pas les dépôts : trois soumissions sur un même
     * dossier restent un seul refus, sinon l'insistance d'un titulaire honnête
     * peserait autant qu'une campagne hostile.
     *
     * 🔑 **Ce chiffre informe, il ne décide pas.** Il arrive à l'arbitre dans le
     * faisceau (`contexte.refus_precedents`) et dans le `gel_suggere` que rend
     * `Escalade::trancher()`. Il compte sous le compte VISÉ, pas sous le
     * demandeur : `poserGel()` dit ce que le câbler à un gel rouvrirait. Un
     * compteur ne sait pas qui insiste ; un arbitre, si.
     */
    public function compterRefusRecents(int $compteId, int $depuis): int;

    /**
     * Gèle l'OUVERTURE de nouveaux dossiers. Le compte reste entier et connectable.
     *
     * ⚠️ Un seul appelant dans cette bibliothèque : `Escalade::geler()`, le geste
     * d'un arbitre. Le câbler à un compteur — d'échecs, de refus, de requêtes —
     * rend le gel déclenchable par qui n'a aucun droit sur le compte, puisque
     * tous ces compteurs se remplissent avec un nom public.
     *
     * ⚠️ **Rangez `$par`, et ne touchez pas à la trace du dégel.** Le geste qui
     * ferme une porte doit nommer son auteur autant que celui qui l'ouvre —
     * `leverGel()` le faisait seul. Et un gel reposé n'efface pas `degele_par` :
     * la décision de l'arbitre qui avait levé le précédent reste lisible, comme
     * ce contrat l'exige pour `leverGel()`. Ce qui dit si l'ouverture est gelée,
     * c'est `gelJusqua()`, jamais la présence d'une trace de dégel.
     */
    public function poserGel(int $compteId, int $jusqua, int $quand, string $par): void;

    /** Jusqu'à quand l'ouverture est gelée, 0 si elle ne l'est pas. */
    public function gelJusqua(int $compteId, int $maintenant): int;

    /**
     * Lève le gel.
     *
     * ⚠️ La trace se garde : qui a dégelé et quand vaut d'être conservé, y
     * compris pour l'arbitre suivant. Effacer la ligne effacerait la décision.
     */
    public function leverGel(int $compteId, string $par, int $quand): void;

    /** `$auteur` vaut `demandeur` ou `admin`. */
    public function ajouterMessageLitige(int $litigeId, string $auteur, string $texte, int $quand): void;

    /** @return list<array{auteur: string, texte: string, ecrit_le: int}> */
    public function messagesDuLitige(int $litigeId): array;

    /** Les dossiers pour la console d'arbitrage, du plus récent au plus ancien. */
    public function listerLitiges(int $limite): array;

    /**
     * Efface les dossiers périmés. Rend le nombre effacé.
     *
     * 🔴 **Appelée par `Escalade::purger()`, que RIEN n'appelle dans cette
     * bibliothèque.** C'est au déploiement de la lancer périodiquement — unité
     * `systemd` prête dans `deploy/bi-self/`, outil dans `bi-self/selfrecover/tools/purger.php`,
     * ou le planificateur de ton choix. Sans ça, les dossiers périmés s'empilent avec leur empreinte de
     * sésame : l'échéance les rend inactifs, elle n'efface rien.
     *
     * ⚠️ Les dossiers acceptés et refusés doivent y SURVIVRE, pour les raisons
     * que l'implémentation de référence porte en commentaire. Leur rétention
     * est donc une décision de déploiement, pas un effet de cette méthode.
     */
    public function purgerLitigesExpires(int $avant): int;

    /**
     * Combien `purgerLitigesExpires($avant)` effacerait. N'efface rien.
     *
     * 🔑 **Elle existe pour qu'un mode d'essai ne détruise pas.** L'outil livré
     * annonce ce qu'une purge ferait avant de la faire ; sans ce compteur, il ne
     * peut le savoir qu'en exécutant la suppression puis en l'annulant — et une
     * transaction annulée ne défait rien sur un moteur qui n'en tient pas, ni ne
     * rend le verrou d'écriture qu'elle a pris le temps de le faire.
     *
     * ⚠️ **La clause est LA MÊME que celle de la purge, pas sa copie.**
     * L'implémentation doit la partager — une constante, une méthode privée, ce
     * qu'elle veut —, jamais la réécrire. Deux clauses parallèles divergent au
     * premier filtre ajouté d'un seul côté, et c'est alors le mode prudent qui
     * annonce un chiffre faux. Le `banc_stockage_pdo` compare les deux sur la
     * même base : ce cas est ce qui tient le design.
     */
    public function compterLitigesExpires(int $avant): int;

    /**
     * Efface les lignes d'échec antérieures à `$avant` dont l'étiquette commence
     * par l'un de `$prefixes`. Rend le nombre effacé.
     *
     * 🔑 **Chaque préfixe a un propriétaire, et c'est lui qui le purge.**
     * `login_attempts` est un espace d'étiquettes partagé : cette bibliothèque y
     * écrit sous les préfixes d'`Etiquette::PREFIXES`, votre page de connexion
     * y écrit sous les siens. Une purge qui viderait la table effacerait vos
     * lignes, et une clé étrangère vers les comptes y est **structurellement
     * impossible** — la colonne ne porte pas un identifiant de compte.
     * `$prefixes` est donc
     * toujours `Etiquette::PREFIXES`, et vos propres lignes restent à vous.
     *
     * 🔴 **Rien dans cette bibliothèque ne l'appelle** : elle n'a pas d'horloge.
     * Sans une tâche planifiée — l'outil `bi-self/selfrecover/tools/purger.php`,
     * l'unité `systemd` de `deploy/bi-self/`, ou votre planificateur — la table
     * ne fait que croître sous son index `(username, attempted_at)`. Les freins
     * ne lisent qu'une fenêtre, donc rien ne rougit en attendant.
     *
     * ⚠️ Effacer une ligne d'échec **réarme ce qu'elle freinait**. Les freins ne
     * lisent qu'une fenêtre — un quart d'heure par défaut : une rétention plus
     * courte rouvrirait la porte que le frein venait de fermer. Gardez-la
     * largement au-dessus de `fenetreEchecs`.
     *
     * 🔑 **Les RÉUSSITES ne se purgent pas**, et ce n'est pas un oubli : la
     * suspension du niveau 2 se réarme sur `dateDerniereReussite()`, qui n'a
     * **aucune fenêtre**. Effacer une réussite vieille de six mois rouvrirait
     * donc une suspension que le titulaire avait levée, sans qu'aucun frein ne
     * change de comportement entre-temps. Elles sont rares — une par
     * récupération réussie — et elles portent un fait qu'aucune autre ligne ne
     * dit. Filtrez sur l'échec, comme le nom de cette méthode le dit.
     */
    public function purgerEchecs(int $avant, array $prefixes): int;

    /**
     * Combien `purgerEchecs($avant, $prefixes)` effacerait. N'efface rien.
     *
     * Même exigence que `compterLitigesExpires()` : la clause se PARTAGE avec la
     * purge. Ici le filtre dépend du nombre de préfixes reçus, donc il se
     * construit à un seul endroit et les deux méthodes l'emploient.
     *
     * ⚠️ Un appel sans préfixe REFUSE, comme la purge : un comptage muet rendrait
     * zéro, et l'opérateur en conclurait qu'il n'y a rien à effacer.
     *
     * @param list<string> $prefixes
     */
    public function compterEchecsPurgeables(int $avant, array $prefixes): int;

    /**
     * Ce que le serveur sait du compte, sans que personne l'ait déclaré.
     *
     * ⚠️ `derniere_connexion` et `nombre_connexions` sont NULLABLES, et c'est
     * délibéré : un déploiement qui ne les enregistre pas doit rendre `null`,
     * jamais zéro. Zéro se lit « jamais connecté » et transforme une réponse
     * honnête en divergence — le dossier d'une personne légitime arrive alors à
     * charge devant l'arbitre.
     *
     * `faits_locaux` est facultatif : la case des faits que seul le déploiement
     * connaît. `Escalade` les rend tels quels sous `contexte.local`, sans les
     * interpréter ni les confronter à une réponse — l'arbitre les lit, la
     * bibliothèque ne sait pas ce qu'ils veulent dire. L'hôte de dérivation en
     * est l'exemple : il dit sous quelle adresse le mot mémorisé du compte a été
     * dérivé, ce qui distingue les générations après un changement d'adresse.
     *
     * ⚠️ **Jamais un secret, jamais une empreinte.** Ce qui entre ici est montré
     * à un humain qui arbitre. Le niveau 3 rassemble des faits parce qu'il n'a
     * plus de secret à vérifier ; y glisser une empreinte remettrait dans la
     * console ce que le protocole tient hors de portée.
     *
     * @return array{id: int, nom_compte: string, cree_le: int, derniere_connexion: int|null, nombre_connexions: int|null, faits_locaux?: array<string, string|int|bool|null>}|null
     */
    public function faitsDuCompte(int $compteId): ?array;

    /**
     * Repose les quatre secrets d'un compte en une fois, après un accord.
     *
     * 🔑 Les trois empreintes et le sel ne sont pas quatre informations mais
     * une seule : le mot mémorisé est dérivé sous ce sel, les séparer laisse une
     * fenêtre où le compte n'est récupérable par rien.
     *
     * ⚠️ **Une date d'émission de passphrase se refait ici aussi** : ce chemin
     * en émet une neuve, exactement comme `remplacerEmpreintes()`.
     *
     * ⚠️ **Une implémentation rafraîchit ici ses marqueurs de déploiement liés
     * au mot dérivé, dans la même requête.** C'est le seul endroit du protocole
     * où l'empreinte du mot mémorisé est réécrite : `remplacerEmpreintes()` ne
     * touche que le mot de passe et la passphrase. Un marqueur laissé en place
     * ne se périme donc jamais ailleurs qu'ici — et il ment à partir d'ici.
     *
     * Le cas concret est l'hôte de dérivation. Le mot mémorisé est dérivé dans
     * le navigateur sous le nom d'hôte servi (`client/sr-derive.js`, mode
     * `hostname`), et le serveur ne reçoit que l'empreinte : elle ne dit pas
     * d'où elle vient. Un déploiement qui range l'hôte à côté doit le réécrire
     * en même temps, sinon la seule colonne capable de distinguer les
     * générations affirme une adresse que l'empreinte ne porte plus.
     *
     * 🔑 **Cette obligation ne passe pas par la signature, et c'est un choix.**
     * L'hôte est une constante de déploiement, pas une valeur de requête : en
     * paramètre, il s'assiérait à côté de cinq arguments qui viennent tous du
     * corps HTTP, et un intégrateur le remplirait tôt ou tard avec
     * `$_SERVER['HTTP_HOST']` — que l'attaquant écrit. Une donnée absente se
     * rattrape ; une donnée fausse fournie par l'attaquant, non. L'adaptateur,
     * lui, tient déjà l'environnement du déploiement, et l'écriture reste dans
     * la transaction que la bibliothèque a ouverte.
     */
    public function reposerSecrets(
        int $compteId,
        string $empreinteMotDePasse,
        string $empreintePassphrase,
        string $empreinteMotDerive,
        string $sel,
    ): void;

    // ── Atomicité ──────────────────────────────────────────────────────────
    //
    // 🔑 Une récupération réussie touche plusieurs lignes : le secret consommé,
    // les empreintes remplacées, les sessions révoquées. Les écrire séparément
    // laisse des fenêtres — celle où le code est déjà marqué utilisé alors que
    // le mot de passe n'a pas changé rend le compte inaccessible par ce code
    // sans qu'il ait servi.
    //
    // ⚠️ Ce qui doit survivre à un échec ne va pas dans la transaction. Le défi
    // du facteur « cet appareil » est consommé avant la vérification de
    // signature, exprès : un défi rejouable annule l'intérêt de le tirer au
    // hasard, échec compris.

    public function commencerTransaction(): void;

    public function validerTransaction(): void;

    public function annulerTransaction(): void;
}
