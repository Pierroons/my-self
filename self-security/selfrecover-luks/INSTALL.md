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
apt install -y cryptsetup cryptsetup-initramfs dropbear-initramfs build-essential \
               libargon2-1 python3-argon2
# libargon2-1 = runtime. Le binaire se lie au runtime : pas besoin de libargon2-dev.
# python3-argon2 = argon2-cffi, dont dépend selfrecover_derive.py (§5). Sans lui,
# l'ajout de slot échoue en ModuleNotFoundError sur une Debian fraîche.
# Sur un poste de travail sans accès distant au démarrage, dropbear-initramfs est inutile.
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

### Vecteur de référence — le C et le Python doivent s'accorder

Deux implémentations dérivent la même clé : le C, qui tourne dans l'initramfs, et le
Python, qui ajoute le slot. Si elles divergent, le slot enrôlé par l'un ne s'ouvrira
jamais par l'autre — et on ne s'en aperçoit qu'au redémarrage.

Valeurs de test publiques, sans rapport avec un déploiement réel :

```bash
printf '0011223344556677\n' > /tmp/sel-test
printf '%s' 'correct horse battery staple' \
  | "$SKG/selfrecover_derive_c" --salt-file /tmp/sel-test --label disk --format hex
printf '%s' 'correct horse battery staple' \
  | python3 selfrecover_derive.py --stdin --salt-file /tmp/sel-test --label disk --format hex
```

Les deux doivent afficher :

```
5c32c300f2af84d83bd4db0dd668692f44b692143cdb2cd909a688c49a60a5d6
```

Une différence signale une divergence d'implémentation — paramètres Argon2, encodage
du sel, ou traitement de la fin de ligne. **Ne va pas plus loin tant qu'elles ne
s'accordent pas.**

## 2. Générer le sel de déploiement

Sel **unique** à ta machine, indispensable à toute dérivation (à **sauvegarder hors-site**, cf. §13).

