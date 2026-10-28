<?php

declare(strict_types=1);

namespace Pierroons\SelfRecover\Storage;

use RuntimeException;

/**
 * `remplacerEmpreintes()` n'a plus trouvé, sur le compte, la passphrase que
 * l'appelant avait lue : une autre requête l'a consommée entre-temps.
 *
 * 🔑 Un type à elle, comme `CodeDejaConsomme` : c'est un refus ordinaire, la
 * course entre deux requêtes portant la même passphrase, et l'appelant ne doit
 * pas le confondre avec une panne de la base.
 */
final class PassphraseDejaConsommee extends RuntimeException
{
}
