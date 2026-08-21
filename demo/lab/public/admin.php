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
$episodes = Admin::packEpisodes($pdo);
$reports = Admin::reports($pdo);
$demandes = Admin::pendingRequests($pdo);
$disputes = \Pierroons\MySelfLab\RecoverL3::adminList($pdo)['disputes'] ?? [];

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

<!-- Épisodes de meute -->
<div class="card">
  <h2>🐺 Épisodes de meute <span class="muted" style="font-size:12px">(rang par votant, pas par cible)</span></h2>
  <?php if (!$episodes): ?><p class="muted">Aucun épisode enregistré.</p><?php else: ?>
  <table class="adm"><tr><th>Votant</th><th>Cible</th><th>Rang</th><th>Suite donnée</th><th>Quand</th></tr>
    <?php foreach ($episodes as $e): ?>
      <tr><td><?= h((string) $e['votant']) ?></td><td><?= h((string) $e['cible']) ?></td>
          <td><?= (int) $e['rang'] ?></td>
          <td><?= h((string) $e['action']) ?></td>
          <td><?= $dt((int) $e['detected_at']) ?></td></tr>
    <?php endforeach; ?>
  </table><?php endif; ?>
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
        <td><button class="btn btn-ghost mini js-voirprofil" data-id="<?= (int) $c['id'] ?>">👁 voir (mémo)</button>
            <div id="prof-<?= (int) $c['id'] ?>"></div></td>
        <td style="white-space:nowrap">
          <button class="btn btn-ghost mini js-modaction" data-id="<?= (int) $c['id'] ?>" data-op="ban">🔨 bannir</button>
          <button class="btn mini js-modaction" data-id="<?= (int) $c['id'] ?>" data-op="pardon">✅ gracier</button>
        </td>
      </tr>
    <?php endforeach; ?>
  </table>
</div>

<!-- Promotions : proposer, pas promouvoir -->
<div class="card">
  <h2>🔑 Promotions administrateur</h2>
  <p class="adm-warn" style="margin-top:0">
    Cette page <strong>ne promeut personne</strong>. Un administrateur propose, le
    super-utilisateur tranche depuis le serveur — il n'a pas d'interface distante,
    et son accord s'inscrit au journal avant d'être appliqué.
    Décision&nbsp;: <code>selfrecover-su approve-request &lt;id&gt; "&lt;observation&gt;"</code>.
  </p>

  <?php if ($demandes): ?>
    <table class="adm"><tr><th>#</th><th>Demandeur</th><th>Cible</th><th>Motif</th><th>Déposée</th></tr>
      <?php foreach ($demandes as $d): ?>
        <tr>
          <td><?= (int) $d['id'] ?></td>
          <td>@<?= h($d['requester_username']) ?></td>
          <td>@<?= h($d['target_username']) ?></td>
          <td><?= h((string) $d['reason']) ?></td>
          <td><?= $dt((int) $d['created_at']) ?></td>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php else: ?>
    <p class="muted">Aucune demande en attente.</p>
  <?php endif; ?>

  <div style="margin-top:14px;display:flex;gap:8px;flex-wrap:wrap;align-items:flex-start">
    <input id="promo-cible" type="text" placeholder="identifiant du compte" autocomplete="off"
           style="flex:0 0 190px;padding:7px 10px;border-radius:8px;border:1px solid var(--border);background:var(--card);color:inherit">
    <input id="promo-motif" type="text" placeholder="pourquoi ce compte doit être promu"
           style="flex:1 1 280px;padding:7px 10px;border-radius:8px;border:1px solid var(--border);background:var(--card);color:inherit">
    <button class="btn" id="promo-envoi">Déposer la demande</button>
  </div>
  <div id="promo-retour" class="muted" style="font-size:12.5px;margin-top:8px"></div>
</div>