```bash
# od et non xxd : depuis Debian 13, xxd est un paquet distinct, absent aussi bien
# d'une installation minimale que d'un bureau GNOME. od vient de coreutils.
head -c 16 /dev/urandom | od -An -tx1 | tr -d ' \n' > "$SKG/selfrecover_salt"
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

## 4. Sauvegarder l'en-tête LUKS — **avant** de toucher aux slots

L'étape suivante écrit dans l'en-tête du volume. Une sauvegarde de l'initramfs ou
du `crypttab` ne protège pas de ça : elles couvrent l'**amorçage**, pas la
**corruption de l'en-tête**. En-tête perdu, aucun slot n'ouvre plus rien, quel que
soit l'initrd sur lequel on démarre.

```bash
cryptsetup luksHeaderBackup "$ROOT_DEV" --header-backup-file entete-luks-$(date +%Y%m%d).img
chmod 400 entete-luks-*.img
```

Vérifie que la sauvegarde porte bien les slots attendus :

```bash
cryptsetup luksDump --header entete-luks-*.img | grep -cE "^\s+[0-9]+: luks2"
```

Trois choses à savoir, et elles comptent autant que la commande :

1. **Rangée sur le volume chiffré, cette sauvegarde ne sert à rien** — au moment où
   on en a besoin, on ne peut plus la lire. Support externe, hors de la machine.
2. **Elle contient les slots.** Quiconque l'obtient peut attaquer les passphrases
   hors ligne, **sans disposer du disque**. À protéger comme le disque lui-même :
   jamais dans une archive, jamais dans un dépôt, jamais en pièce jointe.
3. **Restaurer une sauvegarde ressuscite les slots révoqués depuis.** Une sauvegarde
   antérieure à un `luksKillSlot` réintroduit la clé qu'on croyait supprimée.
   Refaire la sauvegarde après chaque changement de slot, et détruire l'ancienne.

---

## 5. Ajouter le slot « récupération » sur chaque volume

`setup-add-selfrecover-slot.sh` ajoute un slot LUKS dont la clé = dérivation (label `disk`)
de la passphrase recover. **Autorisé par une passphrase existante** (le slot natif).
Utilise **la même passphrase recover** pour tous les volumes (c'est le secret unifié).

Pour tirer la passphrase :

```bash
python3 genere-passphrase.py        # 7 mots par défaut ; passe un nombre pour en avoir plus
```

Il affiche les deux formes — avec et sans espaces — et **la longueur en caractères de
chacune**. À lancer dans un vrai terminal : la passphrase s'affiche à l'écran et
n'est écrite nulle part.

> **⚠️ Fixe la convention d'espacement AVANT d'enrôler, et note-la.** La dérivation
> ne normalise rien : `sept mots separes` et `septmotssepares` donnent deux clés
> différentes. Une hésitation sur ce point rend le slot inouvrable **sans que rien ne
> le signale** — la double saisie ne valide que la répétition, pas l'exactitude.
>
> Le déploiement de référence enrôle les mots **concaténés, sans séparateur**. Note
> la **longueur en caractères** à côté de la passphrase sur son support : c'est ce
> qui permet de vérifier, au moment de l'enrôlement, qu'on saisit bien ce qu'on a
> écrit.
>
> **Une nuance qui compte** : le guide dit que la *dérivation* ne normalise rien,
> et c'est exact. Mais les deux chemins de saisie du keyscript, eux, ne se valent
> pas — `/lib/cryptsetup/askpass` transmet la frappe telle quelle, tandis que le
> repli passe par `read`, qui **supprime les blancs de tête et de queue**. Une
> passphrase saisie avec une espace finale ouvrira donc par un chemin et pas par
> l'autre. Raison de plus pour n'avoir aucune espace en bordure, et pour noter la
> longueur en caractères.
>
> Et surtout : **ne normalise jamais les espaces dans la dérivation** pour corriger
> une hésitation. Ce serait le correctif évident, et il romprait tout slot déjà
> enrôlé — y compris ceux des machines qu'on n'a pas sous la main.

```bash
SELFRECOVER_SALT="$(cat $SKG/selfrecover_salt)" ./setup-add-selfrecover-slot.sh "$ROOT_DEV"
SELFRECOVER_SALT="$(cat $SKG/selfrecover_salt)" ./setup-add-selfrecover-slot.sh "$DATA_DEV"   # si volume secondaire
```

Vérifie (le nouveau slot apparaît) :

```bash
cryptsetup luksDump "$ROOT_DEV" | grep -E "^\s+[0-9]+: luks2"
```

## 6. Vérifier le slot **avant** d'en dépendre pour démarrer

Le slot vient d'être créé, mais rien ne prouve encore que la dérivation le
reproduira. Le vérifier maintenant coûte dix secondes ; le découvrir au
redémarrage coûte une session de secours.

Il faut le vérifier **par le chemin qu'empruntera le démarrage**, et pas par un
chemin voisin — voir l'encadré ci-dessous, qui explique pourquoi les deux ne sont
pas équivalents.

```bash
read -rs -p "Passphrase recover : " P; echo        # jamais en argv, jamais dans l'historique

# 1. LE contrôle qui compte : par STDIN, exactement comme au démarrage
printf '%s' "$P" \
  | "$SKG/selfrecover_derive_c" --salt-file "$SKG/selfrecover_salt" --label disk --format raw \
  | cryptsetup open --test-passphrase --key-file=- "$ROOT_DEV" \
  && echo "✅ le slot ouvre le volume PAR LE CHEMIN DU BOOT"

# 2. Par fichier, comme l'enrôlement — utile seulement pour comparer les deux
printf '%s' "$P" \
  | "$SKG/selfrecover_derive_c" --salt-file "$SKG/selfrecover_salt" --label disk --format raw \
  > /run/sr-test.key
cryptsetup open --test-passphrase --key-file /run/sr-test.key "$ROOT_DEV" \
  && echo "   (par fichier : ouvre aussi)"
