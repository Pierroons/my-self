# Contribuer à MySelf

Merci de ton intérêt ! Quelques règles, surtout côté **données**.

## 🔒 Règle d'or : aucune donnée réelle dans le dépôt

Ce dépôt est **public**. N'y commite **jamais** :

- une **identité réelle** (nom civil, prénom+nom de quelqu'un),
- une **adresse**, commune + code postal réels, coordonnées GPS d'une exploitation,
- un **email perso**, un **téléphone**, un **IBAN/RIB** réel,
- une **IP privée** d'infra (`192.168.x.x`…), un **chemin disque** local, un nom de serveur interne,
- un **nom de domaine / business** personnel,
- un **secret** (clé API, token, mot de passe, contenu de `.env`).

Pour les **démos**, utilise uniquement des **données fictives** : personnages d'exemple
(`Marie DUPONT`), communes neutres, IBAN `FR76 0000 0000 0000 0000 0000 000`, etc.

Tes données personnelles de travail vont dans `_perso/` (gitignoré, jamais déployé).

## 🎭 Jeu de données fictives — la seule source d'exemples

Interdire la donnée réelle ne suffit pas : au moment d'écrire un exemple, ce qui
vient à l'esprit est **ce qu'on a sous la main**. Un marché qu'on connaît, une IP
qui marche, un email qui existe. Il faut donc que le faux soit plus accessible
que le vrai.

Ce tableau est cette réserve. **Puise dedans, ne réinvente pas.**

| Besoin | Valeur | Pourquoi celle-là |
|---|---|---|
| Exploitation | `Ferme du Soleil` | déjà utilisée dans les captures d'écran |
| Personne | `Sophie MARTIN`, `Marie DUPONT` | l'équivalent français de « John Doe » |
| Tiers technique | `alice`, `bob`, `carol` | convention des suites cryptographiques |
| Commune | `Sainte-Foy` | homonyme dans une dizaine de départements |
| Code postal | `33220` | cohérent avec Sainte-Foy (Gironde) |
| Adresse | `1 rue de la Mairie` · `17 rue des Lilas` | voies génériques, présentes partout |
| Email | `contact@my-self.fr` · `<nom>.exemple@…` | le second porte son propre marqueur |
| Téléphone | `06 12 34 56 78` | séquence manifestement factice |
| IBAN | `FR76 0000 0000 0000 0000 0000 000` | zéros, invalide à la vérification |
| IP | `192.0.2.x` · `198.51.100.x` · `203.0.113.x` | plages réservées à la doc (RFC 5737) |
| Domaine | `example.org` · `example.com` | réservés par l'IANA, jamais attribuables |
| Compte système | `user`, `deploy`, `app` | n'identifient personne |
| Chemin | `$HOME/.ssh/…`, `/home/user/…` | jamais l'arborescence d'un poste réel |

### Captures d'écran

Aucun scanner ne lit une image : c'est un angle mort permanent, et il ne se
comblera pas. **Une capture vient toujours de l'instance de démonstration.**

Concrètement : si le bandeau `ENV PERSO` apparaît à l'écran, la capture ne part
pas dans le dépôt. Vérifie aussi les métadonnées avec `exiftool` — un PNG peut
porter un nom d'utilisateur ou un chemin de fichier.

### Corriger le présent ne nettoie pas le passé

Retirer une donnée d'un fichier ne la retire pas des commits qui la contenaient.
Un `git log -p` la retrouve, et une réécriture d'historique est le seul remède —
avec les conséquences que ça implique sur un dépôt public.

D'où l'ordre des priorités : **ne pas la faire entrer** vaut mieux que toute
détection, aussi bonne soit-elle.

### Les messages de commit sont publics aussi

Aucun outil ne les scanne. Un message du type « retire le nom X de la démo »
publie X définitivement, en clair, dans un objet que personne ne relit.

Décris **ce que tu as fait**, jamais **ce que tu as retiré**.


## 🛡️ Protection automatique (obligatoire)

Le dépôt est protégé par [gitleaks](https://github.com/gitleaks/gitleaks) :

```bash
sudo apt install gitleaks      # une fois
./scripts/install-hooks.sh     # active le hook pre-commit
```

- Un **hook pre-commit** bloque localement tout commit contenant une donnée sensible.
- Une **CI GitHub Actions** (`.github/workflows/gitleaks.yml`) re-scanne chaque push/PR et
  **refuse le merge** en cas de fuite — la barrière s'applique à tout le monde.
- Règles dans `.gitleaks.toml` ; faux positifs connus dans `.gitleaksignore`.

## 🚀 Déploiement

Le déploiement en production est réservé au mainteneur (audit OPSEC intégré).
intégré). Les contributions passent par **Pull Request** sur `main`.

## Commits

Configure ton email git en **noreply GitHub** pour ne pas exposer ton adresse :
`git config user.email "<id>+<login>@users.noreply.github.com"`.
