<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/dm.php';
require_once __DIR__ . '/../lib/layout.php';

use Pierroons\MySelfLab\Db;
use Pierroons\MySelfLab\DM;
use Pierroons\MySelfLab\Auth;

$pdo = Db::pdo();
$account = Auth::currentAccount($pdo);
if (!$account) {
    header('Location: /login.php');
    exit;
}
$inbox = DM::inbox($pdo, (int) $account['id']);
$sent = DM::sent($pdo, (int) $account['id']);
DM::markRead($pdo, (int) $account['id']);

render_header('Messages', $account);
?>
<h1>Messages privés</h1>
<p class="muted">🔒 Contenu chiffré at-rest par <strong>SelfDataGuard</strong> (AES-256-GCM). Un dump de la base ne révèle que des blobs illisibles.</p>

<div class="card" style="max-width:600px">
  <h2>Nouveau message</h2>
  <div id="msg"></div>
  <div class="field"><label>Destinataire (identifiant)</label><input id="dest" placeholder="ex : libriste"></div>
  <div class="field"><label>Message</label><textarea id="contenu" rows="3"></textarea></div>
  <button class="btn" onclick="envoyer()">Envoyer (chiffré)</button>
</div>

<h2 style="margin-top:24px">📥 Reçus (<?= count($inbox) ?>)</h2>
<?php if (!$inbox): ?>
  <p class="muted">Aucun message reçu.</p>
<?php else: foreach ($inbox as $m): ?>
  <div class="post">
    <div class="head"><span class="auteur">@<?= h($m['expediteur']) ?></span><span class="muted"><?= date('d/m/Y H:i', $m['created_at']) ?></span></div>
    <div class="contenu"><?= h($m['message']) ?></div>
  </div>
<?php endforeach; endif; ?>

<?php if ($sent): ?>
<h2 style="margin-top:24px">📤 Envoyés (<?= count($sent) ?>)</h2>
<?php foreach ($sent as $m): ?>
  <div class="post" style="opacity:.8">
    <div class="head">à <span class="auteur">@<?= h($m['destinataire']) ?></span><span class="muted"><?= date('d/m/Y H:i', $m['created_at']) ?></span></div>
    <div class="contenu"><?= h($m['message']) ?></div>
  </div>
<?php endforeach; endif; ?>

<script>
function envoyer(){
  const dest=document.getElementById('dest').value.trim();
  const contenu=document.getElementById('contenu').value.trim();
  labPost('/api/dm_send.php',{destinataire:dest,message:contenu})
    .then(d=>{
      if(d.ok){location.reload();}
      else{document.getElementById('msg').innerHTML='<div class="toast err">'+(d.message||'Erreur')+'</div>';}
    });
}
</script>
<?php render_footer(); ?>
