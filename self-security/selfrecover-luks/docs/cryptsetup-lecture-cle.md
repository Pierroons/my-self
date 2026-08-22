# Comment `cryptsetup` lit une clé selon le chemin emprunté

Note de mesure. **Debian 13, `cryptsetup 2.7.5`, LUKS2**, deux machines
indépendantes. Le banc est versionné : [`../tests/test_lecture_keyfile.sh`](../tests/test_lecture_keyfile.sh).

Ce document existe parce que le dépôt a publié le contraire. Le commit
`9f0af63` — *« la clé était tronquée au démarrage une fois sur huit »* — reposait
sur une lecture erronée de `cryptsetup(8)`, propagée dans le guide d'installation,
dans quatre commentaires de code et dans deux bancs d'essai. La mesure ci-dessous
le dément. Réponse courte pour une recherche sur `0x0A` et `--key-file=-` : §2.

---

## 1. La question

Un keyscript `crypttab` produit une clé sur sa **sortie standard**, et le démarrage
la passe à `cryptsetup` par un **tube**. Si cette clé est un binaire brut de
32 octets, elle a une chance non nulle de contenir l'octet `0x0A` — un saut de
ligne :

> `1 − (255/256)^32` ≈ **11,8 %**

`cryptsetup(8)`, section *Passphrase processing for LUKS*, semble condamner ce cas :

> **From stdin:** LUKS will read passphrases from stdin **up to the first newline
> character** […]
>
> **From key file:** The complete keyfile is read […] **Newline characters do not
> terminate the input.**

D'où la crainte : une installation sur huit s'enrôlerait correctement — l'enrôlement
passe un fichier — puis deviendrait non amorçable, le démarrage passant par stdin.

## 2. La mesure

Clé de 32 octets contenant **un `0x0A`** — le seul octet qui tronque une lecture de
passphrase, un `0x00` la traverse (mesuré à part) — enrôlée par fichier puis relue par
quatre chemins :

| slot | par fichier | tube `--key-file=-` | tube `--key-file=-` + `\n` | tube **sans** `--key-file` |
|---|---|---|---|---|
| **brut** (`0x00` + `0x0A`) | OUVRE | **OUVRE** | ÉCHOUE | ÉCHOUE |
| **hex** (64 caractères) | OUVRE | OUVRE | ÉCHOUE | OUVRE |

**La case décisive est la deuxième de la première ligne : le tube ne tronque pas.**

Témoins négatifs, dans le même banc — sans eux la matrice ne prouverait rien, un
contrôle incapable d'échouer répondant OUVRE à tout :

```
clé fausse par tube                       ECHOUE
troncature simulée (9 premiers octets)    ECHOUE   <- ce que lirait une troncature
```

Ce n'est pas non plus un artefact de `--test-passphrase`, qui n'active pas le
volume : une ouverture device-mapper réelle sur `losetup`, sous root, rend `rc=0` et
crée le nœud dans `/dev/mapper`.

## 3. Pourquoi la page de manuel induit en erreur

Elle décrit **deux entrées distinctes**.

`--key-file=-` **n'est pas** « from stdin ». C'est « **from key file** », dont la
source se trouve être stdin : le flux est lu **en entier**, sauts de ligne compris.
La section « from stdin » vise le cas où **aucun** `--key-file` n'est passé — la
lecture d'une *phrase secrète*. C'est la quatrième colonne du tableau, la seule qui
échoue en brut.

Comportement **amont**, pas un correctif Debian : `tools_get_key()` ne pose
`CRYPT_KEYFILE_STOP_EOL` que lorsqu'il lit une passphrase, jamais pour un keyfile.

## 4. Le chemin d'amorçage réel

Extrait de `/usr/lib/cryptsetup/functions`, sur une machine en service :

```sh
unlock_mapping() {
    local keyfile="${1:--}"
    ...
    /sbin/cryptsetup -T1 ... --key-file="$keyfile" open -- "$CRYPTTAB_SOURCE" "$CRYPTTAB_NAME"
}
```

et son appelant, `scripts/local-top/cryptroot` :

