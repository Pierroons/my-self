# FIDO2 dans l'initramfs — banc d'essai

**Ce dossier n'est pas une voie soutenue.** Il conserve un résultat de recherche et
le hook qui l'a produit. Le déverrouillage FIDO2 de la racine, s'il est un jour
livré, passera par une autre voie — expliquée plus bas.

## La question posée

Sur Debian 13 avec `initramfs-tools`, peut-on déverrouiller la racine par une clé
FIDO2 ?

## Ce que ce hook établit

**Oui, c'est faisable, et la liste des pièces est close.** Vérifié le 22/08/2026 sur
`cryptsetup-initramfs 2:2.7.5-2` :

- les scripts Debian (`local-top/cryptroot`, `hooks/cryptroot`, `cryptroot-unlock`)
  **ignorent totalement** les tokens LUKS2 : zéro occurrence de `fido2`, `token` ou
  `tpm2`. Les seules options `crypttab` reconnues sont `initramfs`, `keyscript`,
  `luks` et `tries` ;
- mais `cryptsetup` lui-même charge des **greffons de token externes**, et le greffon
  `libcryptsetup-token-systemd-fido2.so` est déjà présent sur le système (paquet
  `systemd-cryptsetup`) ;
- il manque seulement que l'initramfs contienne les pièces. C'est ce que fait ce hook.

Image de test construite, `lsinitramfs` confirme que tout atterrit aux bons chemins :
le greffon, `libfido2.so.1`, `libcbor.so.0.10`, `libsystemd-shared`, la règle udev
`60-fido-id.rules` avec `fido_id`, et les modules `hid` / `usbhid` / `hid-generic`.

## 🔑 Le piège que ce hook documente

**`libfido2` est chargée par `dlopen`, donc invisible à `ldd`.**

```
$ ldd libcryptsetup-token-systemd-fido2.so | grep -c fido2
0
$ strings libsystemd-shared-257.so | grep '^libfido2'
libfido2.so.1
```

Le greffon ne lie pas libfido2 : c'est `libsystemd-shared` qui la charge à
l'exécution, précisément pour éviter une dépendance dure. Conséquence — `copy_exec`
ne la tire pas, l'initramfs se construit **sans la moindre erreur**, et l'échec
n'apparaît qu'au démarrage, sans message exploitable.

C'est la même classe de piège que `libgcc_s.so.1` pour Argon2id, un cran plus
profond. Le module l'a donc rencontrée deux fois : voir la règle générale au §10
d'[INSTALL.md](../INSTALL.md).

## Ce que ce hook n'établit PAS

Faute de clé physique au moment de l'essai :

- que `cryptsetup` charge effectivement le greffon **depuis l'initramfs** au démarrage ;
- que l'énumération de la clé fonctionne à ce stade (udevd tourne, mais non vérifié) ;
- l'enrôlement lui-même par `systemd-cryptenroll`.

Trois points à tester dès qu'une clé est disponible.

## Pourquoi ce n'est pas la voie retenue

**`keyscript` et les tokens s'excluent sur le même volume.** Quand `keyscript=` est
déclaré, le script Debian récupère la sortie du keyscript et appelle
`cryptsetup open --key-file=-` : ce chemin court-circuite l'activation par token.
Les deux mécanismes ne peuvent donc pas être automatiques ensemble, quelles que
soient les pièces embarquées.

La voie envisagée pour SelfRecover est donc l'inverse : **que le keyscript interroge
la clé lui-même** (`fido2-assert`, extension `hmac-secret`), et retombe sur la
passphrase Argon2id si aucune clé n'est présente. SelfRecover définirait son propre
credential et son propre sel, à côté de `selfrecover_salt` — plutôt que de relire le
format de métadonnées de `systemd-cryptenroll`.

## Limite connue du hook

`LIBDIR` est figé à `/usr/lib/x86_64-linux-gnu`. Le hook ne fonctionne donc que sur
amd64. Pour un usage réel il faudrait le déduire de `dpkg-architecture` ou d'un
`ldconfig -p`.

## Valider sans risquer la machine

Ne régénère jamais l'initramfs de production pour essayer un hook :

```bash
T=$(mktemp -d); cp -a /etc/initramfs-tools "$T/itconf"
install -m 0755 initramfs-hook-fido2 "$T/itconf/hooks/fido2"
mkinitramfs -d "$T/itconf" -o "$T/test-initrd.img" "$(uname -r)"
lsinitramfs "$T/test-initrd.img" | grep -E "fido2|cbor|fido_id"
```

*SelfRecover-LUKS — MySelf / Self-Security — AGPL-3.0-or-later.*
