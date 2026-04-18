<?php
/**
 * SelfRecover — Diceware wordlist loader.
 *
 * Utilise les listes officielles EFF large wordlist (7776 mots, CC-BY 3.0) :
 *   - EN : EFF 2016 officielle
 *   - FR : version FR par ArthurPons (même méthodologie, CC-BY 3.0)
 *
 * Entropie :
 *   - 1 mot  = log2(7776)  = 12.92 bits
 *   - 4 mots = 51.70 bits
 *   - 6 mots = 77.55 bits  (sweet spot recommandé par EFF)
 *   - 8 mots = 103.40 bits (niveau paranoïaque)
 */

declare(strict_types=1);

final class DicewareWordlist {
    public const LIST_SIZE = 7776;
    private const ENTROPY_PER_WORD = 12.9248125; // log2(7776)

    /** @var array<string, string[]> */
    private static array $cache = [];

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
        $path = __DIR__ . "/eff_{$lang}.json";
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
            'entropy_bits' => round($count * self::ENTROPY_PER_WORD, 2),
            'lang'         => $lang,
        ];
    }

    /**
     * Valide une passphrase saisie par l'utilisateur (mode avancé).
     * Retourne la liste normalisée des mots valides, ou throw si invalide.
     *
     * @param string[] $userWords
     * @return array{words: string[], entropy_bits: float, lang: string}
     */
    public static function validateUserPassphrase(array $userWords, string $lang = 'en'): array {
        $count = count($userWords);
        if ($count < 4) {
            throw new InvalidArgumentException("Minimum 4 mots requis");
        }
        $normalized = [];
        foreach ($userWords as $w) {
            $w = strtolower(trim($w));
            if (!self::contains($w, $lang)) {
                throw new InvalidArgumentException("Mot hors liste officielle EFF: $w");
            }
            $normalized[] = $w;
        }
        return [
            'words'        => $normalized,
            'entropy_bits' => round($count * self::ENTROPY_PER_WORD, 2),
            'lang'         => $lang,
        ];
    }
}
