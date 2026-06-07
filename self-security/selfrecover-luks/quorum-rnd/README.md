# quorum-rnd — déverrouillage par quorum (R&D, non activé)

Piste de recherche : déverrouillage **automatique** des volumes par **consensus de témoins**
réseau (parts distribuées / Shamir), **sans saisie** de passphrase.

⚠️ **Non activé dans la v0.3.0** de SelfRecover-LUKS, qui repose sur keyscript Argon2id +
keyfile (voir [`../INSTALL.md`](../INSTALL.md) et le [whitepaper](../docs/SelfRecover-LUKS_Whitepaper.md), § travaux futurs).
Ces fichiers sont conservés ici comme base de travaux futurs.

| Fichier | Rôle |
|---------|------|
| `keyguard-luks-unlock.py` | déverrouillage quorum réseau + fallback SelfRecover (boot-safe) |
| `add-slot-via-quorum.sh` | ajoute un slot autorisé par la clé maître reconstituée via quorum |
| `keyguard.conf.example` | exemple de configuration (témoins, chemins, device) |
| `test-luks-selfrecover.sh` | PoC dérivation + slot sur image jetable |
| `test-phase2-image.sh` | valide les deux voies (quorum + SelfRecover) sur image jetable |

*MySelf / Self-Security — AGPL-3.0-or-later.*
