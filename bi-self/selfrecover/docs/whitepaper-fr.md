# SelfRecover — "Whitepaper" v1.1

**Protocole de récupération de compte sans email**
*Un mot. Tous les sites. Pour toujours.*

---

## Résumé

SelfRecover est un protocole de récupération de compte à connaissance partagée qui élimine la dépendance à l'email pour la réinitialisation de mot de passe. Il repose sur une dérivation HMAC-SHA256 effectuée côté client en utilisant le domaine courant comme matériau de clé : le mot de récupération brut ne quitte jamais le navigateur, et un mot capturé est inutilisable sur tout autre domaine (anti-phishing natif). Ce document décrit le protocole, son escalade en trois niveaux, son modèle de menaces, et les règles de déploiement obligatoires.

---

## 1. Le problème

Toute application web fait face à la même question : *que se passe-t-il quand un utilisateur oublie son mot de passe ?*

Depuis vingt ans, la réponse de l'industrie est toujours la même : **envoyer un email**. Cela crée une chaîne de dépendances :

- L'application doit intégrer un service SMTP (SendGrid, Mailgun, AWS SES, ou du self-hosted)
- L'utilisateur doit avoir une adresse email valide et la partager avec le service
- L'email doit réellement arriver (filtres anti-spam, greylisting, délivrabilité)
- Le lien de reset doit être cliqué dans un délai (15 à 60 minutes)
- Le modèle de sécurité est externalisé vers un tiers (Gmail, Outlook, ProtonMail)

**La vraie question que personne ne pose :** pourquoi un site web a-t-il besoin de votre email pour prouver que vous êtes vous ?

SelfRecover propose une autre réponse : la confiance reste entre l'utilisateur et le site. Pas d'intermédiaire. Pas d'email. Pas de tiers.

---

## 2. Le modèle SelfRecover

**Principe fondamental :**

> Mot de récupération seul = rien.
> Algorithme seul = rien.
> Mot de récupération + Algorithme = identité prouvée.

SelfRecover est un système de récupération à connaissance partagée (split knowledge). L'utilisateur retient un mot. Le système fournit l'algorithme. Aucun des deux n'a de valeur sans l'autre.

**Ce que l'utilisateur retient :**