rm -f /run/sr-test.key; unset P
```

`--test-passphrase` ne monte rien et ne modifie rien : il répond seulement « cette
clé ouvre-t-elle ce volume ». Tant que **le contrôle n° 1** n'a pas répondu oui,
**ne branche pas le keyscript** : l'amorçage dépendrait d'une dérivation non prouvée.

> **⚠️ Si le n° 2 réussit et que le n° 1 échoue, arrête-toi : ne branche pas le
> keyscript.** Ce n'est pas une bizarrerie de la commande, c'est le défaut décrit
> juste en dessous, et il rendrait la machine non amorçable.

### 🔑 Pourquoi stdin et fichier ne sont pas équivalents

`cryptsetup(8)`, section *Passphrase processing for LUKS* :

> **From stdin**: LUKS will read passphrases from stdin **up to the first newline
> character** […]
> **From key file**: The complete keyfile is read […] **Newline characters do not
> terminate the input.**

La clé dérivée fait 32 octets **bruts**. Chaque octet a une chance sur 256 de valoir
`0x0A`, c'est-à-dire un saut de ligne — soit **11,8 % de chance qu'au moins un des 32
en soit un** (mesuré sur 200 000 tirages : 11,73 %).

Or les deux chemins ne lisent pas pareil :

| Moment | Comment cryptsetup reçoit la clé | Ce qu'il lit |
|---|---|---|
| Enrôlement (§5) | `luksAddKey … fichier` | les 32 octets |
| Vérification par fichier | `--key-file fichier` | les 32 octets |
| **Démarrage** | keyscript → stdout → `--key-file=-` | **jusqu'au premier `0x0A`** |

Une clé contenant un `0x0A` s'enrôle donc parfaitement, se vérifie parfaitement par
fichier, et **échoue définitivement au démarrage** — avec un `bad password` qui
oriente vers la mauvaise piste, puisque tout le reste est en place.

C'est pour ça que le contrôle n° 1 passe par un tube et non par un fichier : il doit
reproduire le chemin du démarrage, pas un chemin qui lui ressemble.

Vérifie aussi les paramètres du nouveau slot :

```bash
cryptsetup luksDump "$ROOT_DEV" | grep -A6 "^  1: luks2"
```

`cryptsetup` **recalibre le PBKDF à chaque `luksAddKey`**, selon la mémoire libre du
moment : deux slots du même volume peuvent afficher des `Memory` très différents —
209 MiB et 155 MiB sur le déploiement de référence, sans aucune intention. Ce n'est
pas un défaut, mais retiens que **le déverrouillage exige la mémoire inscrite dans le
slot** : un slot créé sur une machine au repos peut devenir difficile à ouvrir dans
un environnement plus contraint. Ne fige pas `--pbkdf-memory` pour autant : le faire
à la baisse affaiblit le slot, et c'est le geste d'« optimisation » qui coûte le plus.

---

## 7. Deux parcours — choisis le tien

Tout ce qui précède est commun : la dérivation, le sel, le slot recover et sa
vérification ne dépendent pas de la machine. **Ce qui suit diverge.**

| | **Parcours SERVEUR** | **Parcours POSTE DE TRAVAIL** |
|---|---|---|
| Qui tape la passphrase | personne sur place → **SSH d'amorçage** | toi, au clavier |
| Volumes secondaires | fréquents → **cascade par fichier-clé** | souvent aucun |
| Paquet `dropbear-initramfs` | requis | **inutile** |
| Surface exposée au démarrage | un serveur SSH dans l'initramfs | aucune |
| Sections à suivre | **§8 en entier**, puis §9 | **§8a seulement**, puis saute §9 |

Le parcours poste est le plus court : le clavier étant devant la machine, tout
l'appareillage d'accès distant disparaît — et avec lui deux des trois pièges que ce
guide documente.

> **Ne monte pas dropbear « au cas où » sur un poste.** C'est un serveur SSH qui
> écoute avant que le disque soit déverrouillé, avec sa propre clé d'hôte et sa
> propre surface. Sur une machine dont tu tapes la passphrase au clavier, il
> n'apporte rien et ajoute tout.

---

## 8. Volume racine — le keyscript, et l'accès distant si tu en as besoin

### 8a. Référencer le keyscript dans `/etc/crypttab` — **les deux parcours**

Ajoute `keyscript=` aux options de la ligne du volume racine (garde `x-initrd.attach`) :

```
# /etc/crypttab  (exemple — adapte le nom et l'UUID)
<root_name> UUID=<UUID-ROOT> none luks,discard,x-initrd.attach,keyscript=/etc/selfkeyguard/selfrecover-keyscript.sh
```

### 8b. Accès SSH au démarrage (dropbear) — **parcours SERVEUR uniquement**

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

### 8c. (Optionnel) Prompt explicite côté dropbear — **parcours SERVEUR**

Par défaut `cryptroot-unlock` affiche « Please unlock disk ». Pour annoncer la recover :

```bash
sed -i 's|Please unlock disk $CRYPTTAB_NAME: |Passphrase Recover-LUKS ($CRYPTTAB_NAME) : |' \
  /usr/share/cryptsetup/initramfs/bin/cryptroot-unlock
