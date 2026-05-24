<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/forum.php';
require_once __DIR__ . '/../lib/layout.php';
use Pierroons\MySelfLab\Db;
use Pierroons\MySelfLab\Forum;
use Pierroons\MySelfLab\Auth;

$pdo = Db::pdo();
$account = Auth::currentAccount($pdo);
if (!$account) {
    header('Location: /login.php');
    exit;
}
render_header('Nouveau sujet', $account);
?>
<h1>Nouveau sujet</h1>
<div class="card" style="max-width:620px">
  <div id="msg"></div>
  <div class="field"><label>Titre</label><input id="titre" placeholder="Titre du sujet"></div>
  <div class="field"><label>Catégorie</label>
    <select id="categorie">
      <?php foreach (Forum::CATEGORIES as $key => $label): ?>
        <option value="<?= h($key) ?>"><?= h($label) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="field"><label>Premier message</label><textarea id="message" rows="6"></textarea></div>
  <button class="btn" onclick="publier()">Publier le sujet</button>
</div>
<script>
function publier(){
  const titre=document.getElementById('titre').value.trim();
  const categorie=document.getElementById('categorie').value;
  const message=document.getElementById('message').value.trim();
  labPost('/api/thread_create.php',{titre,categorie,message})
    .then(d=>{
      if(d.ok){location.href='/thread.php?id='+d.thread_id;}
      else{document.getElementById('msg').innerHTML='<div class="toast err">'+(d.message||'Erreur')+'</div>';}
    });
}
</script>
<?php render_footer(); ?>
