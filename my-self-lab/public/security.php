<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/layout.php';

use Pierroons\MySelfLab\Db;
use Pierroons\MySelfLab\Auth;

$account = Auth::currentAccount(Db::pdo());
render_header('Architecture de sécurité', $account);
?>
<style>
.sec-intro{background:linear-gradient(120deg,rgba(63,185,140,.10),transparent);border:1px solid var(--border);border-radius:10px;padding:16px 18px;margin-bottom:18px}
.sec-card{background:var(--card);border:1px solid var(--border);border-radius:10px;padding:16px 18px;margin-bottom:12px}
.sec-card h2{margin-top:0}
.sec-card ul{margin:6px 0 0;padding-left:18px;line-height:1.65}
.sec-card code{background:var(--elev);color:#9ad9bf;padding:1px 5px;border-radius:3px;font-size:12.5px}
.mt{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin:8px 0}
@media(max-width:680px){.mt{grid-template-columns:1fr}}
.mt .ok{background:rgba(63,185,140,.08);border:1px solid rgba(63,185,140,.3);border-radius:8px;padding:12px 14px}
.mt .no{background:rgba(217,100,89,.08);border:1px solid rgba(217,100,89,.3);border-radius:8px;padding:12px 14px}
.mt h4{margin:0 0 6px;font-size:12px;text-transform:uppercase;letter-spacing:.4px}
.mt .ok h4{color:var(--acc)} .mt .no h4{color:var(--danger)}
.mt ul{margin:0;padding-left:16px;line-height:1.6;font-size:13px}
.pill{display:inline-block;font-size:11px;padding:1px 8px;border-radius:10px;border:1px solid var(--border);color:var(--txt2);margin-left:6px}
.roadmap{font-size:13px;color:var(--txt2)}
</style>

<h1>🔐 Architecture de sécurité</h1>
<div class="sec-intro">
  <strong>Transparence assumée.</strong> Cette page documente nos défenses <em>et leurs limites</em>.
  Pas de sécurité par l'obscurité : une red team mérite de savoir ce qu'elle attaque. Tout le code est
  sous licence <strong>AGPL-3.0</strong>. Pour le cadre du test, voir les <a href="/redteam.php">règles d'engagement</a> ;
  pour les démonstrations, l'<a href="/attacks.php">Attack Simulator</a>.
</div>

<div class="sec-card">
  <h2>1. Authentification — SelfRecover <span class="pill">sans email</span></h2>
  <ul>
    <li>Aucun email, aucun n° de téléphone. À l'inscription : mot de récupération choisi → <code>password</code> (16 car.) + passphrase diceware EFF générés côté serveur.</li>
    <li>Stockage : <code>bcrypt(password)</code>, <code>bcrypt(passphrase)</code>, <code>bcrypt(clé_dérivée)</code> — coût 12. <strong>Aucun secret en clair.</strong></li>
    <li>Dérivation par domaine : <code>HMAC-SHA256(secret, domaine ‖ sel_du_site)</code> → un secret hameçonné sur un autre domaine ne produit pas la bonne clé.</li>
    <li>Rate-limit progressif (5 échecs / 15 min) + limite d'inscription par IP (anti-énumération, anti-spam).</li>
  </ul>
</div>

<div class="sec-card">
  <h2>2. Chiffrement des données — deux modèles selon la sensibilité</h2>
  <p><strong>a) Blind-key serveur</strong> (profil : bio, localisation, lien) — AES-256-GCM, clé dérivée d'un secret serveur stocké <em>hors base et hors webroot</em>. Un dump SQL ne révèle que des blobs.</p>
  <p><strong>b) Bout-en-bout côté client</strong> (mémo perso) — chiffré dans le <strong>navigateur</strong> (WebCrypto). <code>PBKDF2</code> (600k) → <code>HKDF</code> par étiquette → une <code>vault_key</code> aléatoire chiffre le mémo, wrappée dans deux enveloppes (mot de passe + passphrase de secours). <strong>Le serveur ne détient aucune clé.</strong></p>
  <div class="mt">
    <div class="ok"><h4>✅ Ce que ça protège</h4><ul>
      <li>Blind-key : vol de disque, dump SQL, injection</li>
      <li>E2E : <strong>même</strong> un accès admin ou root sur le serveur → le mémo reste illisible</li>
    </ul></div>
    <div class="no"><h4>⛔ Ce que ça ne protège pas (V1, assumé)</h4><ul>
      <li>Blind-key : un admin / un RCE qui lit la clé peut déchiffrer le profil &amp; les DM</li>
      <li>E2E : un serveur compromis <em>persistant</em> servant un JS piégé captant le mot de passe au déverrouillage (faille du « code servi »)</li>
    </ul></div>
  </div>