# Cosmétique. Réécrit par une mise à jour du paquet cryptsetup -> à ré-appliquer le cas échéant.
```

## 9. Volumes secondaires — cascade par fichier-clé — **parcours SERVEUR**

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

## 10. Régénérer l'image d'amorçage (avec filet)

```bash
cp -a /boot/initrd.img-$(uname -r) /boot/initrd.img-$(uname -r).bak    # FILET : retour arrière
update-initramfs -u

# vérifie que tout est embarqué :
lsinitramfs /boot/initrd.img-$(uname -r) | grep -E "selfrecover-keyscript|selfrecover_derive_c|libargon2|libgcc|sbin/dropbear"
```

### Valider un hook modifié sans toucher à l'image de production

Si tu as changé le hook, ne régénère pas l'initramfs pour voir : construis une image
de test à part. `mkinitramfs -d` lit une configuration alternative, l'image de
production n'est jamais remplacée, et le risque de rendre la machine non amorçable
est nul.

```bash
T=$(mktemp -d); cp -a /etc/initramfs-tools "$T/itconf"
install -m 0755 mon-hook "$T/itconf/hooks/mon-hook"
mkinitramfs -d "$T/itconf" -o "$T/test-initrd.img" "$(uname -r)"
lsinitramfs "$T/test-initrd.img" | grep -E "ce-que-tu-attends"
```

### ⚠️ Une bibliothèque chargée dynamiquement ne se révèle jamais toute seule

`copy_exec` suit les dépendances qu'`ldd` déclare. Une bibliothèque ouverte à
l'exécution par `dlopen` n'y figure pas : l'initramfs se construit **sans erreur**,
et l'échec n'apparaît qu'au démarrage, sans message exploitable.

Le module a rencontré ce motif deux fois — `libgcc_s.so.1`, tirée par la
bibliothèque Argon2, et `libfido2.so.1`, que `libsystemd-shared` charge par `dlopen`
précisément pour éviter une dépendance dure. Dans les deux cas, `ldd` ne montre rien.

### Le symétrique : vérifier qu'un outil dont on dépend est bien là

La règle ci-dessus dit de copier ce qu'`ldd` ne révèle pas. Son symétrique vaut
autant : **avant de faire dépendre un script d'une commande, vérifier qu'elle existe
là où le script tourne.**

Le repli du keyscript coupe l'écho par `stty -echo 2>/dev/null`. Ce `2>/dev/null` est
commode — et c'est exactement ce qui masquerait l'absence de `stty` : l'écho
resterait actif et la passphrase s'afficherait, sans le moindre message. Vérifié :

```bash
lsinitramfs /boot/initrd.img-$(uname -r) | grep -E "bin/stty|bin/busybox"
```

`stty` est présent sous forme d'applet busybox dans un initramfs Debian standard. Le
comportement est en outre dégradé proprement : hors terminal, `stty` échoue mais
`read` aboutit quand même — on perd la coupure d'écho, pas la saisie.

**Éprouve les chemins de repli en les EXÉCUTANT.** Un repli n'est emprunté que
lorsque le chemin nominal a échoué, c'est-à-dire jamais pendant les essais. Ni
`dash -n` ni `checkbashisms` ne voient une commande valide qui échoue à
l'exécution — c'est ainsi qu'un `read -rs`, syntaxiquement irréprochable, a
neutralisé ce repli sans qu'aucun contrôle ne bronche.

**Règle : toute bibliothèque chargée dynamiquement se copie à la main dans le hook.**
L'analyse des dépendances ne la révélera pas, et aucun test de construction ne la
signalera. Le seul contrôle qui vaut est `lsinitramfs | grep`, sur l'image produite.

---

## 11. Garde-fou — vérifier l'initramfs après chaque mise à jour de noyau

Le coût réel de ce module n'est pas cryptographique : c'est le **nombre de pièces
dans le chemin d'amorçage**. Chaque pièce ajoutée est une pièce qui peut manquer
après une régénération d'initramfs — et le manque ne se voit qu'au redémarrage, sur
une machine devenue non amorçable.

```bash
install -m 0755 initramfs-post-update-verifie-selfrecover \
        /etc/initramfs/post-update.d/zz-verifie-selfrecover
