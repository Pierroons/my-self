<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/moderate.php';
require_once __DIR__ . '/../lib/layout.php';

use Pierroons\MySelfLab\Db;
use Pierroons\MySelfLab\Moderate;
use Pierroons\MySelfLab\Auth;

$pdo = Db::pdo();
$account = Auth::currentAccount($pdo);
$blocked = Moderate::blockedVotes($pdo, 30);

render_header('Modération', $account);
?>
<h1>🛡️ Modération — SelfModerate</h1>
<p class="muted">Modération <strong>sans autorité centrale</strong> : la communauté vote, des défenses automatiques contrent la manipulation.</p>

<div class="card">
  <h2>Comment ça marche</h2>
  <ul style="color:var(--txt2);font-size:13px;line-height:1.7;margin:0;padding-left:18px">
    <li><strong>Réputation</strong> : chaque membre démarre à 20/30. Les votes ▲▼ sur ses posts et son profil la font évoluer.</li>
    <li><strong>Anti-Sybil</strong> : un compte trop récent (&lt;24 h) sans aucun message ne peut pas voter.</li>
    <li><strong>Anti upvote-farming</strong> : plus de 3 votes positifs répétés vers le même membre sur 60 jours sont neutralisés.</li>
    <li><strong>Anti pack-voting</strong> : 3 votes négatifs coordonnés (&lt;60 s) sur une même cible sont annulés et la réputation restaurée.</li>
    <li><strong>Sanctions graduées</strong> : réputation &lt;5 → perte du droit de vote ; ≤0 → suspension 24 h, puis 7 j, 30 j, définitive.</li>
  </ul>
</div>

<?php if ($account): ?>
<div class="card">
  <h2>Lancer la détection d'abus</h2>
  <p class="muted">Analyse les votes récents et annule les patterns de pack-voting détectés.</p>
  <div id="dmsg"></div>
  <button class="btn" id="btn-detecter">🔍 Détecter les abus maintenant</button>
</div>
<script nonce="<?= nonce() ?>">
function detecter(){
  labPost('/api/detect_abuse.php',{}).then(d=>{
    const m=document.getElementById('dmsg');
    if(d.ok){
      if(d.pack_detected){
        let html='<div class="toast ok">✓ '+d.cancelled_votes+' vote(s) annulé(s). Pack(s) :<ul>';
        d.packs.forEach(p=>{html+='<li>cible #'+p.target_author+' — '+p.voters.join(', ')+' (spread '+p.spread_s+'s)</li>';});
        html+='</ul></div>';
        m.innerHTML=html;
        setTimeout(()=>location.reload(),2500);
      } else {
        m.innerHTML='<div class="toast ok">Aucun pack-voting détecté sur la période récente.</div>';
      }
    } else { m.innerHTML='<div class="toast err">'+(d.message||'Erreur')+'</div>'; }
  });
}
document.getElementById('btn-detecter').addEventListener('click', detecter);
</script>
<?php else: ?>
<div class="card"><p class="muted"><a href="/login.php">Connecte-toi</a> pour lancer la détection d'abus.</p></div>
<?php endif; ?>

<div class="card">
  <h2>Votes neutralisés (<?= count($blocked) ?>)</h2>
  <?php if (!$blocked): ?>
    <p class="muted">Aucun vote bloqué pour l'instant.</p>
  <?php else: ?>
    <table style="width:100%;border-collapse:collapse;font-size:12.5px">
      <thead><tr style="text-align:left;color:var(--muted)"><th style="padding:6px 8px">Date</th><th>Votant</th><th>Cible</th><th>Type</th><th>Raison</th></tr></thead>
      <tbody>
      <?php foreach ($blocked as $b): ?>
        <tr style="border-top:1px solid var(--border)">
          <td style="padding:6px 8px"><?= date('d/m H:i', (int) $b['created_at']) ?></td>
          <td>@<?= h($b['voter']) ?></td>
          <td>@<?= h($b['cible']) ?></td>
          <td><?= $b['value'] == 1 ? '▲' : '▼' ?> <?= h($b['target_type']) ?></td>
          <td><span style="color:var(--danger)"><?= h($b['blocked_reason']) ?></span></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>
<?php render_footer(); ?>
