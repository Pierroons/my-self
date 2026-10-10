# MySelf-Lab

Forum vitrine de l'écosystème **MySelf** — terrain de démonstration et de test red team.

Un forum réaliste sur le thème de la **souveraineté numérique**, qui intègre les modules MySelf entre eux dans une application concrète :

- **Authentification sans email** via [SelfRecover](../../bi-self/selfrecover/) — l'utilisateur choisit un mot de récupération, le serveur génère mot de passe + passphrase diceware. Dérivation `HMAC(clé = mot_récup, message = nom d'hôte + "|v2" + sel du compte)`, le nom d'hôte étant lu par le navigateur : la clé stockée diffère d'une adresse à l'autre.
- **Messages privés chiffrés at-rest** via [SelfDataGuard](../../self-security/selfdataguard/) — XChaCha20-Poly1305, clé serveur (blind key) hors base. Une exfiltration de la base ne révèle que des blobs illisibles.
- **Modération par réputation** via [SelfModerate](../../bi-self/selfmoderate/) — réputation, anti-Sybil, anti pack-voting, sanctions graduées. Servie sur `/moderation.php`.
- **Mémo personnel de bout en bout** — la clé se forme dans le navigateur (Argon2id, puis HKDF et AES-GCM) et ne touche jamais le serveur. Deux enveloppes ouvrent la même clé de coffre.

## Stack

PHP 8.1+ · SQLite (PDO) · vanilla JS · zéro framework.

## Installation

```bash
composer install
php seed.php                       # comptes + sujets de démonstration
php -S 127.0.0.1:8090 -t public    # serveur local
```

Ouvrir http://127.0.0.1:8090

## Structure

```
demo/lab/
├── composer.json     # require selfrecover + selfdataguard + selfmoderate (path local)
├── schema.sql        # accounts, app_sessions, threads, posts, dm
├── seed.php          # données de démonstration
├── lib/              # 27 fichiers — Db, Auth (SelfRecover), Forum, DM, DataGuard,
│                     #   Moderate, RecoverL3, Flags, Admin, Stats, layout…
├── public/           # pages + api/ (endpoints)
└── data/             # SQLite + secrets (gitignored)
```

## Sécurité — modèle de menace démontré (V1)

| Adversaire | Sans MySelf | Avec MySelf-Lab |
|---|---|---|
| Dump de la base | Données + DM en clair | DM chiffrés XChaCha20-Poly1305 (clé hors base) |
| Bruteforce login | Illimité | Rate-limit 5 échecs / 15 min |
| Phishing reset email | Vecteur classique | Pas d'email — aucun lien de réinitialisation à imiter |
| Secrets en base | Souvent en clair | Argon2id partout, m=64 Mio — t=4/p=2 pour les empreintes SelfRecover (`Hashing::ARGON2`), t=3/p=1 pour la clé des messages et le coffre mémo (profil de SelfDataGuard, repris par le script de dérivation) ; blind key en `0600` hors webroot |

## Hors V1

- **E2E des messages privés entre utilisateurs** (clés asymétriques par membre). Aujourd'hui les
  messages sont chiffrés **au repos** sous une clé du serveur : il peut donc les lire, et c'est par
  là qu'un des deux drapeaux du défi tombe. Seul le **mémo personnel** est de bout en bout.

*Trois entrées de cette section ont été retirées le 07/10/2026 : SelfModerate, l'Attack Simulator et
la page de règles red team tournent, et le lab est servi sur `ctf.my-self.fr` depuis le 18/07/2026.
Mesuré ce jour-là : les trois pages répondent 200.*

## Licence

AGPL-3.0-or-later — partie de l'écosystème [MySelf](https://my-self.fr).
