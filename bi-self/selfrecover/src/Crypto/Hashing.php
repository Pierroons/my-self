<?php

declare(strict_types=1);

namespace Pierroons\SelfRecover\Crypto;

/**
 * Profil de hachage du protocole, et le hash factice qui lui répond.
 *
 * 🔑 **Un seul endroit, appelé partout.** Le profil recopié dans chaque module
 * diverge au premier oubli, et un secret haché plus faiblement que ses voisins
 * ne se voit pas : rien dans la base ne distingue un hash à 4 itérations d'un
 * hash à 2. Le 08/06/2026, une remédiation a posé ce profil dans deux
 * implémentations sur trois ; la troisième l'a reçu le 17/08. Soixante-dix
 * jours, sans qu'aucun test ne rougisse.
 */
final class Hashing
{
    /**
     * Argon2id, profil OWASP 2026 : 64 MiB de mémoire, 4 itérations, 2 fils.
     *
     * ⚠️ Le coût mémoire est le paramètre qui compte : 64 Mo à mobiliser par
     * hachage, ce qui rend l'attaque par GPU coûteuse à paralléliser.
     */
    public const ARGON2 = [
        'memory_cost' => 65536,
        'time_cost'   => 4,
        'threads'     => 2,
    ];

    /**
     * Hash Argon2id factice, à vérifier quand le compte ou le code n'existe pas.
     *
     * 🔑 Sans lui, le chemin « identifiant inconnu » ne calcule rien et répond
     * plus vite — ou, quand un délai fixe le compense, plus lentement mais de
     * façon parfaitement régulière. Dans les deux cas le temps de réponse
     * distingue ce que le message refuse de dire, et trier des comptes existants
     * ne demande qu'une requête et un chronomètre.
     *
     * ⚠️ Il DOIT porter les mêmes paramètres que les vrais hash : c'est leur coût
     * qu'on imite, pas leur existence. Aucun secret réel ne correspond à cette
     * empreinte, et aucune vérification contre elle ne doit réussir — elle n'est
     * là que pour brûler le même temps. `Tests\HashingTest` le vérifie.
     */
    private const DUMMY_HASH =
        '$argon2id$v=19$m=65536,t=4,p=2$bmJrdDNvVlNHYlZKaktvOQ$5rrXLA5A2HcsuydGvvacn80ulh5dLCAuqWjd5t3F+Bw';

    /** Hache un secret destiné à être stocké. */
    public static function hash(string $secret): string
    {
        return password_hash($secret, PASSWORD_ARGON2ID, self::ARGON2);
    }

    /** Vérifie un secret contre son empreinte. */
    public static function verify(string $secret, string $hash): bool
    {
        return password_verify($secret, $hash);
    }

    /**
     * Le hash à vérifier quand il n'y a rien à vérifier.
     *
     * Appel typique, où `$compte` peut être `null` :
     *   Hashing::verify($secret, $compte['recovery_hash'] ?? Hashing::dummyHash())
     */
    public static function dummyHash(): string
    {
        return self::DUMMY_HASH;
    }

    /** Options du profil, pour un appel direct à `password_hash`. */
    public static function argon2Options(): array
    {
        return self::ARGON2;
    }

    /**
     * Le hash factice porte-t-il encore le profil courant ?
     *
     * Un littéral et un tableau écrits à quelques lignes d'écart ne peuvent pas
     * diverger visiblement : une révision du profil OWASP les désaligne sans
     * qu'aucune erreur ne survienne. Cette méthode rend la divergence testable.
     */
    public static function dummyHashSuitProfil(): bool
    {
        $info = password_get_info(self::DUMMY_HASH);
        $o    = $info['options'] ?? [];

        return ($info['algoName'] ?? '') === 'argon2id'
            && ($o['memory_cost'] ?? null) === self::ARGON2['memory_cost']
            && ($o['time_cost']   ?? null) === self::ARGON2['time_cost']
            && ($o['threads']     ?? null) === self::ARGON2['threads'];
    }
}