</div>

<div class="sec-card">
  <h2>3. Le socle commun — mapping SelfRecover ⇄ SelfDataGuard</h2>
  <ul>
    <li>Un seul <strong>secret racine</strong> mémorisé, une <strong>primitive de dérivation partagée</strong>, des <strong>clés filles séparées par étiquette</strong> (<code>auth</code> / <code>data-enc</code> / <code>data-recover</code>).</li>
    <li>Règle d'or : <strong>jamais la même clé pour l'authentification et le chiffrement</strong> (le serveur voit l'auth, il ne doit jamais pouvoir déchiffrer).</li>
    <li>Récupération unifiée : le même mot/passphrase de secours rend l'accès <em>et</em> les données. La force du secours = <strong>entropie de l'entrée</strong> (passphrase diceware), pas la taille du hash.</li>
  </ul>
</div>

<div class="sec-card">
  <h2>4. Durcissement applicatif</h2>
  <ul>
    <li><strong>CSRF</strong> : jeton HMAC lié à la session, vérifié sur chaque action d'écriture (en-tête <code>X-CSRF-Token</code>).</li>
    <li><strong>En-têtes</strong> : <code>Content-Security-Policy</code>, <code>X-Frame-Options: DENY</code>, <code>X-Content-Type-Options: nosniff</code>, <code>Referrer-Policy</code>, <code>HSTS</code>.</li>
    <li><strong>Sessions</strong> : jeton aléatoire 192 bits, cookie <code>HttpOnly</code>/<code>SameSite=Lax</code>, TTL 24 h + purge.</li>
    <li><strong>Anti-énumération</strong> : messages d'erreur non discriminants, limite par IP.</li>
  </ul>
</div>

<div class="sec-card">
  <h2>5. Modération — SelfModerate <span class="pill">anti-manipulation</span></h2>
  <ul>
    <li>Réputation par membre (départ 20/30), vote ±1 sur les messages <em>et</em> les membres.</li>
    <li><strong>Anti-Sybil</strong> : un compte de moins de 24 h sans contribution ne peut pas voter.</li>
    <li><strong>Anti pack-voting</strong> : 3+ votes négatifs coordonnés en moins de 60 s → annulés, réputation restaurée.</li>
    <li><strong>Anti upvote-farming</strong> : votes mutuels répétés neutralisés. Sanctions graduées (perte du vote, bannissement 24 h → 7 j → 30 j → permanent).</li>
  </ul>
</div>

<div class="sec-card">
  <h2>6. Modèle de menace — honnête</h2>
  <div class="mt">
    <div class="ok"><h4>✅ Neutralisé</h4><ul>
      <li>Exfiltration de la base (dump, disque volé) — blobs</li>
      <li>Bruteforce de mot de passe (rate-limit)</li>
      <li>Manipulation de la modération (Sybil, pack-voting)</li>
      <li>CSRF, clickjacking, vol de session passif</li>
      <li>Vol du mémo, <strong>même avec un accès root</strong> (E2E au repos)</li>
    </ul></div>
    <div class="no"><h4>⚠️ Limites connues (V1)</h4><ul>
      <li>Profil &amp; DM lisibles par un admin / un RCE (blind-key)</li>
      <li>Serveur compromis <em>persistant</em> → altération du code servi (« code servi »)</li>
      <li>Métadonnées non chiffrées (qui parle à qui, quand)</li>
    </ul></div>
  </div>
</div>

<div class="sec-card">
  <h2>7. Feuille de route (hors V1)</h2>
  <p class="roadmap">E2E étendu aux messages privés et au profil · <code>Argon2id</code> en remplacement de PBKDF2 · <strong>superviseur d'intégrité externe</strong> (détection du code servi altéré + comportement anormal, confinement automatique réversible) · quorum distribué (Shamir) pour les clés critiques.</p>
</div>

<?php render_footer(); ?>
