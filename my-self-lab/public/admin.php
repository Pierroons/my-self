<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/admin.php';
require_once __DIR__ . '/../lib/layout.php';

use Pierroons\MySelfLab\Db;
use Pierroons\MySelfLab\Auth;
use Pierroons\MySelfLab\Admin;

$pdo = Db::pdo();
$account = Auth::currentAccount($pdo);

// Garde : réservé aux admins. Pas d'indice d'existence pour les non-admins.
if (!$account || empty($account['is_admin'])) {
    render_header('Espace', $account);
    echo '<div class="card"><h1>404</h1><p class="muted">Page introuvable.</p></div>';
    render_footer();
    exit;
}

$stats   = Admin::stats($pdo);
$comptes = Admin::accounts($pdo);
$fails   = Admin::failedLogins($pdo);
$blocked = Admin::blockedVotes($pdo);
$reports = Admin::reports($pdo);

$sevColor = ['info' => '#9aa9b6', 'faible' => '#6cb6ff', 'moyen' => '#d4a056', 'eleve' => '#e0824f', 'critique' => '#d96459'];
$statutColor = ['nouveau' => '#d4a056', 'valide' => '#3fb98c', 'rejete' => '#d96459'];
$dt = fn(int $t): string => $t ? date('d/m H:i', $t) : '—';