```

**`/etc/initramfs/post-update.d/` et non `/etc/kernel/postinst.d/`** : `update-initramfs`
est déclenché par une mise à jour de noyau, mais aussi par `cryptsetup-initramfs`,
`busybox`, `initramfs-tools`, ou à la main. Seul `post-update.d` couvre tous ces
chemins — et c'est justement une mise à jour de `cryptsetup-initramfs` qui casserait
ce module sans qu'aucun noyau ne change.

Il vérifie les six pièces (binaire, sel, keyscript, `libargon2`, `libgcc_s`,
`cryptsetup`) **et compare le sel embarqué à celui du disque** : un sel présent mais
périmé dérive une autre clé, et un contrôle de simple présence afficherait
« complet » sur une machine qui ne redémarrera pas. Il **échoue bruyamment avec la
marche à suivre**, et reste inerte tant que `keyscript=` n'est pas dans
`/etc/crypttab` : tu peux l'installer avant même d'avoir branché le module.

Éprouve-le dans les deux sens — un garde-fou qu'on n'a jamais vu refuser ne prouve
rien :

```bash
/etc/kernel/postinst.d/zz-verifie-selfrecover "$(uname -r)"   # doit rendre 0 et une ligne verte
```

Puis recommence sur un initrd volontairement incomplet : il doit rendre 1 et lister
ce qui manque.

---

## 12. Test au redémarrage

### Parcours POSTE DE TRAVAIL

```bash
reboot
```

À l'écran : `Passphrase Recover-LUKS (<root_name>) :`. Saisis la passphrase recover —
**dans la forme exacte que tu as enrôlée** (§5). La racine s'ouvre, le démarrage
continue.

Rien d'autre à faire : pas de fenêtre réseau à attendre, pas de volume secondaire à
surveiller.

### Parcours SERVEUR

```bash
reboot
# Depuis un autre poste, dès que le port 2222 répond :
ssh -p 2222 root@<IP-DU-SERVEUR>
cryptroot-unlock        # -> saisis la PASSPHRASE RECOVER
# La racine s'ouvre, la connexion se ferme, le boot continue.
# Le(s) volume(s) secondaire(s) s'ouvre(nt) automatiquement via le fichier-clé.
```

### Filet anti-verrouillage — les deux parcours

Si le keyscript échoue, ouvre la racine au slot **natif** sans passer par lui : au
clavier sur un poste, dans le shell dropbear sur un serveur.

```bash
cryptsetup open "$ROOT_DEV" <root_name>   # -> passphrase native -> exit -> le boot continue
```

En dernier recours : remets l'image `.bak` (`mv …​.bak …`) depuis un live/secours.

> **Prépare ce filet avant d'en avoir besoin.** Sur un poste, une entrée de secours
> dans le chargeur d'amorçage, pointant sur l'initrd `.bak`, évite d'aller chercher
> une clé USB live à froid. Elle fige en revanche une version de noyau : à retirer
> après la première mise à jour, sinon elle devient trompeuse plutôt qu'utile.

## 13. Récupération après catastrophe — secrets HORS-SITE

Pour tout reconstruire après destruction du matériel, conserve dans un **gestionnaire de mots
de passe** (pas sur la machine) :

| Secret | Sans lui… |
|--------|-----------|
| **Passphrase de récupération** | rien ne s'ouvre |
| **`selfrecover_salt`** | la dérivation est impossible sur matériel neuf |
| **Secrets de sauvegarde** (accès au dépôt + passphrase du dépôt) | les backups sont illisibles |

> Le `selfrecover_salt` est le point le plus oublié : **sans lui hors-site, la passphrase seule
> ne suffit pas** à régénérer les clés.

## 14. Dépannage

| Symptôme | Cause | Remède |
|----------|-------|--------|
| `libgcc_s.so.1 must be installed for pthread_exit` → `Aborted` | libgcc absente de l'initramfs | le hook doit la copier (§3) ; régénère l'initramfs |
| `gave up waiting for root file system device` | délai trop court | `rootdelay=60` (§8b) |
| Prompt natif « Please unlock disk » sur un volume **secondaire** | systemd-cryptsetup ignore le keyscript | passe ce volume en **fichier-clé** (§9) |
| Le boot bloque sur un volume secondaire | `x-initrd.attach` + échec | retire `x-initrd.attach` ; fichier-clé post-pivot (§9) |
| `bad password` alors que la passphrase est juste | binaire/sel/lib manquant dans l'initramfs | vérifie `lsinitramfs` (§10) |
| `bad password`, mais le §6 répondait ✅ **par fichier** | clé brute contenant un `0x0A`, tronquée sur stdin | refais le §6 par **stdin** ; s'il échoue, migre le slot en hex (§6) |

---

## 15. Migrer un déploiement antérieur — passage de la clé brute à l'hexadécimal

**Concerne les installations faites avant ce changement**, où le keyscript produisait
`--format raw`. Le §6 explique le défaut : une clé brute contenant un `0x0A` est
tronquée au démarrage parce que `cryptsetup` lit stdin jusqu'au premier saut de
ligne, alors qu'il lit un fichier en entier.

### Faut-il migrer ?

**Si la machine démarre aujourd'hui, sa clé ne contient pas de `0x0A`** — sinon elle
ne démarrerait pas. Il n'y a donc rien d'urgent. Ce qui change :

- une **réinstallation** ou un **nouveau volume** avec l'ancien format a 11,8 % de
  chance de produire une machine non amorçable ;
- le keyscript livré désormais produit de l'**hexadécimal** : le remplacer sur une
  machine dont le slot est en brut rendra ce slot inouvrable.

> **⚠️ Ne remplace jamais le keyscript sans migrer le slot.** Le nouveau keyscript et
> l'ancien slot ne se parlent pas. C'est le seul geste de cette page qui peut rendre
> une machine en service non amorçable.

### La migration, dans cet ordre

Le slot natif reste ouvrable pendant toute l'opération : c'est lui le filet.

```bash
# 0. Sauvegarde de l'en-tête AVANT (§4) — elle contient les slots
cryptsetup luksHeaderBackup "$ROOT_DEV" --header-backup-file entete-avant-migration.img

