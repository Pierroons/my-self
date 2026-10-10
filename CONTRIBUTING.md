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

Le dépôt est protégé par [gitleaks](https://github.com/gitleaks/gitleaks). Installe-le par son
**binaire officiel** ([releases](https://github.com/gitleaks/gitleaks/releases)), pas par le paquet
apt : figé sur une version ancienne, il n'interprète pas les allowlists comme la CI. Puis :

```bash
./scripts/install-hooks.sh     # active les trois hooks, une fois après clonage
```

- Trois **hooks** bloquent localement, du moins cher au plus cher : `pre-commit` (les
  fichiers indexés), `commit-msg` (le message), `pre-push` (l'historique, les orphelins, les
  métadonnées).
- Sans **liste de motifs**, qui vit hors du dépôt, les hooks ne cherchent que des secrets,
  pas les données personnelles : `install-hooks.sh` dit où la créer.
- Une **CI GitHub Actions** (`.github/workflows/gitleaks.yml`) re-scanne chaque push/PR et
  **refuse le merge** en cas de fuite — la barrière s'applique à tout le monde.
- Règles dans `.gitleaks.toml` ; faux positifs connus dans `.gitleaksignore`.

## ✍️ Signer tes contributions — le certificat d'origine

**Tu contribues depuis un fork, par demande de fusion.** Chaque commit de ta demande porte une ligne
de signature ; `git commit -s` l'ajoute toute seule :

```
Signed-off-by: Prénom Nom <adresse@exemple.org>
```

En la posant, tu attestes que tu as écrit cet apport ou que tu as le droit de le soumettre sous la
licence du projet. Le texte de référence est le fichier [`DCO`](DCO) à la racine — le *Developer
Certificate of Origin* 1.1, celui du noyau Linux.

**Tu ne cèdes aucun droit.** Tu restes pleinement titulaire de tes lignes, et ton nom entre dans
[`AUTHORS`](AUTHORS). La signature atteste la provenance, elle ne transfère rien.

Elle te protège d'ailleurs autant que le projet : si un employeur, présent ou futur, revendiquait un
jour une contribution, l'attestation tranche la question d'avance, datée et signée.

Pour ne pas exposer ton adresse, utilise ton adresse noreply GitHub dans la signature comme dans
`user.email` (voir [Commits](#commits)).

### Ce que la règle couvre, et ce qu'elle ne couvre pas

⚖️ Elle vise les **apports extérieurs**, et le contrôle `dco` la vérifie sur les commits d'une demande
de fusion — là, et nulle part ailleurs.

L'historique antérieur au 9 octobre 2026 n'en relève pas : il est d'un seul auteur, qui est le
titulaire des droits. Il n'y a là aucune provenance à attester, et le réécrire pour y poser des
signatures rétroactives coûterait les empreintes d'un dépôt public sans rien prouver de plus.

🔑 Le texte dit donc « chaque commit **de ta demande de fusion** » et non « chaque commit ». La
nuance n'est pas cosmétique : une règle plus large que ce qu'on applique fait croire à une garantie
qui n'existe pas — et 917 commits seraient rétroactivement hors règle le jour où on l'écrit.

### Signer la clé en plus du nom — recommandé

Le certificat d'origine est une **déclaration** ; une signature cryptographique est une **preuve**.
Les deux se posent d'un seul geste :

```bash
git commit -s -S
```

Ce n'est pas exigé, et rien ne le refuse en son absence. Si tu le fais, vérifie que l'identité de ta
clé est celle que tu veux voir dans un historique public — une adresse noreply convient très bien.

## 🚀 Déploiement

Le déploiement en production est réservé au mainteneur (audit OPSEC intégré).
Les contributions passent par **Pull Request** sur `main`.

## Commits

Configure ton email git en **noreply GitHub** pour ne pas exposer ton adresse :
`git config user.email "<id>+<login>@users.noreply.github.com"`.
