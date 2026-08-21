# MySelf

> 🇬🇧 **[Read this page in English →](./README.md)**

**Récupérer un compte sans email, sans SMS, sans tiers.**

Aujourd'hui, « mot de passe oublié » veut dire « on envoie un lien à ton adresse
email ». Ta boîte mail devient la clé de tous tes comptes. Et si tu la perds, tu
perds tout le reste avec.

MySelf est un ensemble de modules qui explorent l'autre voie : identité, données,
droit et outils métier, sans canal externe dans la boucle. Le code est libre, il
tourne sur ta propre machine, et il est fait pour être lu.

---

## D'où vient l'aléa

Cinq dés, une liste de 7776 mots — soit exactement 6⁵.

| Passphrase | Entropie |
|---|---|
| 1 mot (5 dés) | 12,9 bits |
| 4 mots | 51,7 bits |
| 6 mots | 77,5 bits |

C'est mesurable et non reproductible. Un générateur logiciel produit une suite
calculable à partir de son état interne ; les dés n'ont pas d'état.

La liste anglaise est celle de l'EFF. La liste française est une traduction
communautaire : il n'existe pas de liste officielle en français, celle-ci s'est
imposée par l'usage. Les deux comptent 7776 entrées, donc les chiffres ci-dessus
valent dans les deux langues.
[La méthode papier est documentée pas à pas](./bi-self/selfrecover/tools/entropy-lab/docs/diceware-method-fr.pdf).

---

## Un secret, trois usages cloisonnés

C'est ce qui fait de MySelf un ensemble plutôt qu'une collection. Une seule
passphrase mémorisée sert à trois choses, par un **label** distinct qui change le
sel effectif : deux clés issues du même secret restent indépendantes, et
compromettre l'une n'ouvre pas les autres.

| Label | Usage | Module |
|---|---|---|
| `auth` | retrouver l'accès à un compte | SelfRecover |
| `disk` | ouvrir un slot LUKS2 | SelfRecover-LUKS |
| `data-enc` | chiffrer la donnée applicative | SelfDataGuard |

Une seule saisie au démarrage ouvre le volume racine, puis les volumes
secondaires en cascade.

---

## Les modules

Chaque module répond à une question et se déploie seul. Ceux qui portent du code
de sécurité documentent leur propre modèle de menace : ce qu'ils protègent, et ce
qu'ils ne protègent pas. SelfJustice et SelfAct n'en ont pas — ce sont des bases
de droit tenues à jour, pas des dispositifs de protection.

| Module | Question | État |
|---|---|---|
| [SelfRecover](./bi-self/selfrecover/) | Qui es-tu ? | **v0.4.0** — utilisable, démo locale |
| [SelfRecover-LUKS](./self-security/selfrecover-luks/) | Et si on vole le disque ? | **v0.3.0** — installé et documenté |
| [SelfDataGuard](./self-security/selfdataguard/) | Comment protéger les données au repos ? | **v0.1.0** — en service, 191 tests |
| [SelfJustice](./self-right/selfjustice/) | Que dit le droit ? | **v0.1.0 bêta** |
| [SelfAct](./self-right/selfact/) | Comment agir ? | **v0.1.2** — code, pas encore en ligne |
| [SelfModerate](./bi-self/selfmoderate/) | Comment se comporte-t-on ? | **v0.2.0** — moteur installable, 8 contrôles ; 6 mécanismes du protocole manquent |

Chaque ligne ci-dessus mène à du code lisible et exécutable. Pas de lien vers une
démo hébergée : tout s'auto-héberge depuis ce dépôt.

---

## Sous le capot

Ce que tu trouveras en ouvrant `src/`, et qui vaut mieux qu'un paragraphe de
présentation :

| Fichier | Ce qu'il y a dedans |
|---|---|
| [`Primitives.php`](./self-security/selfdataguard/src/Crypto/Primitives.php) | AAD lié au `userId`, `zeroize()` avec repli si `sodium_memzero` manque, `hash_equals`, classe finale à constructeur privé |
| [`entropy.js`](./bi-self/selfrecover/tools/entropy-lab/engine/entropy.js) | Rejection sampling sur `crypto.getRandomValues` |
| [`Recovery.php`](./bi-self/selfrecover/src/Recovery/Recovery.php) | Rate-limit scopé `username + IP`, empreinte factice contre l'oracle temporel |

Les bibliothèques embarquées (`zxcvbn.js`, `hash-wasm-argon2.js`, liste EFF) sont
les vraies, pas des remplaçantes de démonstration.

---

## Essayer

Tout tourne en local, sans compte à créer. Il te faut PHP 8.1 ou plus récent,
avec `sodium`, `pdo_sqlite` et `mbstring`.

**Voir la base chiffrée en direct** — écran partagé : l'application d'un côté, le
contenu brut de la base de l'autre.

```bash
git clone https://github.com/Pierroons/my-self.git
cd my-self/demo/selfdataguard
./run.sh
```

**Voir les modules travailler ensemble** — un forum où l'inscription passe par
SelfRecover et où les messages privés sont chiffrés par SelfDataGuard.

```bash
cd my-self/demo/lab
composer install
php seed.php
php -S 127.0.0.1:8090 -t public
```

---

## Conceptions publiées

Deux conceptions n'existent qu'à l'état de document.

| Conception | Question | Document |
|---|---|---|
| SelfGuard | Destruction sous contrainte | [whitepaper](./self-security/selfguard/docs/whitepaper.md) |
| SelfKeyGuard | 2FA matérielle | [whitepaper](./self-security/selfkeyguard/docs/whitepaper.md) |

---

## Contribuer

Relecture de code bienvenue. Audits — sécurité, droit, accessibilité — très
bienvenus : signale ce qui cloche, y compris dans cette page.

Le [CONTRIBUTING.md](./CONTRIBUTING.md) du dépôt vaut pour tous les modules ;
SelfRecover a le sien en complément. Traductions bienvenues, forks encouragés.

---

## Licence

[AGPL-3.0-or-later](./LICENSE) — copyleft fort. Tu peux l'utiliser, le modifier,
l'héberger. Si tu bâtis un service dessus et que tu l'ouvres à d'autres, tu
publies tes modifications aussi.

Avant le 19 avril 2026, MySelf était sous licence MIT : les versions publiées
à cette date restent disponibles sous leurs termes d'origine. Détail dans
[COPYRIGHT](./COPYRIGHT).

---

## Auteur

Écrit en binôme continu avec un assistant IA. La direction,
l'expérience de terrain et les arbitrages sont humains ; la structure et la
relecture sont partagées.
