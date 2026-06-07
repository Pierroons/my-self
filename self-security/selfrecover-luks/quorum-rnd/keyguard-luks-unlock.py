#!/usr/bin/env python3
"""
SelfKeyGuard LUKS unlock — quorum réseau avec FALLBACK SelfRecover.

Flow :
  1. Tente le déverrouillage par QUORUM réseau (témoins) -> clé maître Shamir.
  2. Si le quorum échoue (témoin down, réseau KO) -> bascule SelfRecover :
     prompt du mot de récupération -> Argon2id(label=disk) -> clé du slot SelfRecover.
  3. luksOpen via key-file en tmpfs (RAM) -> mount -> shred de la clé.

Le slot quorum n'est JAMAIS retiré : les deux voies ouvrent le même volume (slots LUKS distincts).
À lancer en root (cryptsetup + mount).

Configuration (ordre de priorité : variable d'env > keyguard.conf > défaut) :
  WITNESSES     liste d'URLs "/part" séparées par des virgules
  KEYGUARD_DIR  répertoire de auth_key.bin et selfrecover_salt   (défaut : dossier du script)
  LUKS_DEVICE   volume à ouvrir   (défaut : /dev/disk/by-label/cryptdata)
  LUKS_MAPPER   nom du mapper     (défaut : cryptdata)
  MOUNT_POINT   point de montage  (défaut : /mnt/cryptdata)
Voir keyguard.conf.example.
"""
import base64
import hashlib
import hmac
import json
import os
import secrets
import subprocess
import sys
import time
import getpass
import urllib.request
from pathlib import Path
from urllib.parse import urlparse

from Crypto.Protocol.SecretSharing import Shamir

# Module de dérivation SelfRecover, déployé à côté de ce script.
sys.path.insert(0, str(Path(__file__).resolve().parent))
from selfrecover_derive import derive as selfrecover_derive  # noqa: E402


def _load_conf() -> dict:
    """Config depuis l'environnement, sinon keyguard.conf à côté du script, sinon défauts."""
    here = Path(__file__).resolve().parent
    file_conf: dict[str, str] = {}
    cf = here / "keyguard.conf"
    if cf.exists():
        for line in cf.read_text(encoding="utf-8").splitlines():
            line = line.strip()
            if line and not line.startswith("#") and "=" in line:
                k, v = line.split("=", 1)
                file_conf[k.strip()] = v.strip()

    def get(key: str, default: str) -> str:
        return os.environ.get(key, file_conf.get(key, default))

    return {
        "witnesses": [w.strip() for w in get("WITNESSES", "").split(",") if w.strip()],
        "keyguard_dir": Path(get("KEYGUARD_DIR", str(here))),
        "luks_device": get("LUKS_DEVICE", "/dev/disk/by-label/cryptdata"),
        "luks_mapper": get("LUKS_MAPPER", "cryptdata"),
        "mount_point": get("MOUNT_POINT", "/mnt/cryptdata"),
    }


_CONF = _load_conf()
WITNESSES = _CONF["witnesses"]
AUTH_KEY_PATH = _CONF["keyguard_dir"] / "auth_key.bin"
SALT_PATH = _CONF["keyguard_dir"] / "selfrecover_salt"   # sel du déploiement (non secret)
TMPFS_KEY = Path("/run/keyguard/master.bin")
LUKS_DEVICE = _CONF["luks_device"]
LUKS_MAPPER = _CONF["luks_mapper"]
MOUNT_POINT = _CONF["mount_point"]


# ---------------------------------------------------------------- quorum réseau
def signed_request(url: str, key: bytes):
    path = urlparse(url).path or "/"
    ts = str(int(time.time()))
    nonce = secrets.token_hex(16)
    message = f"{ts}:{nonce}:{path}".encode()
    sig = hmac.new(key, message, hashlib.sha256).hexdigest()
    req = urllib.request.Request(url, headers={
        "X-Auth-TS": ts, "X-Auth-Nonce": nonce, "X-Auth-Sig": sig,
    })
    with urllib.request.urlopen(req, timeout=10) as r:
        return json.loads(r.read().decode("utf-8"))


def fetch_part(url, key):
    data = signed_request(url, key)
    return [(c["share_id"], base64.b64decode(c["data_b64"])) for c in data["chunks"]]


def combine(parts):
    num_chunks = len(parts[0])
    secret = b""
    for chunk_idx in range(num_chunks):
        secret += Shamir.combine([p[chunk_idx] for p in parts])
    return secret


def get_key_via_quorum() -> bytes:
    if not WITNESSES:
        raise RuntimeError("aucun témoin configuré (WITNESSES dans keyguard.conf ou l'environnement)")
    auth_key = AUTH_KEY_PATH.read_bytes()
    parts = []
    for url in WITNESSES:
        print(f"      {url}")
        parts.append(fetch_part(url, auth_key))
    return combine(parts)


# ----------------------------------------------------------- fallback SelfRecover
def get_key_via_selfrecover() -> bytes:
    if not sys.stdin.isatty():
        # Au boot (pas de TTY), on ne peut pas demander le mot : on sort proprement.
        raise SystemExit(
            "Quorum KO et pas de terminal interactif.\n"
            "→ Relance à la main :  sudo python3 keyguard-luks-unlock.py"
        )
    salt = SALT_PATH.read_text().strip() if SALT_PATH.exists() else os.environ.get("SELFRECOVER_SALT", "")
    if not salt:
        raise SystemExit("Sel SelfRecover manquant (fichier selfrecover_salt ou env SELFRECOVER_SALT).")
    word = getpass.getpass("  Mot de récupération SelfRecover : ")
    return selfrecover_derive(word, salt, "disk")


# ----------------------------------------------------------------------- système
def run(cmd, check=True):
    print(f"  $ {' '.join(cmd)}")
    r = subprocess.run(cmd, capture_output=True, text=True)
    if r.stdout:
        print(r.stdout)
    if r.returncode != 0:
        print(f"  STDERR: {r.stderr}", file=sys.stderr)
        if check:
            raise SystemExit(f"Command failed: {' '.join(cmd)}")
    return r


def main():
    print("=== SelfKeyGuard LUKS unlock (quorum + fallback SelfRecover) ===\n")

    try:
        print("[quorum] tentative via les témoins réseau…")
        key = get_key_via_quorum()
        source = "quorum réseau"
    except SystemExit:
        raise
    except Exception as e:
        print(f"[quorum] ⚠ indisponible : {e}", file=sys.stderr)
        print("[fallback] → SelfRecover (mot de récupération)\n", file=sys.stderr)
        key = get_key_via_selfrecover()
        source = "SelfRecover (mot de récup)"
    print(f"      clé obtenue via {source} ({len(key) * 8} bits)\n")

    print(f"[luks] écrit la clé en tmpfs + luksOpen {LUKS_DEVICE}")
    TMPFS_KEY.parent.mkdir(parents=True, exist_ok=True)
    TMPFS_KEY.write_bytes(key)
    TMPFS_KEY.chmod(0o600)
    try:
        run(["cryptsetup", "luksOpen", LUKS_DEVICE, LUKS_MAPPER, "--key-file", str(TMPFS_KEY)])
        Path(MOUNT_POINT).mkdir(parents=True, exist_ok=True)
        run(["mount", f"/dev/mapper/{LUKS_MAPPER}", MOUNT_POINT])
    finally:
        run(["shred", "-u", str(TMPFS_KEY)], check=False)

    print(f"\n=== Monté sur {MOUNT_POINT} — déverrouillé via {source} ===")
    run(["df", "-h", MOUNT_POINT], check=False)


if __name__ == "__main__":
    main()
