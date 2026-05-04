# MySelf-Live — Signing & key management

## Root key (offline)
- Generated on a Tails session, dedicated machine, never network-connected
- Algorithm : ed25519
- Storage : YubiKey or smartcard physical token
- Public key : `pubkey-myself-root.asc` (committed to repo + mirrored on my-self.fr/keys/)
- Passphrase : 7+ words diceware, never digital, kept on paper in safe

## Subkeys (rotated 6 months)
- Signed by root key
- Used for : ISO signature, manifest signature, cosign blob signing
- Old subkeys remain valid for verification of historical ISOs

## Web of trust
- Cosigners listed in `trusted-signers.txt`
- Cosigners must sign the root key in person, not via email
- Each cosigner publishes their signature on a public keyserver

## User-side verification

```bash
# 1. Import root key
curl https://my-self.fr/keys/myself-root.asc | gpg --import

# 2. Verify ISO signature
gpg --verify myself-live-0.2.0.iso.asc myself-live-0.2.0.iso

# 3. Cross-channel hash compare
sha256sum myself-live-0.2.0.iso
# Compare with values published on:
#   - https://my-self.fr/iso/sha256sums (signed)
#   - ipfs://Qm.../sha256sums
#   - github.com/Pierroons/my-self/releases/v0.2.0
# Three independent matches = high confidence
```
