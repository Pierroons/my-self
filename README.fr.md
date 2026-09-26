# MySelf

> 🇬🇧 **[Read this page in English →](./README.md)**

**Des outils qui n'ont besoin de personne d'autre que toi.**

Retrouver un compte passe par une adresse email. Connaître ses droits passe par
quelqu'un qui les connaît. Protéger ses données passe par un service qui les
détient. À chaque fois, un tiers est dans la boucle — et ce tiers peut fermer,
se tromper, être piraté, ou simplement ne plus répondre.

MySelf est un ensemble de modules qui explorent l'autre voie, sur quatre
terrains : l'identité, les données, le droit et la vie collective. Le code est
libre, il tourne sur ta propre machine, et il est fait pour être lu.

---

## Les modules

Chaque module répond à une question et se déploie seul. Son README dit le reste :
ce qu'il fait, comment l'installer, et ce qu'il ne protège pas.

| Module | Question | État |
|---|---|---|
| [SelfRecover](./bi-self/selfrecover/) | Qui es-tu ? | **v0.6.0** — bibliothèque + implémentation déployée |
| [SelfRecover-LUKS](./self-security/selfrecover-luks/) | Et si on vole le disque ? | **v0.5.0** — installé et documenté, clé en hexadécimal |
| [SelfDataGuard](./self-security/selfdataguard/) | Comment protéger les données au repos ? | **v0.4.0** — 219 contrôles, XChaCha20-Poly1305 sur tout processeur ; l'instance publique tourne encore en 0.3.0 |
| [SelfJustice](./self-right/selfjustice/) | Que dit le droit ? | **v0.4.0 bêta** — logement, famille, administration et jurisprudence administrative |
| [SelfAct](./self-right/selfact/) | Comment agir ? | **v0.1.2** — en ligne, plus de 1 800 ressources officielles |
| [SelfModerate](./bi-self/selfmoderate/) | Comment se comporte-t-on ? | **v0.3.0** — recoupement des votants liés, convalescence, motif de vote ; 2 mécanismes pas encore codés |

Ceux qui portent du code de sécurité documentent leur propre modèle de menace.
SelfJustice et SelfAct n'en ont pas : ce sont des bases de droit tenues à jour,
pas des dispositifs de protection.

Chaque ligne mène à du code lisible et exécutable. Pas de lien vers une démo
hébergée : tout s'auto-héberge depuis ce dépôt.

---

## Ce qui en fait un ensemble

Pas un secret unique qui ouvrirait tout — ce serait le contraire du but. Ce que
les modules partagent, c'est la discipline : un séparateur de domaine dans le
sel, et deux primitives dont chacune tient un rôle qu'on ne lui fait pas quitter.

**HMAC-SHA256 lie et masque.** Le mot mémorisé sert à prouver qu'on le connaît
sans jamais l'envoyer : ce qui transite vaut
`HMAC(mot, nom d'hôte | version + sel du compte)`. Le nom d'hôte est lu dans la
page, jamais reçu du réseau — le même mot donne donc une empreinte différente sur
chaque service, et une page clonée servie ailleurs ne produit rien d'utilisable.
L'empreinte fait 64 caractères que le mot en compte quatre ou quarante, et sa
sortie est indistinguable d'un aléa : deux services qui compareraient leurs bases
n'y reconnaîtraient ni le même mot, ni la même personne.

**Argon2id à 64 Mio ralentit.** C'est la seule chose que HMAC ne fait pas : il ne
coûte rien à calculer. Partout où un attaquant travaille hors ligne — un disque
volé, un coffre chiffré, une base dumpée — aucun compteur d'essais ne peut
l'arrêter, et le prix d'une tentative est tout ce qui reste.

| Module | Secret | Portée | Ce qui le sépare |
|---|---|---|---|
| SelfRecover | passphrase diceware (L1) | par compte | sel interne Argon2id |
| SelfRecover | mot mémorisé (L2) | par compte | nom d'hôte + sel du compte |
| SelfRecover-LUKS | passphrase diceware | par machine | étiquette `disk` |
| SelfDataGuard | mot de passe + mot mémorisé | par utilisateur | contexte `/dataguard` |

Compromettre l'un n'ouvre pas les autres — non parce qu'une étiquette les
cloisonne, mais parce que ce sont des secrets distincts, dérivés séparément.
Le détail de chaque dérivation est dans le README du module concerné.

### Le sel n'est pas un secret

Il est rangé en clair à côté de l'empreinte, et qui lit la base le voit. Ce n'est
pas un oubli : le sel ne sert pas à cacher, il sert à **séparer**.

Sans lui, deux personnes qui choisissent le même mot produisent la même
empreinte. Trois conséquences, toutes mauvaises : la base révèle qui partage un
secret avec qui ; une table calculée une seule fois sert contre tous les comptes
du service ; et qui casse une empreinte les casse toutes d'un coup.

Avec un sel tiré au hasard par compte — 16 octets, engendrés par ton navigateur —
chaque empreinte redevient un problème séparé. Le travail ne se mutualise plus :
il faut le refaire personne par personne.

**Un sel par service ne suffirait pas.** Il déplacerait la constante au lieu de
saler : tous les comptes du service la partageraient encore. C'est pourquoi le
code l'exige par compte et refuse de dériver sans lui, plutôt que de l'accepter
vide en silence.

Et ce qu'il ne fait pas : il ne rend aucune tentative plus coûteuse. Attaquer un
compte précis reste possible si son secret est faible — c'est Argon2id qui rend
chaque essai cher, et le sel qui empêche d'en faire un seul pour tout le monde.

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

Écrit en binôme continu avec un assistant IA. La direction, l'expérience de
terrain et les arbitrages sont humains ; la structure et la relecture sont
partagées.
