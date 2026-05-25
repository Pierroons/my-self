#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
selfrecover_derive — dérive une clé (Argon2id) depuis un mot de récupération SelfRecover.

Principe « mapping » : UN mot racine mémorisé -> clés filles cloisonnées par LABEL.
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
    return hash_secret_raw(
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
    ap.add_argument("--word", required=True, help="mot de récupération (à garder fort)")
    ap.add_argument("--salt", required=True, help="sel propre au déploiement (ex. site_salt)")
    ap.add_argument("--label", default="disk", help="disk | auth | data-enc | ...")
    ap.add_argument("--len", type=int, default=32, help="taille de la clé en octets")
    ap.add_argument("--format", choices=["hex", "raw"], default="hex",
                    help="hex (texte, sûr en pipe) ou raw (octets bruts)")
    a = ap.parse_args()
    key = derive(a.word, a.salt, a.label, a.len)
    if a.format == "raw":
        sys.stdout.buffer.write(key)            # octets bruts (pas de newline)
    else:
        sys.stdout.write(key.hex())             # hex, SANS newline -> sûr pour cryptsetup --key-file=-
