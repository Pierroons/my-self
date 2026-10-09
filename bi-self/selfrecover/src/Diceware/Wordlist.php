<?php
/**
 * SelfRecover — Diceware wordlist loader.
 *
 * Deux listes de 7776 mots, une par langue :
 *   - EN : la « large wordlist » de l'EFF (2016), sous licence CC BY ;
 *   - FR : diceware-fr-alt d'Arthur Pons, bâtie sur la méthode de l'EFF, sous licence MIT.
 *
 * Entropie :
 *   - 1 mot  = log2(7776)  = 12.92 bits
 *   - 4 mots = 51.70 bits
 *   - 6 mots = 77.55 bits  (sweet spot recommandé par EFF)
 *   - 8 mots = 103.40 bits (niveau paranoïaque)
 */

declare(strict_types=1);

namespace Pierroons\SelfRecover\Diceware;

use InvalidArgumentException;
use RuntimeException;

final class Wordlist {
    public const LIST_SIZE = 7776;

    /** @var array<string, string[]> */
    private static array $cache = [];

    /** @var array<string, true>|null Les deux listes réunies, indexées par mot. */
    private static ?array $union = null;

    /**
     * @return string[]
     */
    public static function load(string $lang = 'en'): array {
        if (!in_array($lang, ['en', 'fr'], true)) {
            throw new InvalidArgumentException("Langue non supportée: $lang");
        }
        if (isset(self::$cache[$lang])) {
            return self::$cache[$lang];
        }
        // Source unique : bi-self/selfrecover/assets/. Une copie par module divergerait
        // en silence — un chargement raté lève ici, il ne se devine pas.
        $path = __DIR__ . "/../../assets/eff_{$lang}.json";
        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw new RuntimeException("Impossible de charger la liste: $path");
        }
        $words = json_decode($raw, true);
        if (!is_array($words) || count($words) !== self::LIST_SIZE) {
            throw new RuntimeException("Liste invalide: $path");
        }
        self::$cache[$lang] = $words;
        return $words;
    }

    /**
     * Vérifie qu'un mot donné appartient à la liste officielle.
     */
    public static function contains(string $word, string $lang = 'en'): bool {
        return in_array(strtolower(trim($word)), self::load($lang), true);
    }

    /**
     * Génère une passphrase aléatoire de $count mots depuis la liste officielle.
     *
     * @return array{words: string[], entropy_bits: float, lang: string}
     */
    public static function generate(int $count = 6, string $lang = 'en'): array {
        if ($count < 1 || $count > 20) {
            throw new InvalidArgumentException("count hors borne: $count");
        }
        $words = self::load($lang);
        $max = self::LIST_SIZE - 1;
        $picked = [];
        for ($i = 0; $i < $count; $i++) {
            $picked[] = $words[random_int(0, $max)];
        }
        return [
            'words'        => $picked,
            'entropy_bits' => round($count * log(self::LIST_SIZE, 2), 2),
            'lang'         => $lang,
        ];
    }

    /**
     * Le mot appartient-il à l'une des deux listes, tel quel ?
     *
     * Aucune normalisation ici : l'appelant compare la forme qu'il va ranger.
     * Recherche par clé, parce qu'une passphrase apportée est contrôlée avant
     * tout frein : son coût ne doit pas grandir avec ce qu'on lui envoie.
     */
    public static function inAnyList(#[\SensitiveParameter] string $word): bool {
        self::$union ??= array_fill_keys(array_merge(self::load('en'), self::load('fr')), true);

        return isset(self::$union[$word]);
    }
}
