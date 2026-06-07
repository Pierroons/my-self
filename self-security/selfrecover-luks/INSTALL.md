# SelfRecover-LUKS — Guide d'installation

> Déverrouillage d'un serveur entièrement chiffré (LUKS2) par **une seule passphrase de
> récupération**, à distance dès le démarrage, sans cloud ni tiers de confiance.
> Pilier **Self-Security** de l'écosystème MySelf — Licence **AGPL-3.0-or-later**.

Ce guide reproduit une installation **validée sur serveur LNMP Debian 13 Trixie**. Il n'invente aucune
cryptographie : il assemble LUKS2, Argon2id et un SSH d'amorçage (dropbear) en un protocole
cohérent et auto-hébergé.

---

## ⚠️ Avant de commencer — lis ceci

- Tu manipules le **déverrouillage du disque**. Une erreur peut rendre la machine non
  amorçable. **Garde toujours deux filets** : (1) le slot LUKS **natif** (passphrase classique)
  sur chaque volume — on ne le supprime **jamais** ; (2) une **sauvegarde de l'image d'amorçage**
  avant chaque `update-initramfs`.
- **Teste sur une machine sans données critiques** d'abord (ou avec sauvegardes à jour).
- Toutes les commandes sont à lancer en **root**. Adapte les **variables** ci-dessous à ta machine.

```bash
# --- À ADAPTER À TA MACHINE ---
ROOT_DEV=/dev/nvme0n1p3        # volume LUKS racine (/)
DATA_DEV=/dev/nvme0n1p4        # volume LUKS secondaire (optionnel, ex. /data)
NET_MODULE=r8169               # module noyau de ta carte réseau (lspci -k | grep -A2 Ethernet)
SKG=/etc/selfkeyguard          # répertoire d'installation
```

## Prérequis

- Système déjà installé en **chiffrement intégral** (volume racine LUKS2 + initramfs-tools).
- Paquets :

```bash
apt update
apt install -y cryptsetup cryptsetup-initramfs dropbear-initramfs build-essential libargon2-1
# libargon2-1 = runtime. Le binaire se lie au runtime : pas besoin de libargon2-dev.
```

---

## 1. Compiler la fonction de dérivation

`selfrecover_derive.c` reproduit la dérivation : `clé = Argon2id(passphrase, SHA256(sel:label)[:16], t=3, m=64 MiB, p=4, 32 o)`.
Il lit la passphrase sur **stdin** (jamais en argument) et se lie au **runtime** `libargon2.so.1`.

```bash
cc -O2 -Wall -o selfrecover_derive selfrecover_derive.c -l:libargon2.so.1
install -d -m 0755 "$SKG"
install -m 0755 selfrecover_derive "$SKG/selfrecover_derive_c"
```

Vérifie l'unique dépendance :

```bash
ldd "$SKG/selfrecover_derive_c" | grep argon2     # -> libargon2.so.1
```

## 2. Générer le sel de déploiement

Sel **unique** à ta machine, indispensable à toute dérivation (à **sauvegarder hors-site**, cf. §9).

```bash
head -c 16 /dev/urandom | xxd -p > "$SKG/selfrecover_salt"
chmod 0400 "$SKG/selfrecover_salt"
```

## 3. Déployer le keyscript et le hook initramfs

Copie `selfrecover-keyscript.sh` et `initramfs-hook-selfrecover` (fournis dans ce dépôt) :

```bash
install -m 0755 selfrecover-keyscript.sh        "$SKG/selfrecover-keyscript.sh"
install -m 0755 initramfs-hook-selfrecover      /etc/initramfs-tools/hooks/selfrecover
```

> **Piège n°1 — `libgcc_s.so.1`.** Argon2id est multi-thread → le binaire a besoin de
> `libgcc_s.so.1` au démarrage. Cette bibliothèque est chargée **dynamiquement** et reste
> **invisible à `ldd`** : `copy_exec` ne la copie donc pas. Le hook fourni la **force
> explicitement**. Sans elle : `libgcc_s.so.1 must be installed for pthread_exit to work`
> → `Aborted` → `bad password` au démarrage. (Déjà géré dans le hook.)

## 4. Ajouter le slot « récupération » sur chaque volume