<!-- Rapports red team -->
<div class="card">
  <h2>⚖️ Litiges — récupération niveau 3</h2>
  <p class="muted" style="margin-top:0;font-size:12.5px">Accepter n'ouvre aucun accès : la décision est tracée, la remise en main se fait hors ligne. Un bouton qui rendrait le compte serait exactement le chemin automatique que ce niveau existe pour éviter.</p>
  <?php if (!$disputes): ?><p class="muted">Aucun litige déposé.</p><?php else: ?>
  <table class="adm"><tr><th>Litige</th><th>Compte revendiqué</th><th>Statut</th><th>Ouvert</th><th>Actions</th></tr>
    <?php foreach ($disputes as $d): ?>
      <tr>
        <td><code><?= h((string) $d['dispute_number']) ?></code></td>
        <td>@<?= h((string) $d['username']) ?></td>
        <td><span class="tag-sm"><?= h((string) $d['status']) ?></span>
          <?php if ((int) ($d['init_collisions'] ?? 0) > 0): ?>
            <span class="tag-sm" style="color:var(--warn)" title="Plusieurs personnes ont ouvert une procédure pour ce compte">multi-demandeur ×<?= (int) $d['init_collisions'] ?></span>
          <?php endif; ?>
        </td>
        <td class="muted"><?= date('d/m H:i', (int) $d['created_at']) ?></td>
        <td>
          <?php if (in_array($d['status'], ['open', 'awaiting_admin'], true)): ?>
            <button class="btn btn-ghost js-decider" data-n="<?= h((string) $d['dispute_number']) ?>" data-v="grant" style="padding:3px 9px;font-size:12px;color:var(--acc)">Confirmer l'identité</button>
            <button class="btn btn-ghost js-decider" data-n="<?= h((string) $d['dispute_number']) ?>" data-v="refuse" style="padding:3px 9px;font-size:12px;color:var(--danger)">Refuser</button>
          <?php else: ?>
            <span class="muted" style="font-size:12px">—</span>
          <?php endif; ?>
        </td>
      </tr>
      <!--
        Le faisceau est affiché tel quel, sans total ni note globale : agréger
        ces faits en un chiffre ferait lire le chiffre à la place des faits.
      -->
      <tr><td colspan="5">
        <?php $sig = $d['signals'] ?? []; ?>
        <?php if (!$sig): ?>
          <span class="muted" style="font-size:12px">Faisceau pas encore soumis.</span>
        <?php else: ?>
          <div style="display:flex;gap:18px;flex-wrap:wrap;font-size:12.5px">
            <div style="flex:1;min-width:240px">
              <strong style="color:var(--acc)">Passif</strong> <span class="muted">— constaté par le serveur, non falsifiable</span>
              <ul style="margin:4px 0 0;padding-left:18px">
                <?php foreach (($sig['passive'] ?? []) as $f): ?>
                  <li><?= ($f['ok'] ?? false) ? '✅' : '⬜' ?> <?= h((string) ($f['label'] ?? '')) ?> <span class="muted">— <?= h((string) ($f['detail'] ?? '')) ?></span></li>
                <?php endforeach; ?>
              </ul>
            </div>
            <div style="flex:1;min-width:240px">
              <strong style="color:var(--warn)">Déclaratif</strong> <span class="muted">— affirmé par le demandeur, devinable</span>
              <ul style="margin:4px 0 0;padding-left:18px">
                <?php foreach (($sig['declarative'] ?? []) as $f): ?>
                  <?php // Le déclaratif porte « dit » et « reel » : afficher les deux est
                        // tout l'intérêt de cet axe — une coche seule ne se relit pas. ?>
                  <li><?= ($f['ok'] ?? false) ? '✅' : '⬜' ?> <?= h((string) ($f['label'] ?? '')) ?>
                    <span class="muted">— déclaré <strong><?= h((string) ($f['dit'] ?? $f['detail'] ?? '—')) ?></strong>
                    <?php if (isset($f['reel'])): ?> · réel <strong><?= h((string) $f['reel']) ?></strong><?php endif; ?></span></li>
                <?php endforeach; ?>
              </ul>
            </div>
          </div>
          <p class="muted" style="font-size:12px;margin:8px 0 0">
            <?php // Un décompte de concordances, pas une note : il résume ce qui est
                  // au-dessus sans prétendre trancher à la place du relecteur. ?>
            <?php if (!empty($sig['summary'])): ?><strong><?= h((string) $sig['summary']) ?></strong> · <?php endif; ?>
            Échecs L2 antérieurs depuis la même connexion : <?= (int) ($d['l2_prior_attempts'] ?? 0) ?>
            · Refus déjà prononcés : <?= (int) ($d['refusal_count'] ?? 0) ?>
          </p>
        <?php endif; ?>
      </td></tr>
      <?php endforeach; ?>
  </table>
  <?php endif; ?>
