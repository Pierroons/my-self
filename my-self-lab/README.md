# MySelf-Lab

Forum vitrine de l'écosystème **MySelf** — terrain de démonstration et de test red team.

Un forum réaliste sur le thème de la **souveraineté numérique**, qui intègre les modules MySelf entre eux dans une application concrète :

- **Authentification sans email** via [SelfRecover](../bi-self/selfrecover/) — l'utilisateur choisit un mot de récupération, le serveur génère mot de passe + passphrase diceware. Dérivation `HMAC(mot_récup, domaine‖sel_du_site)`, résistante au phishing par isolation de domaine.
- **Messages privés chiffrés at-rest** via [SelfDataGuard](../self-security/selfdataguard/) — AES-256-GCM, clé serveur (blind key) hors base. Une exfiltration de la base ne révèle que des blobs illisibles.

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
my-self-lab/
├── composer.json     # require pierroons/selfdataguard (path local)
├── schema.sql        # accounts, app_sessions, threads, posts, dm
├── seed.php          # données de démonstration
├── lib/              # Db, Auth (SelfRecover), Forum, DM, DataGuard, layout
├── public/           # pages + api/ (endpoints)
└── data/             # SQLite + secrets (gitignored)
```

## Sécurité — modèle de menace démontré (V1)

| Adversaire | Sans MySelf | Avec MySelf-Lab |
|---|---|---|
| Dump de la base | Données + DM en clair | DM chiffrés AES-256-GCM (clé hors base) |
| Bruteforce login | Illimité | Rate-limit 5 échecs / 15 min |
| Phishing reset email | Vecteur classique | Pas d'email — recovery par dérivation HMAC isolée par domaine |
| Secrets en base | Souvent en clair | Argon2id (m=64 Mo, t=4, p=2) pour tout, blind key en `0600` hors webroot |

## Hors V1 (roadmap)

- SelfModerate (vote/réputation anti-Sybil)
- Attack Simulator (`/lab/attacks/`)
- E2E DM inter-utilisateurs (clés asymétriques)
- Page règles d'engagement red team + hébergement `your-instance.example`

## Licence

AGPL-3.0-or-later — partie de l'écosystème [MySelf](https://my-self.fr).