```sh
run_keyscript "$count" | unlock_mapping      # aucun argument -> keyfile="-"
```

`unlock_mapping` est appelée **sans argument** : `keyfile` vaut `-`. Sous Debian, le
démarrage emprunte donc **toujours** `--key-file=-`, y compris pour une passphrase
tapée au clavier. La colonne qui échoue en brut n'est jamais empruntée.

## 5. Le vrai mode de défaillance : le saut de ligne **final**

Troisième colonne du tableau. Il casse les deux formats, hex compris, et **par
fichier autant que par tube** — mesuré aussi.

En mode keyfile tout est lu : un `\n` de trop fait 33 octets au lieu de 32, ou
65 caractères au lieu de 64. La clé enrôlée et la clé présentée diffèrent.

C'est pour cela que le keyscript de référence de Debian écrit :

```sh
printf '%s' "$keys"        # /lib/cryptsetup/scripts/decrypt_derived
```

`printf '%s'`, jamais `echo`. C'est la propriété qu'un test doit garder, et elle vaut
pour hex comme pour brut.

## 6. `--keyfile-size` est une parade, pas un leurre

La page de manuel annonce `--keyfile-size` ignoré — mais, là encore, dans la section
*from stdin*. Pour un **keyfile**, il est honoré, `-` compris. Mesuré, sur une clé
suivie de cinq octets de bruit :

| lecture | `--keyfile-size 32` | résultat |
|---|---|---|
| tube `--key-file=-` | oui | OUVRE |
| fichier `--key-file` | oui | OUVRE |

`crypttab(5)` expose l'option sous le nom `keyfile-size=`, ignorée pour les volumes
plain dm-crypt seulement, et `unlock_mapping()` la transmet
(`CRYPTTAB_OPTION_keyfile_size`). Une ligne `crypttab` qui porte `keyfile-size=64`
survit donc à un `\n` final accidentel :

```
sortie du dérivateur + \n final, sans keyfile-size    ECHOUE
sortie du dérivateur + \n final, --keyfile-size 64    OUVRE
```

`keyfile-size` ne remplace pas `printf '%s'` : il rattrape un `\n`
accidentel, il ne garantit pas que le keyscript produise la bonne clé.

## 7. Ce que cela change pour SelfRecover-LUKS

**Une clé brute est sûre au démarrage sous Debian.** Le risque à 11,8 % ne se
matérialise pas sur ce chemin.

**L'hexadécimal reste néanmoins le format retenu — pour la quatrième colonne.** Une
clé hex survit aussi à une lecture *en tant que passphrase*, ce que la clé brute ne
fait pas. Cela couvre un déverrouillage de secours tapé à la main, un `cryptsetup
open` sans `--key-file=-`, et tout système d'amorçage qui ne serait pas celui de
Debian. C'est un gain de **portabilité**, pas la correction d'un défaut.

**Un changement de format reste incompatible.** Un slot enrôlé en brut n'est pas
ouvert par un keyscript qui produit du hex — indépendamment du motif du passage à
l'hex. La procédure de migration du guide garde donc sa raison d'être ; elle perd son
urgence. Voir [`../INSTALL.md`](../INSTALL.md), section 15.

## 8. Reproduire

```sh
bash self-security/selfrecover-luks/tests/test_lecture_keyfile.sh
```

Le banc ne demande pas root et ne touche aucun volume : il crée un conteneur LUKS2
jetable dans un répertoire temporaire, nettoyé par un `trap` même en cas d'erreur.

## 9. Remarque de méthode

Le diagnostic démenti a été posé depuis un poste où `cryptsetup` était installé, sans
que ce soit vérifié : la page de manuel a tenu lieu de chemin d'exécution. Elle
**ressemblait** au chemin réel, elle n'en était pas un. C'est la règle qui avait servi
à trouver le défaut initial, appliquée un étage trop bas :

> Un contrôle qui n'emprunte pas le chemin qu'il valide ne valide rien.

Corollaire : `dash -n` et `shellcheck` ne voient pas une commande valide qui échoue à
l'exécution.