- Un identifiant public (pseudo, téléphone, gamer tag, numéro client — n'importe quelle étiquette)
- Un mot de récupération de son choix (n'importe quelle longueur, même `bob`)

C'est tout. Deux choses. Pour tous les sites. Pour toujours.

---

## 3. Fonctionnement technique

### 3.1 Inscription

Quand un nouveau compte est créé, le mot de récupération est immédiatement traité par dérivation HMAC-SHA256. Le mot brut n'atteint jamais le serveur.

```
clé_dérivée = HMAC-SHA256(mot_récupération, domaine + salt_du_site)
```

Le serveur reçoit et stocke :

- `bcrypt(mot_de_passe)` — hash classique du mot de passe
- `bcrypt(passphrase)` — une passphrase diceware générée côté serveur (4 mots, ~51 bits d'entropie)
- `bcrypt(clé_dérivée)` — la clé de récupération dérivée par HMAC

L'utilisateur reçoit la passphrase une seule fois et doit la conserver hors ligne.

### 3.2 Authentification

La connexion utilise le flow classique `username + mot de passe` → token JWT. Le token est lié à une empreinte navigateur et s'invalide au changement d'appareil.

### 3.3 Récupération

Trois niveaux, chacun avec ses propres garanties et modes d'échec :

| Niveau | Entrée | Résultat en cas de succès |
|--------|--------|---------------------------|
| **L1** | Username + passphrase diceware | Nouveau mot de passe |
| **L2** | Identifiant + mot de récupération (dérivé HMAC) | Nouveau mot de passe |
| **L3** | Formulaire de scoring multi-facteurs | Nouveau mot de passe ou chat admin |

---

## 4. Dérivation HMAC — Un mot, unique partout

C'est l'innovation centrale de SelfRecover.

Quand l'utilisateur tape son mot de récupération, le navigateur calcule une clé dérivée spécifique au site **avant que quoi que ce soit ne quitte le client** :

```javascript
async function hmacDerive(mot, salt) {
    const enc = new TextEncoder();
    const domaine = window.location.hostname;
    const matériau = enc.encode(domaine + salt);
    const clé = await crypto.subtle.importKey(
        'raw', matériau,
        { name: 'HMAC', hash: 'SHA-256' },
        false, ['sign']
    );
    const sig = await crypto.subtle.sign('HMAC', clé, enc.encode(mot));
    return Array.from(new Uint8Array(sig))
        .map(b => b.toString(16).padStart(2, '0')).join('');
}
```

**Propriétés clés :**

- Le même input (`"bob"`) produit un output différent sur chaque site
- Le mot brut ne quitte **jamais** le navigateur
- Le serveur ne voit jamais `"bob"` — uniquement la clé dérivée
- Le résultat fait toujours 256 bits, quelle que soit la longueur de l'input
- Un mot de 3 lettres est aussi sécurisé qu'un de 30 caractères dans le système
- Fonctionne sur tous les appareils — mêmes maths, même résultat
- Le domaine est obtenu automatiquement via `window.location.hostname`

**L'anti-phishing est natif.** Un faux site (ex. `tartenpion-fake.fr` au lieu du vrai `tartenpion.fr`) dérive une clé complètement différente à partir du même mot. Un mot de récupération capturé est inutilisable sur tout autre domaine.

---

## 5. Escalade de récupération en 3 niveaux

### 5.1 Niveau 1 — Mot de passe oublié

- L'utilisateur fournit : `username` + `passphrase diceware` (correspondance exacte)
- En cas de succès : nouveau mot de passe généré, masqué par défaut, affiché une seule fois
- Le mot de passe reste à l'écran jusqu'à confirmation `"J'ai noté"`
- Rate limit : 3 essais / 15 minutes par username, 3 blocages → éjection vers L2
- Anti-bot : champ honeypot (caché en CSS) + vérification de timing (< 2 secondes = bot)

### 5.2 Niveau 2 — Passphrase perdue

- L'utilisateur fournit : `identifiant public` + `mot de récupération` (dérivé HMAC côté client)
- 3 tentatives avec décompte visible (1/3, 2/3, 3/3)
- En cas de succès : nouveau mot de passe généré
- Après 3 échecs : redirection vers L3
- Un litige est créé automatiquement (`LIT-0001`), l'admin est notifié, toutes les tentatives sont loguées
- Les litiges résolus automatiquement sont purgés de la BDD après 24 heures

### 5.3 Niveau 3 — Accès totalement perdu

- Entrée : lien discret `"J'ai perdu tous mes accès"` sur la page de login
- L'utilisateur fournit d'abord son identifiant public (anti-timing : délai forcé 2-3 s)
- L'empreinte navigateur est capturée et vérifiée contre la liste des empreintes suspectes
- Un formulaire unique, un seul submit, plusieurs champs par catégorie :
  - Identifiant public (4 champs, 20 points)
  - Mot de récupération (5 champs, 25 points) — dérivé HMAC côté client
  - Username (3 champs, 30 points)
  - Passphrase (3 champs, 25 points)
- Bonus passifs : IP connue (+5), empreinte connue (+5)
- **Score ≥ 60/100** → compte récupéré
- **Score < 60/100 après 3 tentatives** → activation du chat admin
- Cooldown : 1 heure entre chaque tentative

---

## 6. Système de litiges et interface admin

Chaque session de récupération échouée au-delà de L1 ouvre un litige (`LIT-XXXX`) visible dans le dashboard admin.

- Chaque litige a un numéro unique, un niveau courant, des compteurs de tentatives, le meilleur score L3, et un statut (`open`, `resolved`, `closed`, `attack_confirmed`)
- L'admin reçoit une notification push à la création du litige
- Un chat bidirectionnel est disponible entre l'admin et l'utilisateur restreint (polling, pas de WebSocket temps réel pour rester simple)
- Les litiges résolus sont purgés automatiquement après 24 heures pour garder la BDD propre

### 6.1 Clôture du litige — Décision admin

Quand l'admin examine un litige, deux options existent :

**Option 1 — Accorder la récupération (débloquer) :**

- L'admin vérifie l'identité via l'échange chat
- Le mot de passe est reset, tous les compteurs sont remis à zéro, le litige passe en `resolved`
- L'utilisateur reçoit son nouveau mot de passe par notification push

**Option 2 — Refuser la récupération :**

- L'admin ne considère pas la preuve d'identité suffisante
- Ban temporaire de 24 h appliqué — aucun nouveau litige ne peut être ouvert pendant cette fenêtre
- Le compteur de refus s'incrémente (1/3, 2/3, 3/3)
- **Au 3ᵉ refus : le compte est définitivement supprimé.** L'identifiant public redevient disponible pour une nouvelle inscription.

**Raisonnement :** un attaquant ne peut pas spammer des litiges à l'infini. Chaque refus coûte 24 h de downtime, et trois strikes effacent l'enregistrement complètement. Le vrai propriétaire, s'il est bloqué par erreur, peut réessayer après chaque fenêtre de ban ou recommencer de zéro s'il est totalement verrouillé.

---

## 7. Détection anti-abus

- **Honeypot** : champ caché en CSS — s'il est rempli, c'est un bot
- **Timing** : formulaire soumis en moins de 2 secondes → bot
- **Empreintes suspectes** : 5 tentatives depuis la même empreinte navigateur (tout identifiant confondu) → empreinte suspecte
- **Empreinte suspecte + liée à un utilisateur connu** : admin notifié, utilisateur contacté
- **Empreinte suspecte + inconnue** : IP bloquée 24 h
- **Patterns inter-comptes** : détectés en L2/L3 via le tracking d'empreintes

---

## 8. Diagnostic et remontée de bugs (sans données perso)

Chaque échec génère un code d'erreur structuré :

```
SR-L1-PASS-001   Niveau 1, passphrase incorrecte, tentative 1
SR-L2-HMAC-003   Niveau 2, validation HMAC échouée, tentative 3
SR-L3-SCORE-042  Niveau 3, scoring terminé, score 42/100
SR-L3-FING-BLK   Niveau 3, empreinte bloquée
SR-SYS-SALT-ERR  Erreur système, récupération du salt échouée
```

**Ce qui EST inclus dans les rapports de diagnostic :**

- Code d'erreur, version de la librairie, navigateur/OS, niveau atteint, nombre de tentatives, score
- Hash du salt du site (pas le salt — identifie l'installation)

**Ce qui n'est JAMAIS inclus :**

- Mot de récupération (brut ou dérivé), username, identifiant, IP, empreinte
- Passphrase, mot de passe, aucune donnée personnelle

---

## 9. Protection contre les attaques actives

Si un utilisateur légitime se connecte normalement et que le serveur détecte une activité suspecte (tentatives L1 échouées, litiges ouverts, empreintes suspectes liées à son compte), un modal s'affiche :

> **Vérification de sécurité**
> Une activité inhabituelle a été détectée sur ton compte.
> *As-tu essayé de récupérer ton compte récemment ?*
> `[ Oui, c'était moi ]`  `[ Non, ce n'était pas moi ]`

- **Oui** → nettoyage silencieux des tentatives échouées et des litiges, l'utilisateur continue normalement
- **Non** → protection renforcée activée en arrière-plan :
  - Nouveau mot de passe généré et affiché à l'utilisateur
  - Tous les JWT existants invalidés
  - Mode protection 7 jours activé (recovery L2 verrouillé)
  - Empreintes suspectes bloquées 24 h
  - Admin notifié via push

L'utilisateur voit un message rassurant `"Ton compte est maintenant sécurisé"` — pas un log technique. L'admin gère l'investigation en arrière-plan.

---

## 10. Modèle de menaces et limites

### 10.1 Menaces traitées

- **Attaques par phishing** — HMAC par domaine : un faux site dérive une clé différente
- **Piratage de l'email** — il n'y a aucun email dans le protocole
- **Panne du fournisseur SMTP** — pas de dépendance SMTP
- **Confiance tiers** — seuls le site et l'utilisateur sont impliqués
- **Brute force limité par rate** — limites par username + escalade L2/L3
- **Énumération par bot** — honeypot + timing + délais forcés
- **Blanchiment de réputation sociale** — l'identifiant public est verrouillé après inscription, impossible à modifier par l'utilisateur

### 10.2 CRITIQUE — Accès root serveur (sudo)

**C'est la limite la plus importante.**

SelfRecover protège les données de récupération par dérivation HMAC, hachage bcrypt et connaissance partagée. Mais **aucune de ces protections ne compte si un attaquant obtient un accès root au serveur**.

**La vulnérabilité :**

- Certains environnements Linux accordent un sudo sans mot de passe par défaut (`NOPASSWD: ALL` dans sudoers). Cas notables : **Raspberry Pi OS** (user `pi`) et les **images cloud** (AMIs Ubuntu AWS/DigitalOcean/GCP pour le user `ubuntu`, Amazon Linux pour `ec2-user`, etc.). La plupart des installations desktop/serveur (Debian, Ubuntu iso, Fedora, Arch) n'ont **pas** ce problème par défaut — mais vérifie toujours ton `/etc/sudoers.d/` à l'installation.
- Si un attaquant compromet le compte utilisateur (fuite de clé SSH, vulnérabilité web, etc.), il passe root sans aucune friction
- Avec root : accès direct à la base de données, remplacement des hash de mot de passe, modification du code, extraction des clés — SelfRecover devient décoratif

Ce n'est pas un risque théorique. C'est le point de défaillance unique qui contourne l'intégralité du protocole.

**RÈGLE DE DÉPLOIEMENT OBLIGATOIRE :**

- Retirer `NOPASSWD` de sudoers immédiatement après l'installation de l'OS
- Définir une passphrase diceware forte (minimum 6 mots, 8 recommandés) comme mot de passe de l'utilisateur sudo
- `sudo` doit exiger cette passphrase pour chaque escalade de privilèges
- La passphrase doit être conservée hors ligne uniquement (papier, pas numérique)
- L'authentification SSH doit utiliser des clés (pas de login par mot de passe)

**Implémentation (Debian / Ubuntu / Raspberry Pi OS) :**

```bash
# 1. Changer le mot de passe de l'utilisateur pour une passphrase diceware forte
echo "user:votre-passphrase-diceware" | sudo chpasswd

# 2. Modifier sudoers : remplacer "user ALL=(ALL) NOPASSWD: ALL" par "user ALL=(ALL) ALL"
sudo visudo -f /etc/sudoers.d/010_user-nopasswd

# 3. Vérifier : cette commande doit échouer avec "il est nécessaire de saisir un mot de passe"
sudo -k && sudo -n whoami
```

Un déploiement SelfRecover sans sudo durci, c'est un verrou sur une porte sans mur. **Cette règle est non négociable.**

### 10.3 Le mot de récupération est la clé de voûte

Si le mot de récupération est compromis (ingénierie sociale, regard par-dessus l'épaule, note négligemment écrite), un attaquant qui connaît aussi l'identifiant public peut récupérer le compte. C'est voulu et ne peut pas être corrigé sans canal de communication externe — ce que SelfRecover rejette explicitement.

Aucun système ne protège contre un secret volé. Une clé privée SSH qui fuite donne l'accès au serveur. Une seed phrase qui fuite vide un portefeuille. Un mot de récupération qui fuite ouvre le compte. Le modèle de sécurité est identique.

SelfRecover part du principe que :

- L'utilisateur traite son mot de récupération comme une clé de maison — pas sur un post-it, pas partagée dans un chat
- La dérivation HMAC limite les dégâts à un seul site (le mot est inutilisable sur d'autres domaines)
- Le rate limiting et l'escalade L2→L3 freinent les tentatives de force brute
- Le serveur ne peut pas compenser la négligence humaine — aucun système ne le peut

**Tu fais gaffe : zéro problème. Tu t'en fous : open bar.** Ce n'est pas une faille — c'est le contrat fondamental de tout système de sécurité basé sur un secret.

### 10.4 Autres limites (par conception)

- Si l'utilisateur oublie à la fois son mot de récupération et sa passphrase et échoue au scoring L3 : l'admin est le seul recours
- Les utilisateurs changeant fréquemment d'appareil perdent les bonus passifs de fingerprint

Ces limites sont voulues. Un système avec des recours infinis a une surface d'attaque infinie.

---

## 11. Checklist de sécurité au déploiement

SelfRecover ne peut pas protéger les comptes si le serveur qui l'héberge est mal sécurisé. Cette checklist est **obligatoire** avant tout déploiement en production.

### 11.1 Accès serveur

- [ ] Retirer `NOPASSWD` de sudoers — imposer une passphrase diceware (6+ mots) pour toute escalade de privilèges
- [ ] Authentification SSH par clé uniquement — désactiver le login par mot de passe (`PasswordAuthentication no`)
- [ ] Firewall actif (UFW / iptables) — exposer uniquement les ports 80, 443 et SSH

### 11.2 Base de données

- [ ] Prepared statements (PDO / requêtes paramétrées) pour TOUTES les requêtes SQL — sans exception
- [ ] Utilisateur BDD avec privilèges minimaux (`SELECT`, `INSERT`, `UPDATE`, `DELETE` uniquement — pas de `GRANT`, pas de `DROP`)
- [ ] Pas de phpMyAdmin ou Adminer exposé sur internet
- [ ] Backups chiffrés au repos (gpg ou openssl) — un dump en clair est une faille
- [ ] Stockage des backups isolé du web root — pas accessible via HTTP

### 11.3 Application

- [ ] HTTPS obligatoire — la dérivation HMAC utilise le domaine, HTTP exposerait à une attaque MITM
- [ ] Rate limiting sur tous les endpoints de recovery (nginx `limit_req` ou applicatif)
- [ ] Headers de sécurité : CSP, X-Frame-Options, X-Content-Type-Options, Referrer-Policy
- [ ] PHP : `disable_functions`, `open_basedir`, `expose_php off`
- [ ] Scripts init et migration bloqués en production (deny all ou supprimer)

### 11.4 Monitoring

- [ ] Logger toutes les tentatives de recovery (niveau, succès/échec, IP — jamais le mot de récupération)
- [ ] Alerter sur les échecs répétés L2/L3 pour un même compte
- [ ] Vérification automatisée des backups (test de restauration périodique)

Un déploiement qui ignore cette checklist n'est pas un déploiement SelfRecover — c'est une passoire.

---

## 12. Guide d'intégration

### 12.1 Pré-requis

- PHP 8.0+ ou Node.js 18+
- Base de données SQL (MySQL, MariaDB, PostgreSQL, SQLite)
- Navigateur moderne avec JavaScript et Web Crypto API
- HTTPS obligatoire en production

### 12.2 Distribution prévue

```bash
composer require pierroons/selfrecover   # future lib PHP
npm install selfrecover                  # future lib JS
```

Pas encore publiées. Voir la [démo](../demo/) pour une implémentation standalone fonctionnelle à étudier.

---

## 13. Comparaison avec les solutions existantes

| Feature | Reset par email | WebAuthn / Passkey | **SelfRecover** |
|---------|:---:|:---:|:---:|
| Pas de SMTP | ✗ | ✓ | ✓ |
| Pas de tiers | ✗ | ✗ (vendor lock-in) | ✓ |
| Fonctionne sur tous les appareils | ✓ | ~ (lié à l'appareil) | ✓ |
| Récupération possible hors ligne | ✗ | ✗ | ~ (le user détient le secret) |
| Anti-phishing par conception | ✗ | ✓ | ✓ |
| Isolation par site | ✓ | ✓ | ✓ |
| Coût zéro pour l'utilisateur | ✓ | ✓ | ✓ |
| Complexité d'implémentation | haute (SMTP) | haute (FIDO2) | faible |

SelfRecover n'est pas un remplacement pour WebAuthn. C'est un complément, surtout pour les sites qui ne veulent pas embarquer de l'authentification liée à l'appareil et ne veulent pas non plus s'appuyer sur SMTP.

---

## 14. Feuille de route

- [x] Spécification du protocole (v1.1)
- [x] Implémentation de référence (ARC PVE Hub, en production)
- [x] Livres blancs EN + FR
- [x] Démo standalone (L1 + L2)
- [ ] Audit de sécurité (communauté bienvenue)
- [ ] Extraction en librairie PHP (`composer require pierroons/selfrecover`)
- [ ] Extraction en librairie JS (`npm install selfrecover`)
- [ ] Plugin WordPress
- [ ] Package Laravel
- [ ] Portages vers Python, Go, Rust, Node

---

## 15. Contribuer

SelfRecover est open source sous licence MIT.

- Audits de sécurité et tests d'intrusion bienvenus
- Retours d'expérience de déploiements en production
- Portages vers d'autres langages et frameworks

**GitHub :** https://github.com/Pierroons/selfrecover

---

*SelfRecover — parce que votre identité ne devrait pas dépendre d'une boîte mail.*