`setup-add-selfrecover-slot.sh` ajoute un slot LUKS dont la clé = dérivation (label `disk`)
de la passphrase recover. **Autorisé par une passphrase existante** (le slot natif).
Utilise **la même passphrase recover** pour tous les volumes (c'est le secret unifié).

```bash
SELFRECOVER_SALT="$(cat $SKG/selfrecover_salt)" ./setup-add-selfrecover-slot.sh "$ROOT_DEV"
SELFRECOVER_SALT="$(cat $SKG/selfrecover_salt)" ./setup-add-selfrecover-slot.sh "$DATA_DEV"   # si volume secondaire
```

Vérifie (le nouveau slot apparaît) :

```bash
cryptsetup luksDump "$ROOT_DEV" | grep -E "^\s+[0-9]+: luks2"
```

## 5. Volume racine — keyscript + accès distant (dropbear)

### 5a. Référencer le keyscript dans `/etc/crypttab`

Ajoute `keyscript=` aux options de la ligne du volume racine (garde `x-initrd.attach`) :

```
# /etc/crypttab  (exemple — adapte le nom et l'UUID)
<root_name> UUID=<UUID-ROOT> none luks,discard,x-initrd.attach,keyscript=/etc/selfkeyguard/selfrecover-keyscript.sh
```

### 5b. Accès SSH au démarrage (dropbear)

```bash
# réseau dans l'initramfs
grep -q '^IP=' /etc/initramfs-tools/initramfs.conf \
  && sed -i 's/^IP=.*/IP=dhcp/' /etc/initramfs-tools/initramfs.conf \
  || echo 'IP=dhcp' >> /etc/initramfs-tools/initramfs.conf

# module réseau (adapte NET_MODULE)
grep -qx "$NET_MODULE" /etc/initramfs-tools/modules || echo "$NET_MODULE" >> /etc/initramfs-tools/modules

# clé publique autorisée au boot (mets TA clé)
install -d -m 0755 /etc/dropbear/initramfs
cat ~/.ssh/id_ed25519.pub > /etc/dropbear/initramfs/authorized_keys   # adapte
chmod 0600 /etc/dropbear/initramfs/authorized_keys

# dropbear sur un port dédié
echo 'DROPBEAR_OPTIONS="-p 2222 -s -j -k -I 300"' > /etc/dropbear/initramfs/dropbear.conf
```

> **Piège n°2 — « gave up waiting for root file system device ».** Sans marge de temps, le
> démarrage abandonne avant que tu aies pu te connecter et saisir la passphrase. Ajoute un
> **délai d'attente** :
>
> ```bash
> # /etc/default/grub : ajoute rootdelay=60 à GRUB_CMDLINE_LINUX_DEFAULT, puis update-grub
> sed -i 's/^GRUB_CMDLINE_LINUX_DEFAULT="\(.*\)"$/GRUB_CMDLINE_LINUX_DEFAULT="\1 rootdelay=60"/' /etc/default/grub
> update-grub
> ```

### 5c. (Optionnel) Prompt explicite côté dropbear

Par défaut `cryptroot-unlock` affiche « Please unlock disk ». Pour annoncer la recover :

```bash
sed -i 's|Please unlock disk $CRYPTTAB_NAME: |Passphrase Recover-LUKS ($CRYPTTAB_NAME) : |' \
  /usr/share/cryptsetup/initramfs/bin/cryptroot-unlock
# Cosmétique. Réécrit par une mise à jour du paquet cryptsetup -> à ré-appliquer le cas échéant.
```

## 6. Volumes secondaires — cascade par fichier-clé

> **Piège n°3 — systemd-cryptsetup ignore `keyscript`.** Les volumes **non-racine** sont gérés
> par `systemd-cryptsetup`, qui **ne supporte pas** le champ `keyscript` (seul le volume racine,
> via initramfs-tools, le respecte). Et un volume en `x-initrd.attach` qui échoue **bloque** le
> démarrage (`nofail` ne couvre que le *montage*, pas le *déverrouillage*).
>
> **Solution standard : un fichier-clé stocké sur la racine chiffrée.** Une fois la racine
> ouverte par la recover, le volume secondaire s'ouvre **tout seul** après le pivot.

```bash
# fichier-clé aléatoire, DANS le coffre racine (chiffré)
install -d -m 0700 /etc/keys
dd if=/dev/urandom of=/etc/keys/<data>.key bs=4096 count=1
chmod 0400 /etc/keys/<data>.key

# l'ajouter comme slot du volume secondaire (autorisé par une passphrase existante)
cryptsetup luksAddKey "$DATA_DEV" /etc/keys/<data>.key
```

`/etc/crypttab` — le volume secondaire pointe vers le fichier-clé, **sans** `x-initrd.attach`
(ouvert **après** le pivot, donc non bloquant), avec `nofail` pour le montage :

```
<data_name> UUID=<UUID-DATA> /etc/keys/<data>.key luks,nofail
```

`/etc/fstab` — montage avec `nofail` (le boot continue même si le volume échoue) :

```
/dev/mapper/<data_name> /data ext4 defaults,nofail 0 2
```

**Sécurité** : le fichier-clé est dans le coffre racine chiffré → disque volé = racine chiffrée
= fichier-clé inaccessible. Il n'affaiblit pas la protection.

## 7. Régénérer l'image d'amorçage (avec filet)

```bash
cp -a /boot/initrd.img-$(uname -r) /boot/initrd.img-$(uname -r).bak    # FILET : retour arrière
update-initramfs -u

# vérifie que tout est embarqué :
lsinitramfs /boot/initrd.img-$(uname -r) | grep -E "selfrecover-keyscript|selfrecover_derive_c|libargon2|libgcc|sbin/dropbear"
```

## 8. Test au redémarrage

```bash
reboot
# Depuis un autre poste, dès que le port 2222 répond :
ssh -p 2222 root@<IP-DU-SERVEUR>
cryptroot-unlock        # -> saisis la PASSPHRASE RECOVER
# La racine s'ouvre, la connexion se ferme, le boot continue.
# Le(s) volume(s) secondaire(s) s'ouvre(nt) automatiquement via le fichier-clé.
```

**Filet anti-verrouillage** : si le keyscript échoue, ouvre la racine au slot **natif** sans
passer par lui — dans le shell dropbear :

```bash
cryptsetup open "$ROOT_DEV" <root_name>   # -> passphrase native -> exit -> le boot continue
```

En dernier recours : remets l'image `.bak` (`mv …​.bak …`) depuis un live/secours.

## 9. Récupération après catastrophe — secrets HORS-SITE

Pour tout reconstruire après destruction du matériel, conserve dans un **gestionnaire de mots
de passe** (pas sur la machine) :

| Secret | Sans lui… |
|--------|-----------|
| **Passphrase de récupération** | rien ne s'ouvre |
| **`selfrecover_salt`** | la dérivation est impossible sur matériel neuf |
| **Secrets de sauvegarde** (accès au dépôt + passphrase du dépôt) | les backups sont illisibles |

> Le `selfrecover_salt` est le point le plus oublié : **sans lui hors-site, la passphrase seule
> ne suffit pas** à régénérer les clés.

## 10. Dépannage

| Symptôme | Cause | Remède |
|----------|-------|--------|
| `libgcc_s.so.1 must be installed for pthread_exit` → `Aborted` | libgcc absente de l'initramfs | le hook doit la copier (§3) ; régénère l'initramfs |
| `gave up waiting for root file system device` | délai trop court | `rootdelay=60` (§5b) |
| Prompt natif « Please unlock disk » sur un volume **secondaire** | systemd-cryptsetup ignore le keyscript | passe ce volume en **fichier-clé** (§6) |
| Le boot bloque sur un volume secondaire | `x-initrd.attach` + échec | retire `x-initrd.attach` ; fichier-clé post-pivot (§6) |
| `bad password` alors que la passphrase est juste | binaire/sel/lib manquant dans l'initramfs | vérifie `lsinitramfs` (§7) |

---

## Fichiers du module

| Fichier | Rôle |
|---------|------|
| `selfrecover_derive.c` | dérivation Argon2id (clone C, stdin → clé brute) |
| `selfrecover-keyscript.sh` | keyscript du volume racine (dérive la recover) |
| `initramfs-hook-selfrecover` | embarque binaire + libargon2 + **libgcc** + sel + keyscript |
| `setup-add-selfrecover-slot.sh` | ajoute un slot recover à un volume LUKS |
| `selfrecover_derive.py` | implémentation de référence (Python) pour usage userspace |

*SelfRecover-LUKS — MySelf / Self-Security — AGPL-3.0-or-later.*
