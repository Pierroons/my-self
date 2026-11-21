#!/usr/bin/env python3
"""Reference vectors for the pepper, from a second implementation.

The PHP library applies the pepper with hash_hmac and libsodium. This script
writes the construction out by hand with hashlib, hmac and argon2-cffi: if both
agree, a change to the tag, the length prefixes, the usages, the binding or the
pepper file grammar shows up in `tests/sanity_pepper.php`, against values the
PHP code did not produce itself.

    pre(binding, secret)  SHA-256("myself-pepper/v1/input\\0" || len(binding) on 4 bytes || binding || secret)
    mac(usage, data)      HMAC-SHA256(pepper, "myself-pepper/v1\\0" || len(usage) on 1 byte || usage || data)
    check                 first 16 bytes of mac("check", "")
    lock input            hex(mac(usage, pre(user_salt, secret))), 64 lower-case hex, given to Argon2id
    layer key             mac(usage of the layer, pre(user_salt, ""))
    pepper file           "myself-pepper/v1:" || 64 lower-case hex || an optional "\\n",
                          at least 16 distinct bytes

Usage:
    python3 tests/vecteurs_poivre.py            check vecteurs-poivre.json (exit 1 on mismatch)
    python3 tests/vecteurs_poivre.py --ecrire   rewrite it (only when the construction changes on purpose)
"""
import hashlib
import hmac
import json
import re
import struct
import sys
from pathlib import Path

from argon2.low_level import Type, hash_secret_raw

FICHIER = Path(__file__).with_name("vecteurs-poivre.json")
TAG = b"myself-pepper/v1"

# libsodium's crypto_pwhash: opslimit = passes, memlimit in bytes, one lane.
PROFIL = {"opslimit": 3, "memlimit": 64 * 1024 * 1024}

CAS = [
    {
        "nom": "the inputs of the design review",
        "poivre_hex": bytes(range(32)).hex(),
        "sel_compte_hex": "00112233445566778899aabbccddeeff",
        "mot_de_passe": "correct horse battery",
        "mot_memorise": "a1" * 32,
    },
    {
        "nom": "another pepper, another account",
        "poivre_hex": "f3" + bytes(range(100, 131)).hex(),
        "sel_compte_hex": "a0b1c2d3e4f5061728394a5b6c7d8e9f",
        "mot_de_passe": "mot de passe à accents",
        "mot_memorise": "0f" * 32,
    },
]

REFUS = {
    "upper-case hex": TAG + b":" + bytes(range(32)).hex().upper().encode() + b"\n",
    "63 hex": TAG + b":" + bytes(range(32)).hex()[1:].encode() + b"\n",
    "a carriage return": TAG + b":" + bytes(range(32)).hex().encode() + b"\r\n",
    "no tag": bytes(range(32)).hex().encode() + b"\n",
    "fewer than 16 distinct bytes": TAG + b":" + b"07" * 32 + b"\n",
}


def lire(contenu: bytes) -> bytes:
    m = re.fullmatch(rb"myself-pepper/v1:([0-9a-f]{64})\n?", contenu)
    if not m:
        raise ValueError("malformed")
    poivre = bytes.fromhex(m.group(1).decode())
    if len(set(poivre)) < 16:
        raise ValueError("uniform")
    return poivre


def pre(liaison: bytes, secret: bytes) -> bytes:
    return hashlib.sha256(TAG + b"/input\x00" + struct.pack(">I", len(liaison)) + liaison + secret).digest()


def mac(poivre: bytes, usage: bytes, donnee: bytes) -> bytes:
    assert 1 <= len(usage) <= 255
    return hmac.new(poivre, TAG + b"\x00" + bytes([len(usage)]) + usage + donnee, hashlib.sha256).digest()


def argon2id(secret: str, sel: bytes) -> str:
    return hash_secret_raw(
        secret.encode(), sel, time_cost=PROFIL["opslimit"], memory_cost=PROFIL["memlimit"] // 1024,
        parallelism=1, hash_len=32, type=Type.ID, version=19,
    ).hex()


def attendus(cas: dict) -> dict:
    fichier = TAG + b":" + cas["poivre_hex"].encode() + b"\n"
    poivre = lire(fichier)
    sel = bytes.fromhex(cas["sel_compte_hex"])
    entree_pwd = mac(poivre, b"selfdataguard/wrap-pwd", pre(sel, cas["mot_de_passe"].encode())).hex()
    entree_recov = mac(poivre, b"selfdataguard/wrap-recov", pre(sel, cas["mot_memorise"].encode())).hex()
    return {
        "fichier": fichier.decode(),
        "attendu_check": mac(poivre, b"check", b"")[:16].hex(),
        "attendu_pre_mot_memorise": pre(sel, cas["mot_memorise"].encode()).hex(),
        "attendu_entree_wrap_pwd": entree_pwd,
        "attendu_entree_wrap_recov": entree_recov,
        "attendu_cle_wrap_pwd": argon2id(entree_pwd, sel),
        "attendu_cle_wrap_recov": argon2id(entree_recov, hashlib.sha256(sel + b"/dataguard").digest()[:16]),
        "attendu_cle_couche_wrap_pwd": mac(poivre, b"selfdataguard/wrap-pwd-layer", pre(sel, b"")).hex(),
        "attendu_cle_couche_wrap_recov": mac(poivre, b"selfdataguard/wrap-recov-layer", pre(sel, b"")).hex(),
    }


def construire() -> dict:
    refus = {}
    for nom, contenu in REFUS.items():
        try:
            lire(contenu)
            raise SystemExit(f"the grammar takes a file it must refuse: {nom}")
        except ValueError as e:
            refus[nom] = {"contenu_hex": contenu.hex(), "raison": str(e)}
    return {
        "profil": PROFIL,
        "cas": [{**c, **attendus(c)} for c in CAS],
        "refus": refus,
    }


def main() -> int:
    vecteurs = construire()
    if sys.argv[1:] == ["--ecrire"]:
        FICHIER.write_text(json.dumps(vecteurs, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
        print(f"written: {FICHIER.name}")
        return 0
    if json.loads(FICHIER.read_text(encoding="utf-8")) != vecteurs:
        print(f"MISMATCH: {FICHIER.name} differs from the second implementation", file=sys.stderr)
        return 1
    print(f"OK — {FICHIER.name} matches the second implementation ({len(vecteurs['cas'])} cases, {len(vecteurs['refus'])} refusals)")
    return 0


if __name__ == "__main__":
    sys.exit(main())