render_header('Administration', $account);
?>
<style>
.adm-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:10px;margin-bottom:18px}
.kpi{background:var(--card);border:1px solid var(--border);border-radius:10px;padding:12px 14px}
.kpi .n{font-size:22px;font-weight:700}
.kpi .l{font-size:11.5px;color:var(--muted);text-transform:uppercase;letter-spacing:.3px}
.kpi.alert .n{color:var(--danger)}
.kpi.warn .n{color:var(--warn)}
.adm-warn{background:rgba(108,182,255,.08);border:1px solid rgba(108,182,255,.3);border-radius:8px;padding:12px 16px;font-size:12.5px;color:var(--txt2);margin-bottom:18px}
table.adm{width:100%;border-collapse:collapse;font-size:12.5px}
table.adm th{text-align:left;color:var(--muted);font-weight:600;font-size:11px;text-transform:uppercase;letter-spacing:.3px;border-bottom:1px solid var(--border);padding:6px 8px}
table.adm td{padding:7px 8px;border-bottom:1px solid var(--border)}
table.adm tr:last-child td{border-bottom:none}
.tag-sm{font-size:10.5px;padding:1px 7px;border-radius:9px;border:1px solid;font-weight:600}
.mini{padding:3px 9px;font-size:11.5px}
.reveal{background:#0a0f13;border:1px solid var(--border);border-radius:6px;padding:10px 12px;margin-top:6px;font-family:ui-monospace,monospace;font-size:12px;white-space:pre-wrap;word-break:break-word}
.cols2{display:grid;grid-template-columns:1fr 1fr;gap:14px}
@media(max-width:760px){.cols2{grid-template-columns:1fr}}
</style>

<h1>🛠️ Administration <span class="muted" style="font-size:13px">— @<?= h($account['username']) ?></span></h1>
<div class="adm-warn">
  ℹ️ <strong>Deux modèles de chiffrement coexistent.</strong> Le profil (bio/localisation/lien) est <em>at-rest serveur</em> (blind key) : un dump est neutralisé, mais un admin qui détient la clé peut déchiffrer. Le <strong>mémo perso</strong> est passé en <strong>E2E client</strong> (vault per-user) : la clé ne quitte jamais le navigateur → <strong>même l'admin ne peut pas le lire</strong>. Ce panel reste une cible d'<strong>escalade de privilèges</strong> pour la red team.
</div>

<!-- KPI -->
<div class="adm-grid">
  <div class="kpi"><div class="n"><?= $stats['comptes'] ?></div><div class="l">Comptes</div></div>
  <div class="kpi"><div class="n"><?= $stats['comptes_24h'] ?></div><div class="l">Nouveaux 24h</div></div>
  <div class="kpi"><div class="n"><?= $stats['threads'] ?>/<?= $stats['posts'] ?></div><div class="l">Sujets/Posts</div></div>
  <div class="kpi"><div class="n"><?= $stats['dm'] ?></div><div class="l">DM</div></div>
  <div class="kpi <?= $stats['echecs_login_24h'] > 10 ? 'alert' : '' ?>"><div class="n"><?= $stats['echecs_login_24h'] ?></div><div class="l">Échecs login 24h</div></div>
  <div class="kpi <?= $stats['votes_bloques'] > 0 ? 'warn' : '' ?>"><div class="n"><?= $stats['votes_bloques'] ?></div><div class="l">Votes bloqués</div></div>
  <div class="kpi <?= $stats['rapports_nouveaux'] > 0 ? 'warn' : '' ?>"><div class="n"><?= $stats['rapports_nouveaux'] ?></div><div class="l">Rapports neufs</div></div>
</div>

<!-- Signaux d'attaque -->
<div class="cols2">
  <div class="card">
    <h2>🔓 Échecs de login récents <span class="muted" style="font-size:12px">(bruteforce ?)</span></h2>
    <?php if (!$fails): ?><p class="muted">Aucun échec récent.</p><?php else: ?>
    <table class="adm"><tr><th>Compte visé</th><th>IP</th><th>Quand</th></tr>
      <?php foreach ($fails as $f): ?>
        <tr><td><?= h($f['username']) ?></td><td><?= h((string) $f['ip']) ?></td><td><?= $dt((int) $f['attempted_at']) ?></td></tr>
      <?php endforeach; ?>
    </table><?php endif; ?>
  </div>
  <div class="card">
    <h2>⚖️ Votes neutralisés <span class="muted" style="font-size:12px">(Sybil / pack)</span></h2>
    <?php if (!$blocked): ?><p class="muted">Aucun vote bloqué.</p><?php else: ?>
    <table class="adm"><tr><th>Votant</th><th>Cible</th><th>Raison</th><th>Quand</th></tr>
      <?php foreach ($blocked as $b): ?>
        <tr><td><?= h($b['voter']) ?></td><td><?= h($b['target_type']) ?> #<?= (int) $b['target_id'] ?></td>
            <td><?= h((string) $b['blocked_reason']) ?></td><td><?= $dt((int) $b['created_at']) ?></td></tr>
      <?php endforeach; ?>
    </table><?php endif; ?>
  </div>
</div>

<!-- Comptes -->
<div class="card">
  <h2>👥 Comptes</h2>
  <p class="adm-warn" style="margin-top:0">⏱️ <strong>Timings réduits pour la démo</strong> (volontaire, pour test rapide) : anti-Sybil <strong>2 min</strong>, bans <strong>2 / 10 / 30 min</strong>. En production : 24 h / 24 h → 7 j → 30 j → permanent.</p>
  <table class="adm"><tr><th>ID</th><th>Identifiant</th><th>Réputation</th><th>Créé</th><th>Profil déchiffré</th><th>Modération</th></tr>
    <?php foreach ($comptes as $c): ?>
      <tr>
        <td><?= (int) $c['id'] ?></td>
        <td>@<?= h($c['username']) ?> <?php if ($c['is_admin']): ?><span class="tag-sm" style="color:var(--acc);border-color:var(--acc)">admin</span><?php endif; ?></td>
        <td>★ <?= (int) $c['reputation'] ?><?php if ((int) $c['banned_until'] > time()): ?> <span class="tag-sm" style="color:var(--danger);border-color:var(--danger)">banni</span><?php endif; ?></td>
        <td><?= $dt((int) $c['created_at']) ?></td>
        <td><button class="btn btn-ghost mini" onclick="voirProfil(<?= (int) $c['id'] ?>,this)">👁 voir (mémo)</button>
            <div id="prof-<?= (int) $c['id'] ?>"></div></td>
        <td style="white-space:nowrap">
          <button class="btn btn-ghost mini" onclick="modAction(<?= (int) $c['id'] ?>,'ban')">🔨 bannir</button>
          <button class="btn mini" onclick="modAction(<?= (int) $c['id'] ?>,'pardon')">✅ gracier</button>
        </td>
      </tr>
    <?php endforeach; ?>
  </table>
</div>

<!-- Rapports red team -->
<div class="card">
  <h2>📨 Rapports red team</h2>
  <?php if (!$reports): ?><p class="muted">Aucun rapport reçu.</p><?php else: ?>
  <table class="adm"><tr><th>#</th><th>Pseudo</th><th>Sévérité</th><th>Cible</th><th>Statut</th><th>Reçu</th><th>Actions</th></tr>
    <?php foreach ($reports as $r): ?>
      <tr>
        <td><?= (int) $r['id'] ?></td>
        <td><?= h((string) $r['handle']) ?: '<span class="muted">anonyme</span>' ?></td>
        <td><span class="tag-sm" style="color:<?= $sevColor[$r['severity']] ?? '#9aa9b6' ?>;border-color:<?= $sevColor[$r['severity']] ?? '#9aa9b6' ?>"><?= h($r['severity']) ?></span></td>
        <td><?= h((string) $r['target']) ?></td>
        <td><span class="tag-sm" style="color:<?= $statutColor[$r['status']] ?? '#9aa9b6' ?>;border-color:<?= $statutColor[$r['status']] ?? '#9aa9b6' ?>"><?= h($r['status']) ?></span></td>
        <td><?= $dt((int) $r['created_at']) ?></td>
        <td style="white-space:nowrap">
          <button class="btn btn-ghost mini" onclick="voirRapport(<?= (int) $r['id'] ?>,this)">🔓 lire</button>
          <button class="btn mini" onclick="statutRapport(<?= (int) $r['id'] ?>,'valide')">✓ valider</button>
          <button class="btn btn-ghost mini" onclick="statutRapport(<?= (int) $r['id'] ?>,'rejete')">✗</button>
          <div id="rep-<?= (int) $r['id'] ?>"></div>
        </td>
      </tr>
    <?php endforeach; ?>
  </table><?php endif; ?>
</div>

<script>
function esc(s){return String(s==null?'':s).replace(/[&<>]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;'}[c]));}
function voirProfil(id,btn){
  const box=document.getElementById('prof-'+id);
  labPost('/api/admin.php',{action:'profile',account_id:id}).then(d=>{
    if(!d.ok){box.innerHTML='<div class="reveal">'+esc(d.message)+'</div>';return;}
    const p=d.profile.profil;
    const memoLigne = d.profile.memo_e2e
      ? '🎯 mémo : 🔒 chiffré E2E — illisible même pour l\'admin (aucune clé côté serveur)'
      : '🎯 mémo : (pas de coffre E2E)';
    box.innerHTML='<div class="reveal">'+memoLigne+'\nbio : '+(esc(p.bio)||'—')+'\nloc : '+(esc(p.localisation)||'—')+'\nlien : '+(esc(p.lien)||'—')+'</div>';
  });
}
function voirRapport(id,btn){
  const box=document.getElementById('rep-'+id);
  labPost('/api/admin.php',{action:'report',id:id}).then(d=>{
    if(!d.ok){box.innerHTML='<div class="reveal">'+esc(d.message)+'</div>';return;}
    const r=d.report;
    box.innerHTML='<div class="reveal"><b>'+esc(r.titre)+'</b>\n\n'+esc(r.description)+'\n\n— repro —\n'+(esc(r.repro)||'(aucune)')+'\n\ncontact : '+(esc(r.contact)||'—')+'</div>';
  });
}
function statutRapport(id,status){
  labPost('/api/admin.php',{action:'report_status',id:id,status:status}).then(d=>{
    if(d.ok){location.reload();}
  });
}
function modAction(id,op){
  if(op==='ban' && !confirm('Bannir ce compte (durée démo) ?')) return;
  labPost('/api/admin.php',{action:'moderate',account_id:id,op:op}).then(d=>{
    if(d.ok){ location.reload(); } else { alert(d.message||'Erreur'); }
  });
}
</script>
<?php render_footer(); ?>
