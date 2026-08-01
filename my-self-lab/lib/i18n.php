<?php
/**
 * MySelf-Lab — résolution de langue et traduction.
 *
 * Le lab s'adresse à deux publics : des chercheurs anglophones, majoritaires sur
 * les communautés sécurité, et des écoles françaises. Traduire à la place de
 * n'était donc pas une option — d'où ce dictionnaire par langue, plutôt que des
 * pages dupliquées qui auraient fini par diverger. Sur des règles d'engagement
 * qui promettent un périmètre et une non-poursuite, deux versions contradictoires
 * seraient un vrai problème, pas une coquetterie.
 */

declare(strict_types=1);

const LANGUES = ['fr', 'en'];
const LANGUE_DEFAUT = 'fr';
const LANGUE_COOKIE = 'lab_lang';

/**
 * La langue de la requête, résolue une fois puis mémorisée.
 *
 * Ordre : paramètre explicite, puis choix mémorisé, puis préférence du
 * navigateur, puis français.
 *
 * ⚠️ La valeur vient d'une entrée utilisateur et sert à charger un fichier.
 * Elle est donc comparée à une liste blanche **avant** tout usage : jamais
 * d'interpolation dans un chemin. Sur un serveur qu'on fait attaquer
 * volontairement, une inclusion de fichier arbitraire serait un comble.
 */
function lang(): string
{
    static $langue = null;
    if ($langue !== null) {
        return $langue;
    }

    $demande = $_GET['lang'] ?? null;
    if (is_string($demande) && in_array($demande, LANGUES, true)) {
        // Choix explicite : on le retient un an, sur le seul chemin du site.
        setcookie(LANGUE_COOKIE, $demande, [
            'expires'  => time() + 31536000,
            'path'     => '/',
            'secure'   => true,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        return $langue = $demande;
    }

    $memorise = $_COOKIE[LANGUE_COOKIE] ?? null;
    if (is_string($memorise) && in_array($memorise, LANGUES, true)) {
        return $langue = $memorise;
    }

    return $langue = langueDuNavigateur();
}

/**
 * Première langue acceptée par le navigateur que nous savons servir.
 *
 * `Accept-Language` arrive sous la forme `en-US,en;q=0.9,fr;q=0.8`. On ne
 * cherche pas à trier par qualité : l'ordre d'apparition suffit largement pour
 * un choix binaire, et évite un analyseur là où il n'apporte rien.
 */
function langueDuNavigateur(): string
{
    $entete = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '';
    if (!is_string($entete) || $entete === '') {
        return LANGUE_DEFAUT;
    }

    foreach (explode(',', $entete) as $morceau) {
        $code = strtolower(trim(explode(';', $morceau)[0]));
        $base = explode('-', $code)[0];
        if (in_array($base, LANGUES, true)) {
            return $base;
        }
    }

    return LANGUE_DEFAUT;
}

/**
 * Le dictionnaire de la langue courante, avec le français en filet.
 */
function dictionnaire(): array
{
    static $tables = [];
    $l = lang();

    if (!isset($tables[$l])) {
        $base = __DIR__ . '/../lang/';
        $fr = require $base . 'fr.php';
        // Les clés absentes de l'anglais retombent sur le français : une page à
        // moitié traduite reste lisible, ce qu'une chaîne vide ne serait pas.
        $tables[$l] = ($l === LANGUE_DEFAUT) ? $fr : array_merge($fr, require $base . $l . '.php');
    }

    return $tables[$l];
}

/**
 * Traduit une clé, avec substitution optionnelle façon `sprintf`.
 *
 * Une clé inconnue est retournée telle quelle plutôt que remplacée par du vide :
 * un oubli doit sauter aux yeux à la relecture, pas disparaître silencieusement.
 */
function t(string $cle, ...$args): string
{
    $valeur = dictionnaire()[$cle] ?? $cle;
    return $args ? vsprintf($valeur, $args) : $valeur;
}

/**
 * L'URL courante dans une autre langue, paramètres conservés.
 *
 * Bascule sans perdre sa page ni son contexte — un lecteur au milieu des règles
 * d'engagement doit y rester.
 */
function lang_switch_url(string $cible): string
{
    if (!in_array($cible, LANGUES, true)) {
        $cible = LANGUE_DEFAUT;
    }

    $chemin = strtok($_SERVER['REQUEST_URI'] ?? '/index.php', '?');
    $params = $_GET;
    $params['lang'] = $cible;

    return $chemin . '?' . http_build_query($params);
}

/**
 * Dictionnaire de CONTENU, indexé par le texte français lui-même.
 *
 * Les classes qui produisent du contenu — simulateur d'attaques, console SU,
 * modération — construisent des tableaux de phrases, pas des libellés
 * d'interface. Leur poser une clé par phrase disperserait cent soixante
 * chaînes dans un fichier séparé, loin du code qui les compose et qui seul
 * leur donne un sens.
 *
 * On indexe donc par le texte source, comme le fait gettext. Une phrase sans
 * traduction ressort en français : la page reste lisible, jamais trouée.
 */
function dictionnaireContenu(): array
{
    static $table = null;
    if ($table !== null) {
        return $table;
    }
    if (lang() === LANGUE_DEFAUT) {
        return $table = [];   // le français est la source, rien à traduire
    }
    $f = __DIR__ . '/../lang/contenu-' . lang() . '.php';
    return $table = is_readable($f) ? (require $f) : [];
}

/** Traduit une phrase de contenu. Repli sur le français si elle manque. */
function tc(string $texte): string
{
    return dictionnaireContenu()[$texte] ?? $texte;
}

/**
 * Traduit récursivement toutes les chaînes d'une structure.
 *
 * Évite de parsemer les classes de contenu d'appels à tc() : elles composent
 * leurs tableaux en français, la traduction se fait à la sortie. Les clés ne
 * sont jamais touchées — seules les valeurs affichées le sont.
 */
function tc_deep(array $donnees): array
{
    foreach ($donnees as $cle => $valeur) {
        if (is_string($valeur)) {
            $donnees[$cle] = tc($valeur);
        } elseif (is_array($valeur)) {
            $donnees[$cle] = tc_deep($valeur);
        }
    }
    return $donnees;
}
