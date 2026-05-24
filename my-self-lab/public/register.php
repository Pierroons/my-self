<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/layout.php';
use Pierroons\MySelfLab\Db;
use Pierroons\MySelfLab\Auth;
render_header('Créer un compte', Auth::currentAccount(Db::pdo()));
?>
<h1>Créer un compte</h1>
<p class="muted">Sans email, sans téléphone. Auth par <strong>SelfRecover</strong> : tu choisis un mot de récupération, le serveur te génère un mot de passe + une passphrase à conserver.</p>

<div class="card" style="max-width:480px">
  <div id="msg"></div>
  <div id="form">
    <div class="field">
      <label>Identifiant</label>
      <input id="username" placeholder="3-20 caractères, minuscules/chiffres/_" autocomplete="off">
    </div>
    <div class="field">
      <label>Mot de récupération (tu le choisis, garde-le secret)</label>
      <input id="recovery" placeholder="ex : monchat2024" autocomplete="off">
    </div>
    <button class="btn" onclick="creer()">Créer mon compte</button>
  </div>
</div>

<script>
function creer(){
  const username=document.getElementById('username').value.trim();
  const recovery=document.getElementById('recovery').value.trim();
  fetch('/api/register.php',{method:'POST',headers:{'Content-Type':'application/json'},
    body:JSON.stringify({username,recovery_word:recovery})})
    .then(r=>r.json()).then(d=>{
      const msg=document.getElementById('msg');
      if(d.ok){
        document.getElementById('form').style.display='none';
        msg.innerHTML='<div class="toast ok"><strong>Compte créé !</strong> Copie ces secrets MAINTENANT (affichés une seule fois) :</div>'+
          '<div class="field"><label>Mot de passe</label><input value="'+d.credentials.password+'" readonly onclick="this.select()"></div>'+
          '<div class="field"><label>Passphrase de secours ('+d.credentials.entropy_bits+' bits)</label><input value="'+d.credentials.passphrase+'" readonly onclick="this.select()"></div>'+
          '<p class="muted">Ton mot de récupération, tu le connais déjà. Garde les trois en lieu sûr.</p>'+
          '<a class="btn" href="/login.php">Aller à la connexion →</a>';
      }else{
        msg.innerHTML='<div class="toast err">'+(d.message||'Erreur')+'</div>';
      }
    });
}
</script>
<?php render_footer(); ?>
