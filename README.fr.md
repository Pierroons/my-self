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
| [SelfRecover](./bi-self/selfrecover/) | Qui es-tu ? | **v0.11.0** — bibliothèque + implémentation déployée |
| [SelfRecover-LUKS](./self-security/selfrecover-luks/) | Et si on vole le disque ? | **v0.6.2** — documenté, clé en hexadécimal |
| [SelfDataGuard](./self-security/selfdataguard/) | Comment protéger les données au repos ? | **v0.6.0** — disponible, trois serrures, l'archive au niveau 3, un séquestre lié à son compte |
| [SelfJustice](./self-right/selfjustice/) | Que dit le droit ? | **v0.4.2 bêta** — droit français en vigueur (LEGI), textes UE/CEDH, jurisprudence administrative |
| [SelfAct](./self-right/selfact/) | Comment agir ? | **v0.1.3 bêta** — plus de 1 800 ressources officielles |
| [SelfModerate](./bi-self/selfmoderate/) | Comment se comporte-t-on ? | **v0.4.0** — votants liés, convalescence, motif de vote, ban gradué tracé au journal ; 2 mécanismes pas encore codés |

Ceux qui portent du code de sécurité documentent leur propre modèle de menace ;
celui de SelfModerate reste à écrire. SelfJustice et SelfAct ne détiennent aucun
secret d'utilisateur et n'ont pas encore de modèle de menace.

Chaque ligne mène à du code lisible et exécutable. Pas de lien vers une démo
hébergée : tout s'auto-héberge depuis ce dépôt.

<!-- ecosysteme:selffarm-lite:debut — produit par scripts/check-ecosysteme.sh --ecrire -->
**SelfFarm-Lite**, l'étage applicatif agricole de l'écosystème, vit dans son propre dépôt : [Pierroons/selffarm-lite](https://github.com/Pierroons/selffarm-lite) — **v0.4.11**, publiée.
<!-- ecosysteme:selffarm-lite:fin -->

---

## Ce qui en fait un ensemble

Pas un secret unique qui ouvrirait tout — ce serait le contraire du but. Ce que
les modules partagent, c'est la discipline : des entrées de dérivation
distinctes pour chaque usage, et deux primitives dont chacune tient un rôle qu'on ne lui fait pas quitter.

**HMAC-SHA256 lie et masque.** Le mot mémorisé sert à prouver qu'on le connaît
sans jamais l'envoyer : ce qui transite vaut
`HMAC(mot, matériau | version + sel du compte)`, où l'intégrateur choisit le
matériau. Avec le nom d'hôte, lu dans la page et jamais reçu du réseau, le même
mot donne une empreinte différente sur chaque service, et une page copiée telle
quelle et servie ailleurs ne tire du mot rien d'utilisable. Avec une étiquette fixe,
choisie pour que les comptes survivent à un changement d'adresse, cette
protection disparaît. Une page modifiée, elle, lit le mot quel que soit le
choix. L'empreinte fait 64 caractères que le mot en compte quatre
ou quarante, et deux services qui compareraient leurs bases devraient deviner le
mot pour l'y reconnaître.

**Argon2id à 64 Mio ralentit.** C'est la seule chose que HMAC ne fait pas : il ne
coûte rien à calculer. Partout où un attaquant travaille hors ligne — un disque
volé, un coffre chiffré, une base dumpée — aucun compteur d'essais ne peut
l'arrêter, et le prix d'une tentative est tout ce qui reste.

| Module | Secret | Portée | Ce qui le sépare |
|---|---|---|---|
| SelfRecover | passphrase diceware (L1) | par compte | sel interne Argon2id |
| SelfRecover | mot mémorisé (L2) | par compte | nom d'hôte ou étiquette + sel du compte |
| SelfRecover-LUKS | passphrase diceware | par machine | étiquette `disk` |
| SelfDataGuard | mot de passe + mot mémorisé + passphrase | par utilisateur | contextes `/dataguard` et `/dataguard/passphrase` |

Compromettre l'un n'ouvre pas les autres, avec deux exceptions qu'il faut dire :
SelfRecover et SelfDataGuard peuvent partager le mot mémorisé (L2) et la passphrase
(L1) : la dérivation sépare leurs hachages, pas les secrets eux-mêmes. Aucune des
deux bibliothèques n'importe l'autre : c'est l'intégrateur qui les apparie, comme le
décrit le README de SelfDataGuard, et aucune démo de ce dépôt ne le fait encore. Un hachage volé dans la base d'un côté n'ouvre pas l'autre. Le mot lui-même, s'il est
volé, ouvre seul le coffre SelfDataGuard — côté SelfRecover, il lui faut encore le
*recovery code*. La passphrase volée, elle, ouvre les deux, jusqu'à sa première
utilisation — la tienne ou celle du voleur —, qui la remplace ; une copie de la base prise
avant reste ouverte à l'ancienne.
Un coffre scellé par le seul mot de passe ne survit pas à une récupération
SelfRecover : voir [l'avertissement dans le README de SelfDataGuard](./self-security/selfdataguard/README.fr.md#couplage-avec-selfrecover).
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
avec `sodium`, `pdo_sqlite`, `mbstring` et `openssl`, et Composer pour le forum.

**Voir la base chiffrée en direct** — écran partagé : l'application d'un côté, le
contenu brut de la base de l'autre.

```bash
git clone https://github.com/Pierroons/my-self.git
cd my-self/demo/selfdataguard
./run.sh
```

**Voir les modules travailler ensemble** — un forum où l'inscription passe par
SelfRecover et où les messages privés sont chiffrés au repos avec les primitives
de SelfDataGuard, sous une clé du serveur. Dans un second terminal, depuis le
répertoire où tu as cloné :

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
avant cette date restent disponibles sous leurs termes d'origine. Détail dans
[COPYRIGHT](./COPYRIGHT).

---

## Auteur

Écrit en binôme continu avec un assistant IA. La direction, l'expérience de
terrain et les arbitrages sont humains ; la structure et la relecture sont
partagées.
