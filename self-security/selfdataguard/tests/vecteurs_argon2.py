#!/usr/bin/env python3
"""Reference vectors for the three SelfDataGuard locks, from a second implementation.

The PHP library derives each lock key with libsodium. This script derives the same
keys with argon2-cffi and the construction written out by hand: if both agree, a
change to the contexts, the salt construction, the passphrase normalisation or the
cost profile shows up in `tests/sanity_vecteurs.php`, against a value the PHP code
did not produce itself.

    Password    Argon2id(password,                user_salt)
    Memorized   Argon2id(memorized,               sha256(user_salt || "/dataguard")[:16])
    Passphrase  Argon2id(normalize(passphrase),   sha256(user_salt || "/dataguard/passphrase")[:16])

Usage:
    python3 tests/vecteurs_argon2.py            check vecteurs-argon2.json (exit 1 on mismatch)
    python3 tests/vecteurs_argon2.py --ecrire   rewrite it (only when the construction changes on purpose)
"""
import hashlib
import json
import re
import sys
from pathlib import Path

from argon2.low_level import Type, hash_secret_raw

FICHIER = Path(__file__).with_name("vecteurs-argon2.json")

# libsodium's crypto_pwhash: opslimit = passes, memlimit in bytes, one lane.
PROFIL = {"opslimit": 3, "memlimit": 64 * 1024 * 1024}

CAS = [
    {
        "nom": "ascii",
        "sel_compte_hex": "00112233445566778899aabbccddeeff",
        "mot_de_passe": "correct horse battery",
        "mot_memorise": "a1" * 32,
        "phrase": "cheval agrafe batterie correct moulin ivoire",
    },
    {
        "nom": "accents et blancs irréguliers",
        "sel_compte_hex": "f0e1d2c3b4a5968778695a4b3c2d1e0f",
        "mot_de_passe": "mot de passe — été 2026",
        "mot_memorise": "0f" * 32,
        # The library collapses runs of ASCII whitespace to one space and trims the
        # ends; this input only uses spaces, tabs and newlines, where both agree.
        "phrase": "  cheval\tagrafe   batterie\ncorrect  moulin ivoire \n",
    },
]


def normaliser(passphrase: str) -> str:
    return re.sub(r"[ \t\n\r\x0b\x0c]+", " ", passphrase).strip(" \t\n\r\x0b")


def argon2id(secret: bytes, sel: bytes) -> str:
    return hash_secret_raw(
        secret, sel,
        time_cost=PROFIL["opslimit"],
        memory_cost=PROFIL["memlimit"] // 1024,
        parallelism=1, hash_len=32, type=Type.ID, version=19,
    ).hex()


def calculer(cas: dict) -> dict:
    user_salt = bytes.fromhex(cas["sel_compte_hex"])
    contexte = lambda suffixe: hashlib.sha256(user_salt + suffixe).digest()[:16]
    return {
        **cas,
        "attendu_mot_de_passe": argon2id(cas["mot_de_passe"].encode(), user_salt[:16]),
        "attendu_mot_memorise": argon2id(cas["mot_memorise"].encode(), contexte(b"/dataguard")),
        "phrase_normalisee": normaliser(cas["phrase"]),
        "attendu_phrase": argon2id(normaliser(cas["phrase"]).encode(), contexte(b"/dataguard/passphrase")),
    }


def main() -> int:
    attendu = {"profil": PROFIL, "cas": [calculer(c) for c in CAS]}
    if "--ecrire" in sys.argv:
        FICHIER.write_text(json.dumps(attendu, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
        print(f"écrit : {FICHIER.name}")
        return 0
    lu = json.loads(FICHIER.read_text(encoding="utf-8"))
    if lu != attendu:
        print(f"❌ {FICHIER.name} ne correspond pas à la seconde implémentation")
        return 1
    print(f"✅ {FICHIER.name} : {len(CAS)} cas × 3 serrures, recalculés par argon2-cffi")
    return 0


if __name__ == "__main__":
    sys.exit(main())
