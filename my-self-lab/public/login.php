<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/layout.php';
use Pierroons\MySelfLab\Db;
use Pierroons\MySelfLab\Auth;
render_header('Connexion', Auth::currentAccount(Db::pdo()));
?>
<h1>Connexion</h1>
<div class="card" style="max-width:420px">
  <div id="msg"></div>
  <div class="field"><label>Identifiant</label><input id="username" autocomplete="off"></div>
  <div class="field"><label>Mot de passe</label><input id="password" type="password"></div>
  <button class="btn" onclick="connecter()">Se connecter</button>
  <p class="muted" style="margin-top:12px">Pas de compte ? <a href="/register.php">En créer un</a> (sans email).</p>
</div>
<script>
function connecter(){
  const username=document.getElementById('username').value.trim();
  const password=document.getElementById('password').value;
  fetch('/api/login.php',{method:'POST',headers:{'Content-Type':'application/json'},
    body:JSON.stringify({username,password})})
    .then(r=>r.json()).then(d=>{
      if(d.ok){location.href='/index.php';}
      else{document.getElementById('msg').innerHTML='<div class="toast err">'+(d.message||'Erreur')+'</div>';}
    });
}
</script>
<?php render_footer(); ?>
