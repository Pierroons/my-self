<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/layout.php';
use Pierroons\MySelfLab\Db;
use Pierroons\MySelfLab\Auth;
render_header('Récupération', Auth::currentAccount(Db::pdo()));
?>
<h1>Récupération d'accès <span class="muted" style="font-size:13px">— sans email</span></h1>
<div class="card" style="max-width:460px">
  <p class="muted" style="margin-top:0">Tu as perdu ton mot de passe ? Pas d'email à attendre : ton <strong>mot de récupération</strong> (choisi à l'inscription) suffit. On régénère un nouveau mot de passe que tu copies.</p>
  <div id="msg"></div>
  <div id="form">
    <div class="field"><label>Identifiant</label><input id="username" autocomplete="off"></div>
    <div class="field"><label>Mot de récupération</label><input id="recovery" type="password" autocomplete="off"></div>
    <button class="btn" id="btn-recup">Récupérer mon accès</button>
  </div>
  <div id="result" style="display:none"></div>
</div>
<p class="muted" style="max-width:460px"><a href="/login.php">← Retour à la connexion</a></p>
<script nonce="<?= nonce() ?>">
function esc(s){return String(s==null?'':s).replace(/[&<>]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;'}[c]));}
function recuperer(btn){
  const username=document.getElementById('username').value.trim();
  const recovery_word=document.getElementById('recovery').value;
  if(!username||!recovery_word){document.getElementById('msg').innerHTML='<div class="toast err">Identifiant et mot de récupération requis.</div>';return;}
  btn.disabled=true;
  fetch('/api/recover.php',{method:'POST',headers:{'Content-Type':'application/json'},
    body:JSON.stringify({username,recovery_word})})
    .then(r=>r.json()).then(d=>{
      btn.disabled=false;
      if(!d.ok){document.getElementById('msg').innerHTML='<div class="toast err">'+esc(d.message||'Erreur')+'</div>';return;}
      document.getElementById('form').style.display='none';
      document.getElementById('msg').innerHTML='<div class="toast ok">Accès récupéré ✔</div>';
      const r=document.getElementById('result');
      r.style.display='block';
      r.innerHTML='<p><strong>Copie ces identifiants maintenant</strong> (ils ne seront plus affichés) :</p>'
        +'<div class="field"><label>Nouveau mot de passe</label><input value="'+esc(d.credentials.password)+'" readonly></div>'
        +'<div class="field"><label>Passphrase</label><input value="'+esc(d.credentials.passphrase)+'" readonly></div>'
        +'<p class="muted">⚠️ '+esc(d.note||'')+'</p>'
        +'<a class="btn" href="/login.php">Aller à la connexion</a>';
    }).catch(e=>{btn.disabled=false;document.getElementById('msg').innerHTML='<div class="toast err">Erreur réseau.</div>';});
}
document.getElementById('btn-recup').addEventListener('click', function(){ recuperer(this); });
</script>
<?php render_footer(); ?>
