#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
selfrecover_derive — dérive une clé (Argon2id) depuis la passphrase Recover-LUKS.

Principe « mapping » : UNE passphrase -> clés filles cloisonnées par LABEL.

⚠️ Le secret d'entrée est une passphrase de NIVEAU 1 — diceware, tirée par
`genere-passphrase.py`, propre à la MACHINE. Ce n'est pas le « mot de récupération »
du niveau 2, qui est par compte et se combine à un code de récupération. Le
vocabulaire de L2 employé ici a essaimé jusqu'au README racine du monorepo, où il
était devenu « une seule passphrase mémorisée » — un terme qui n'existe dans aucun
des deux niveaux.

⚠️ `--label` est une CAPACITÉ de ce dérivateur, pas une architecture déployée : seul
`disk` a un consommateur (`selfrecover-keyscript.sh`). Les étiquettes `auth` et
`data-enc` citées dans la documentation n'existent dans aucun code du monorepo.
  - label "auth"     -> prouver/retrouver l'accès (web)
  - label "data-enc" -> chiffrer la donnée applicative (SelfDataGuard)
  - label "disk"     -> key-file pour un slot LUKS (FDE du SSD /data)

Le label change le sel effectif -> deux clés filles du même mot sont indépendantes
(le serveur web ne peut pas dériver la clé "disk" sans le label/sel disque).

Argon2id (KDF lent, memory-hard) : adapté à une clé de DISQUE, bruteforçable hors-ligne
si le SSD est volé -> on ralentit massivement chaque essai (≠ HMAC rapide de l'auth web).
"""
import argparse
import hashlib
import sys
from argon2.low_level import hash_secret_raw, Type


def derive(word: str, salt: str, label: str, length: int = 32,
           time_cost: int = 3, memory_cost: int = 65536, parallelism: int = 4) -> bytes:
    """word + salt + label -> clé déterministe (Argon2id)."""
    # sel effectif = SHA256(salt || label) tronqué -> le label sépare les clés filles
    eff_salt = hashlib.sha256(f"{salt}:{label}".encode("utf-8")).digest()[:16]
    # 19 (0x13) en litteral, et NON `version=ARGON2_VERSION` : cette constante EST le
    # defaut de hash_secret_raw, l'ecrire ne fige donc rien — si argon2-cffi changeait
    # de defaut, la constante changerait avec, et les cles suivraient. Un litteral
    # tient bon tout seul : le C, lui, herite du defaut de libargon2, et la moindre
    # divergence entre les deux fait rougir le vecteur de reference d'INSTALL.md §1.
    return hash_secret_raw(
        version=19,
        secret=word.encode("utf-8"),
        salt=eff_salt,
        time_cost=time_cost,
        memory_cost=memory_cost,   # KiB -> 65536 = 64 MiB
        parallelism=parallelism,
        hash_len=length,
        type=Type.ID,              # Argon2id
    )


if __name__ == "__main__":
    ap = argparse.ArgumentParser(description="Dérive une clé SelfRecover (Argon2id).")
    ap.add_argument("--word", help="la passphrase — DÉCONSEILLÉ : visible dans "
                                   "/proc/<pid>/cmdline pendant l'exécution. Réservé aux tests.")
    ap.add_argument("--stdin", action="store_true",
                    help="lit la passphrase sur stdin (1re ligne) — voie recommandée")
    ap.add_argument("--salt", help="sel propre au déploiement (ex. site_salt)")
    ap.add_argument("--salt-file", help="fichier contenant le sel (comme le clone C)")
    ap.add_argument("--label", default="disk", help="disk | auth | data-enc | ...")
    ap.add_argument("--len", type=int, default=32, help="taille de la clé en octets")
    ap.add_argument("--format", choices=["hex", "raw"], default="hex",
                    help="hex (texte, sûr en pipe) ou raw (octets bruts)")
    a = ap.parse_args()

    # Passphrase : stdin par défaut, argv seulement si explicitement demandé.
    if a.stdin:
        word = sys.stdin.readline().rstrip("\n")
    elif a.word is not None:
        print("selfrecover_derive: AVERTISSEMENT — --word expose la passphrase dans "
              "/proc/<pid>/cmdline. Préférez --stdin.", file=sys.stderr)
        word = a.word
    else:
        ap.error("il faut --stdin (recommandé) ou --word")
    if not word:
        ap.error("passphrase vide")

    # Sel : --salt ou --salt-file, comme le clone C.
    if a.salt_file:
        with open(a.salt_file, "r", encoding="utf-8") as f:
            # Meme lecture que le clone C : tout le fichier, sans les seuls sauts
            # de ligne finaux. `readline().strip()` en differait sur un sel a
            # espaces de tete ou sur deux lignes — deux cles pour un meme fichier.
            salt = f.read().rstrip("\r\n")
    elif a.salt is not None:
        salt = a.salt
    else:
        ap.error("il faut --salt ou --salt-file")

    key = derive(word, salt, a.label, a.len)
    if a.format == "raw":
        sys.stdout.buffer.write(key)            # octets bruts (pas de newline)
    else:
        sys.stdout.write(key.hex())             # hex, SANS newline -> sûr pour cryptsetup --key-file=-
