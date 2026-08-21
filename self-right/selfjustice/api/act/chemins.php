<?php
/**
 * Où vivent les fichiers de SelfAct, et pourquoi pas au même endroit.
 *
 * 🔑 **Deux écrivains ne partagent pas un chemin.** `catalog.json` est produit
 * par une machine, les 1er et 15 ; le code qui l'entoure est écrit à la main et
 * poussé depuis un poste de travail. Tant que les deux visaient
 * `api/act/data/catalog.json`, le dernier qui écrivait gagnait — et le poste de
 * travail porte toujours la copie la plus vieille, puisqu'il ne moissonne rien.
 *
 * Mesuré le 21/08/2026 : la synchronisation du 15 avait écrit 1 890 modèles à
 * 03:30 ; un transfert du dépôt vers la production, le 20 à 21:27, a reposé
 * par-dessus la copie versionnée du 3 août. Douze jours de catalogue perdus
 * sans qu'aucune commande n'ait échoué.
 *
 * La conception avait déjà la réponse ailleurs : les bases SQLite vivent sous
 * `/var/lib/selfjustice/`, où aucun transfert de poste ne va. Le catalogue était
 * l'exception restée dans l'arbre du code. En l'en sortant, il n'y a plus de
 * collision à détecter — il n'y en a plus de possible.
 *
 * `situations.json` reste au dépôt : il est curé à la main, versionné à bon
 * droit, et n'a qu'un seul écrivain.
 */

declare(strict_types=1);

/**
 * Le répertoire d'état de l'instance — ce que la machine produit, par
 * opposition à ce que le dépôt transporte.
 */
function selfact_repertoire_etat(): string {
    $var = getenv('SELFJUSTICE_VAR_DIR');
    return $var !== false && $var !== '' ? rtrim($var, '/') : '/var/lib/selfjustice';
}

/**
 * Le catalogue synchronisé, dans l'ordre : ce que l'exploitant impose, puis
 * l'état de l'instance, puis la copie du dépôt.
 *
 * ⚠️ L'état de l'instance passe AVANT la copie du dépôt, et c'est tout l'objet
 * de ce classement : si un transfert redépose un jour un `data/catalog.json`
 * périmé, il ne sera pas lu. Le défaut redevient inoffensif au lieu d'être
 * seulement détectable.
 *
 * La présence du RÉPERTOIRE tranche, pas celle du fichier : au premier
 * passage la synchronisation n'a encore rien écrit, et un test portant sur le
 * fichier ferait écrire le producteur ici et lire les consommateurs là.
 */
function selfact_chemin_catalogue(): string {
    $impose = getenv('SELFACT_CATALOG');
    if ($impose !== false && $impose !== '') {
        return $impose;
    }
    $etat = selfact_repertoire_etat();
    if (is_dir($etat)) {
        return $etat . '/catalog.json';
    }
    return __DIR__ . '/data/catalog.json';
}