# 1. Ajouter un SECOND slot recover, en hexadécimal, sans toucher à l'ancien
SELFRECOVER_SALT="$(cat $SKG/selfrecover_salt)" ./setup-add-selfrecover-slot.sh "$ROOT_DEV"

# 2. Le prouver PAR LE CHEMIN DU BOOT (§6) — c'est l'étape qui décide
read -rs -p "Passphrase recover : " P; echo
printf '%s' "$P" \
  | "$SKG/selfrecover_derive_c" --salt-file "$SKG/selfrecover_salt" --label disk --format hex \
  | cryptsetup open --test-passphrase --key-file=- "$ROOT_DEV" \
  && echo "✅ le slot hex ouvre le volume par stdin"

# 3. Seulement alors : déployer le nouveau keyscript et régénérer
install -m 0755 selfrecover-keyscript.sh "$SKG/selfrecover-keyscript.sh"
update-initramfs -u

# 4. REDÉMARRER et vérifier que le déverrouillage fonctionne. Ne passe pas à
#    l'étape 5 avant d'avoir redémarré avec succès.

# 5. Une fois le redémarrage réussi : retirer l'ancien slot brut
cryptsetup luksDump "$ROOT_DEV" | grep -E "^\s+[0-9]+: luks2"   # repère son numéro
cryptsetup luksKillSlot "$ROOT_DEV" <numéro-de-l-ancien-slot>
unset P
```

**L'étape 4 n'est pas facultative.** Retirer l'ancien slot avant d'avoir redémarré,
c'est supprimer le filet avant de savoir si le nouveau tient.

Et refais la sauvegarde d'en-tête **après** la migration : celle de l'étape 0
ressusciterait l'ancien slot brut si on la restaurait (§4).

---

## Fichiers du module

| Fichier | Rôle |
|---------|------|
| `selfrecover_derive.c` | dérivation Argon2id (clone C, stdin → clé brute) |
| `selfrecover-keyscript.sh` | keyscript du volume racine (dérive la recover) |
| `initramfs-hook-selfrecover` | embarque binaire + libargon2 + **libgcc** + sel + keyscript |
| `setup-add-selfrecover-slot.sh` | ajoute un slot recover à un volume LUKS |
| `selfrecover_derive.py` | implémentation de référence (Python) pour usage userspace |
| `genere-passphrase.py` | tire une passphrase diceware et affiche les deux formes avec leur longueur (§5) |
| `initramfs-post-update-verifie-selfrecover` | garde-fou : vérifie les six pièces **et le sel** après chaque génération d'initramfs (§11) |

*SelfRecover-LUKS — MySelf / Self-Security — AGPL-3.0-or-later.*