</div>

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
          <button class="btn btn-ghost mini js-voirrapport" data-id="<?= (int) $r['id'] ?>">🔓 lire</button>
          <button class="btn mini js-statut" data-id="<?= (int) $r['id'] ?>" data-status="valide">✓ valider</button>
          <button class="btn btn-ghost mini js-statut" data-id="<?= (int) $r['id'] ?>" data-status="rejete">✗</button>
          <div id="rep-<?= (int) $r['id'] ?>"></div>
        </td>
      </tr>
    <?php endforeach; ?>
  </table><?php endif; ?>
</div>

<script nonce="<?= nonce() ?>">
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
    // Le contenu n'est PAS déchiffrable ici : la clé privée n'est pas sur ce
    // serveur, et c'est précisément la garantie faite aux chercheurs. Le panneau
    // affiche le bloc à copier ; le déchiffrement se fait hors ligne.
    if(!r.chiffre){ box.innerHTML='<div class="reveal">'+esc(r.pgp)+'</div>'; return; }
    box.innerHTML='<div class="reveal" style="font-size:11px;max-height:220px;overflow:auto">'+esc(r.pgp)+'</div>'
      +'<p class="muted" style="font-size:12px;margin:6px 0 0">Chiffré vers la clé du programme. '
      +'Pour lire : copier le bloc puis <code>gpg -d</code> sur une machine qui détient la clé privée — jamais ici.</p>'
      +'<p><button class="btn btn-ghost mini js-copierpgp">Copier le bloc</button></p>';
    const cp=box.querySelector('.js-copierpgp');
    if(cp) cp.addEventListener('click',function(){
      navigator.clipboard.writeText(r.pgp).then(function(){ cp.textContent='Copié ✓'; });
    });
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
document.querySelectorAll('.js-voirprofil').forEach(b=>b.addEventListener('click',()=>voirProfil(+b.dataset.id,b)));
document.querySelectorAll('.js-voirrapport').forEach(b=>b.addEventListener('click',()=>voirRapport(+b.dataset.id,b)));
document.querySelectorAll('.js-statut').forEach(b=>b.addEventListener('click',()=>statutRapport(+b.dataset.id,b.dataset.status)));
document.querySelectorAll('.js-modaction').forEach(b=>b.addEventListener('click',()=>modAction(+b.dataset.id,b.dataset.op)));

function deciderDispute(num, decision){
  // Confirmer n'ouvre AUCUN accès : la décision est enregistrée, et c'est le
  // propriétaire qui repose ensuite ses secrets depuis son fil de litige.
  if(!confirm(decision==='grant'
      ? 'Confirmer l\'identité pour '+num+' ?\n\nCela n\'ouvre pas le compte : le propriétaire reposera lui-même ses secrets.'
      : 'Refuser la demande '+num+' ?')) return;
  labPost('/api/admin_dispute_decide.php',{dispute_number:num,decision:decision}).then(d=>{
    if(d.ok) location.reload(); else alert(d.message||'Échec');
  });
}
function deposerPromotion(){
  var cible = document.getElementById('promo-cible').value.trim();
  var motif = document.getElementById('promo-motif').value.trim();
  var out = document.getElementById('promo-retour');
  out.textContent = '…';
  labPost('/api/admin_request_promotion.php',{target:cible,reason:motif}).then(d=>{
    out.textContent = d.message || (d.ok ? 'Demande déposée.' : 'Échec.');
    out.style.color = d.ok ? 'var(--ok, #3fb98c)' : 'var(--danger)';
    if(d.ok) setTimeout(()=>location.reload(), 1200);
  });
}
document.getElementById('promo-envoi').addEventListener('click', deposerPromotion);

document.querySelectorAll('.js-decider').forEach(b=>b.addEventListener('click',()=>deciderDispute(b.dataset.n,b.dataset.v)));
</script>
<?php render_footer(); ?>
