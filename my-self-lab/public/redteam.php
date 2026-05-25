<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/redteam.php';
require_once __DIR__ . '/../lib/layout.php';

use Pierroons\MySelfLab\Db;
use Pierroons\MySelfLab\Auth;
use Pierroons\MySelfLab\Redteam;

$pdo = Db::pdo();
$account = Auth::currentAccount($pdo);
$hof = Redteam::hallOfFame($pdo);

$sevColor = [
    'info' => '#9aa9b6', 'faible' => '#6cb6ff', 'moyen' => '#d4a056',
    'eleve' => '#e0824f', 'critique' => '#d96459',
];

render_header('Règles d\'engagement red team', $account);
?>
<style>
.rt-hero{background:linear-gradient(120deg,rgba(63,185,140,.10),rgba(217,100,89,.06));border:1px solid var(--border);border-radius:12px;padding:20px 22px;margin-bottom:20px}
.rt-hero h1{margin:0 0 8px}
.rt-hero p{margin:0;color:var(--txt2);max-width:760px}
.rt-sec{margin-bottom:18px}
.rt-sec h2{display:flex;align-items:center;gap:8px}
.scope{display:grid;grid-template-columns:1fr 1fr;gap:12px}
@media(max-width:680px){.scope{grid-template-columns:1fr}}
.scope .col{border-radius:8px;padding:14px 16px;font-size:13px}
.scope .in{background:rgba(63,185,140,.08);border:1px solid rgba(63,185,140,.3)}
.scope .out{background:rgba(217,100,89,.07);border:1px solid rgba(217,100,89,.3)}
.scope h3{margin:0 0 8px;font-size:13px;text-transform:uppercase;letter-spacing:.4px}
.scope.in h3,.scope .in h3{color:var(--acc)}
.scope .out h3{color:var(--danger)}
.scope ul{margin:0;padding-left:18px;line-height:1.7}
.flag{background:#0a0f13;border:1px solid var(--warn);border-radius:8px;padding:14px 16px;font-family:ui-monospace,monospace;font-size:13px;margin:10px 0}
.flag .lbl{color:var(--warn);font-weight:700}
.obj-list{list-style:none;padding:0;margin:0}
.obj-list li{padding:8px 0;border-bottom:1px solid var(--border);font-size:13.5px}
.obj-list li:last-child{border-bottom:none}
.ttp{display:inline-block;font-family:ui-monospace,monospace;font-size:10.5px;color:var(--muted);border:1px solid var(--border);border-radius:8px;padding:0 7px;margin-left:6px;white-space:nowrap}
.two{display:grid;grid-template-columns:1fr 1fr;gap:12px}
@media(max-width:680px){.two{grid-template-columns:1fr}}
.ok-box{background:rgba(63,185,140,.07);border:1px solid rgba(63,185,140,.3);border-radius:8px;padding:14px 16px}
.no-box{background:rgba(217,100,89,.07);border:1px solid rgba(217,100,89,.3);border-radius:8px;padding:14px 16px}
.ok-box h3{color:var(--acc)} .no-box h3{color:var(--danger)}
.ok-box h3,.no-box h3{margin:0 0 8px;font-size:13px;text-transform:uppercase;letter-spacing:.4px}
.ok-box ul,.no-box ul{margin:0;padding-left:18px;line-height:1.7;font-size:13px}
.safe{background:rgba(108,182,255,.08);border:1px solid rgba(108,182,255,.3);border-radius:8px;padding:14px 16px;font-size:13.5px}
.hof{display:flex;flex-wrap:wrap;gap:10px}
.hof .badge{background:var(--card);border:1px solid var(--border);border-radius:20px;padding:6px 14px;font-size:13px}
.hof .badge .sev{font-size:11px;margin-left:6px}
.rt-form .field input,.rt-form .field textarea,.rt-form .field select{width:100%}
.hp{position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden}
</style>

<div class="rt-hero">
  <h1>🎯 Test red team — règles d'engagement</h1>
  <p>MySelf-Lab est une vitrine <strong>volontairement exposée à l'attaque</strong>. Inversion d'OWASP Juice Shop :
  ici l'application est <strong>protégée par l'écosystème MySelf</strong> (SelfRecover, SelfDataGuard, SelfModerate)
  et l'objectif est de prouver — ou de mettre en défaut — cette protection en conditions réelles.
  Toute personne respectant les règles ci-dessous est <strong>autorisée</strong> à mener ses recherches.</p>
</div>

<div class="card rt-sec">
  <h2>📍 Périmètre</h2>
  <div class="scope">
    <div class="col in">
      <h3>✅ Dans le périmètre</h3>
      <ul>
        <li>L'application web MySelf-Lab (ce site) et tous ses chemins</li>
        <li>L'authentification <strong>SelfRecover</strong> (inscription, connexion, récupération)</li>
        <li>Les messages privés et profils chiffrés <strong>SelfDataGuard</strong></li>
        <li>La réputation et le vote <strong>SelfModerate</strong></li>
        <li>Le formulaire de soumission de rapport ci-dessous</li>
      </ul>
    </div>
    <div class="col out">
      <h3>⛔ Hors périmètre</h3>
      <ul>
        <li>Toute autre infrastructure, domaine ou service que ce site</li>
        <li>L'hébergeur, le registrar, les fournisseurs tiers</li>
        <li>Les comptes ou données de personnes réelles</li>
        <li>Le poste, les comptes et la messagerie du mainteneur</li>
      </ul>
    </div>
  </div>
  <p class="muted" style="margin:12px 0 0">Un périmètre étendu (autres composants MySelf) peut être convenu <strong>en privé</strong> avec une équipe retenue, sous accord écrit. Il n'est pas publié ici.</p>
</div>

<div class="card rt-sec">
  <h2>🏁 Objectifs (capture-the-flag)</h2>
  <p>Un compte de démonstration contient, dans son <strong>mémo personnel</strong>, un secret au format :</p>
  <div class="flag"><span class="lbl">FLAG-</span>… (chiffré <strong>de bout en bout côté client</strong> — AES-256-GCM, clé dérivée du secret de l'utilisateur, <strong>jamais présente sur le serveur</strong>)</div>
  <p class="muted">Le nom du compte cible te sera communiqué à l'ouverture du test. Ni un dump de la base, ni un accès administrateur, ni la prise de contrôle du serveur ne révèlent ce secret — la clé n'existe que dans le navigateur du propriétaire. Le défi est de le ramener <strong>en clair</strong>.</p>
  <p class="muted" style="margin:0 0 4px">Réfs <strong>MITRE ATT&amp;CK</strong> / <strong>OWASP</strong> indiquées par objectif (les vulns web applicatives relèvent d'OWASP/CWE, hors périmètre ATT&amp;CK).</p>
  <ul class="obj-list">
    <li>🎯 <strong>Exfiltrer le mémo secret</strong> du compte cible et le restituer en clair <span class="ttp">objectif central</span></li>
    <li>🔓 <strong>Contourner l'authentification</strong> SelfRecover (prendre la main sur un compte sans son mot de passe) <span class="ttp">ATT&amp;CK T1110 · T1078 · OWASP A07</span></li>
    <li>💬 <strong>Lire un DM</strong> échangé entre deux autres membres, en clair <span class="ttp">OWASP A01</span></li>
    <li>⚖️ <strong>Manipuler la réputation</strong> SelfModerate (enterrer un membre par faux comptes coordonnés, ou s'auto-promouvoir) <span class="ttp">CAPEC-210</span></li>
    <li>🪪 <strong>Usurper une session</strong> ou aboutir une attaque CSRF authentifiée <span class="ttp">ATT&amp;CK T1539 · CWE-352</span></li>
    <li>🧨 <strong>Escalade de privilèges</strong> : obtenir un accès administrateur (panel <code>/admin</code>) <span class="ttp">ATT&amp;CK T1078 · OWASP A01</span></li>
  </ul>
</div>

<div class="rt-sec two">
  <div class="ok-box">
    <h3>✅ Autorisé</h3>
    <ul>
      <li>Tests applicatifs web : injection (SQL, commande), XSS, IDOR, contournement d'auth, logique métier</li>
      <li>Analyse cryptographique des blobs SelfDataGuard</li>
      <li>Tentatives de dump de la base via une faille applicative</li>
      <li>Fuzzing raisonné des endpoints</li>
      <li>Interception / rejeu dans le périmètre</li>
    </ul>
  </div>
  <div class="no-box">
    <h3>⛔ Interdit</h3>
    <ul>
      <li>Déni de service, flood, stress volumétrique (DoS / DDoS)</li>
      <li>Ingénierie sociale visant des personnes réelles, le mainteneur, l'hébergeur</li>
      <li>Attaques physiques ou sur des locaux</li>
      <li>Pivot ou scan hors du périmètre déclaré</li>
      <li>Destruction, chiffrement (ransomware) ou altération définitive de données</li>
      <li>Exfiltration massive au-delà de la preuve ; spam ; contenu illégal</li>
    </ul>
  </div>
</div>

<div class="card rt-sec">
  <h2>🧭 Conduite responsable</h2>
  <ul style="margin:0;padding-left:18px;line-height:1.8">
    <li>Sur une faille <strong>critique</strong> : stopper l'exploitation, sécuriser une preuve <em>minimale</em>, signaler sans délai.</li>
    <li>Une preuve = un extrait minimal (une capture, un enregistrement déchiffré) — pas un dump complet.</li>
    <li><strong>Divulgation coordonnée</strong> : pas de publication avant correction et accord mutuel. Délai de référence : <strong>90 jours</strong>.</li>
  </ul>
</div>

<div class="card rt-sec">
  <h2>🛟 Safe harbor</h2>
  <div class="safe">
    Tant que tes recherches respectent ces règles, nous les considérons comme <strong>autorisées et menées de bonne foi</strong>.
    Nous n'engagerons aucune action à ton encontre et nous ferons notre possible pour lever rapidement toute incertitude.
    En cas de doute sur le périmètre ou une technique : <strong>demande avant d'agir</strong> via le formulaire ci-dessous.
  </div>
</div>

<div class="card rt-sec rt-form" id="signaler" style="max-width:640px">
  <h2>📨 Soumettre un rapport</h2>
  <p class="muted">🔒 Le corps de ton rapport est chiffré at-rest par <strong>SelfDataGuard</strong> avant stockage : la base qui le contient ne révèle qu'un blob. Le module que tu testes protège aussi ton rapport.</p>
  <div id="rtmsg"></div>
  <div class="field"><label>Pseudo public (hall of fame, optionnel)</label><input id="handle" maxlength="60" placeholder="ex. @nom_ou_équipe"></div>
  <div class="two" style="gap:12px">
    <div class="field"><label>Sévérité</label>
      <select id="severity">
        <option value="info">Info</option><option value="faible">Faible</option>
        <option value="moyen">Moyen</option><option value="eleve">Élevé</option>
        <option value="critique">Critique</option>
      </select>
    </div>
    <div class="field"><label>Cible</label>
      <select id="target">
        <option value="memo">Mémo secret</option><option value="auth">Auth SelfRecover</option>
        <option value="dm">Messages privés</option><option value="moderation">Modération</option>
        <option value="web">Web / app</option><option value="autre">Autre</option>
      </select>
    </div>
  </div>
  <div class="field"><label>Titre *</label><input id="titre" maxlength="200" placeholder="Résumé en une ligne"></div>
  <div class="field"><label>Description *</label><textarea id="description" rows="4" placeholder="Impact, ce que tu as obtenu…"></textarea></div>
  <div class="field"><label>Étapes de reproduction</label><textarea id="repro" rows="4" placeholder="1. … 2. … 3. …"></textarea></div>
  <div class="field"><label>Contact (optionnel, chiffré)</label><input id="contact" maxlength="200" placeholder="Mastodon, clé PGP, email jetable…"></div>
  <div class="hp"><label>Ne pas remplir</label><input id="website" tabindex="-1" autocomplete="off"></div>
  <button class="btn" id="btn-rtsend">Envoyer (chiffré)</button>
</div>

<div class="card rt-sec">
  <h2>🏆 Hall of fame</h2>
  <?php if (!$hof): ?>
    <p class="muted">Aucune contribution validée pour l'instant. Sois la première personne à y figurer.</p>
  <?php else: ?>
    <div class="hof">
      <?php foreach ($hof as $c): ?>
        <span class="badge"><?= h($c['handle']) ?>
          <span class="sev" style="color:<?= $sevColor[$c['sev']] ?? '#9aa9b6' ?>">● <?= h($c['sev']) ?></span>
          <?php if ($c['nb'] > 1): ?><span class="muted">×<?= (int) $c['nb'] ?></span><?php endif; ?>
        </span>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<script nonce="<?= nonce() ?>">
function envoyerRapport(){
  const box=document.getElementById('rtmsg');
  const payload={
    handle:document.getElementById('handle').value,
    severity:document.getElementById('severity').value,
    target:document.getElementById('target').value,
    titre:document.getElementById('titre').value,
    description:document.getElementById('description').value,
    repro:document.getElementById('repro').value,
    contact:document.getElementById('contact').value,
    website:document.getElementById('website').value
  };
  // Endpoint public (pas d'auth requise pour un chercheur externe) — pas de CSRF.
  fetch('/api/redteam_report.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)})
    .then(r=>r.json()).then(d=>{
      if(d.ok){
        box.innerHTML='<div class="toast ok">Rapport reçu et chiffré. Merci — on revient vers toi.</div>';
        ['titre','description','repro','contact'].forEach(id=>document.getElementById(id).value='');
      }else{
        box.innerHTML='<div class="toast err">'+(d.message||'Erreur')+'</div>';
      }
    }).catch(e=>{box.innerHTML='<div class="toast err">Erreur réseau : '+e.message+'</div>';});
}
document.getElementById('btn-rtsend').addEventListener('click', envoyerRapport);
</script>
<?php render_footer(); ?>
