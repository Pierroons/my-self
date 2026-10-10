<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/layout.php';

use Pierroons\MySelfLab\Db;
use Pierroons\MySelfLab\Auth;
use Pierroons\MySelfLab\Security;

$pdo = Db::pdo();
$account = Auth::currentAccount($pdo);

// Garde : réservé aux admins. Pas d'indice d'existence pour les non-admins.
if (!$account || empty($account['is_admin'])) {
    // Le statut doit dire ce que la page affiche : voir `admin.php`, même garde.
    http_response_code(404);
    render_header('Espace', $account);
    echo '<div class="card"><h1>404</h1><p class="muted">Page introuvable.</p></div>';
    render_footer();
    exit;
}
$csrf = Security::csrfToken(session_token() ?? '');
render_header('Litiges L3', $account);
?>
<h1>Récupérations assistées <span class="muted" style="font-size:13px">— litiges L3</span></h1>
<p class="muted" style="max-width:640px">Le faisceau ci-dessous = <strong>des faits bruts</strong>, jamais un score. Ils t'aident à décider ; ils n'ouvrent rien tout seuls. <strong>Accorder</strong> = tu confirmes l'identité, le demandeur re-choisira lui-même son secret (aucun mot de passe transmis). <strong>Refuser</strong> = ce demandeur n'a pas convaincu. <strong>Le compte n'est pas touché</strong> : ses secrets restent intacts. Mais un titulaire authentique qui arrive ici n'a plus ni mot de passe, ni passphrase, ni feuille de codes : un refus le laisse dehors jusqu'à ce qu'il rouvre un dossier, et l'arbitrage sera à refaire. Les refus sur un compte sont comptés et te sont signalés&nbsp;; <strong>aucun gel ne se pose tout seul</strong> — geler l'ouverture est ton geste, et il se lève.</p>
<div id="list"><p class="muted">chargement…</p></div>

<script nonce="<?= nonce() ?>">
var CSRF='<?= h($csrf) ?>';
var GEL=<?= json_encode(\Pierroons\MySelfLab\RecoverL3::reglesDuGel($pdo), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
function esc(s){return String(s==null?'':s).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));}
function $(id){return document.getElementById(id);}
// ⚠️ Le `.catch` n'est pas décoratif : un lissage de débit répond une page HTML
// (429), pas du JSON, donc `r.json()` rejette. Sans lui, le panneau d'un fil
// restait VIDE sans erreur — l'arbitre lisait « aucun message » sur un fil qui
// en portait. Un refus doit se voir.
function get(u){return fetch(u,{headers:{'X-CSRF-Token':CSRF}})
  .then(r=>r.json().catch(()=>({ok:false,message:'Réponse illisible ('+r.status+').'})))
  .catch(()=>({ok:false,message:'Serveur injoignable.'}));}
function post(u,p){return fetch(u,{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':CSRF},body:JSON.stringify(p)})
  .then(r=>r.json().catch(()=>({ok:false,message:'Réponse illisible ('+r.status+').'})))
  .catch(()=>({ok:false,message:'Serveur injoignable.'}));}

// ⚠️ Trois états, pas deux. « indisponible » n'est PAS une divergence : il dit
// que le serveur n'a rien enregistré sur ce point. Le rendre comme un ❌ ferait
// arriver à charge le dossier d'une personne légitime qui a répondu juste.
var ETATS = {concorde:'✅', diverge:'❌', indisponible:'—'};
var LIBELLES = {annee_creation:'Année de création', mois_connexion:'Dernière connexion (mois)',
                frequence:'Fréquence d\'usage'};

function facts(sig){
  if(!sig) return '';
  var ctx = sig.contexte||{};
  var lignes = [
    ['Compte créé le', ctx.compte_cree_le],
    ['Dernière connexion', ctx.derniere_connexion===null?'non enregistrée':ctx.derniere_connexion],
    ['Nombre de connexions', ctx.nombre_connexions===null?'non enregistré':ctx.nombre_connexions],
    ['Codes de niveau 2 restants', ctx.codes_l2_restants],
    ['Refus précédents (30 j)', ctx.refus_precedents]
  ].map(l=>'<tr><td>contexte</td><td>'+esc(l[0])+'</td><td></td><td class="muted">'+esc(l[1])+'</td></tr>').join('');

  var dec = sig.declaratif||{};
  lignes += Object.keys(dec).map(function(k){
    var d = dec[k];
    return '<tr><td>déclaré</td><td>'+esc(LIBELLES[k]||k)+'</td><td>'+(ETATS[d.etat]||'?')+'</td>'
         +'<td class="muted">dit «'+esc(d.declare)+'»'
         +(d.reel===null?' / <em>rien d\'enregistré côté serveur</em>':' / réel «'+esc(d.reel)+'»')+'</td></tr>';
  }).join('');

  return (sig.avertissement?'<div class="muted" style="font-size:12px;margin:4px 0">⚠️ '+esc(sig.avertissement)+'</div>':'')
       + '<table style="width:100%;font-size:12px;border-collapse:collapse">'+lignes+'</table>';
}

function render(disputes){
  if(!disputes.length){$('list').innerHTML='<p class="muted">Aucun litige en cours.</p>';return;}
  $('list').innerHTML=disputes.map(function(d){
    var flags=[];
    if(d.init_collisions>0) flags.push('⚠️ '+d.init_collisions+' demandeur(s) concurrent(s)');
    var gele = d.gele_jusqu_a>0;
    if(gele) flags.push('🧊 ouverture gelée jusqu\'au '+new Date(d.gele_jusqu_a*1000).toLocaleDateString()
      +(d.gele_par?' (par '+esc(d.gele_par)+')':''));
    if(d.decided_by) flags.push('tranché par '+esc(d.decided_by));
    if(d.abandonne_par) flags.push('abandonné par '+esc(d.abandonne_par)
      +(d.abandonne_le>0?' le '+new Date(d.abandonne_le*1000).toLocaleDateString():''));
    var vivant = (d.status==='awaiting_admin'||d.status==='open') ? '1' : '0';
    return '<div class="card" data-num="'+esc(d.dispute_number)+'" data-vivant="'+vivant+'" style="margin-bottom:14px">'
      +'<div class="row" style="justify-content:space-between"><div><strong>'+esc(d.username)+'</strong> <span class="muted">— '+esc(d.dispute_number)+' · '+esc(d.status)+'</span></div></div>'
      +(flags.length?'<div style="color:#d4a056;font-size:12px">'+flags.join(' · ')+'</div>':'')
      // 🔑 `faisceau`, pas `signals` : les deux adaptateurs renomment la
      // colonne et suppriment `signals_json` en la décodant. La console lisait
      // l'ancien nom, `facts()` rend une chaîne vide sur une clé absente, et
      // l'arbitre tranchait sous un avertissement posé sur du vide.
      +facts(d.faisceau)
      +'<div class="l3-msgs" style="max-height:180px;overflow:auto;border:1px solid #2a2a2a;border-radius:6px;padding:8px;margin:8px 0;font-size:13px"></div>'
      +'<div class="row" style="gap:6px"><input class="l3-in" placeholder="répondre au demandeur…" style="flex:1"><button class="btn l3-send">Envoyer</button></div>'
      +(d.status==='awaiting_admin'||d.status==='open'?'<div class="row" style="gap:8px;margin-top:8px"><button class="btn l3-grant">Accorder</button><button class="btn l3-refuse" style="border-color:#5a2a2a;color:#d96459">Refuser</button><button class="btn l3-abandon" data-user="'+esc(d.username)+'">Abandonner</button></div>':'')
      // Lever un gel se fait depuis n'importe quelle carte — c'est rendre une
      // porte. Le POSER demande un dossier vivant et le faisceau sous les yeux :
      // sinon l'arbitre ferme la dernière porte de quelqu'un sans avoir vu un
      // seul fait, et c'est le cas le moins cher à provoquer pour un tiers.
      +'<div class="row" style="gap:8px;margin-top:8px">'
      +(gele?'<button class="btn l3-degel" data-user="'+esc(d.username)+'">🧊 Lever le gel</button>'
            :(vivant==='1'&&d.faisceau
                ?'<button class="btn l3-gel" data-user="'+esc(d.username)+'">🧊 Geler l\'ouverture</button>'
                :''))
      +'</div>'
      +'</div>';
  }).join('');
  // ⚠️ Un fil par carte, et la liste n'est pas bornée : les dossiers tranchés y
  // restent (la purge les épargne). Lancer tous les fils d'un coup, toutes les
  // huit secondes, dépasse le lissage du vhost dès une dizaine de dossiers — et
  // ce que l'arbitre voit alors, c'est un fil vide. Donc deux bornes : on ne
  // suit QUE les dossiers vivants, et on les demande à la file, pas en rafale.
  var aSuivre=[];
  document.querySelectorAll('.card[data-num]').forEach(function(card){
    var num=card.getAttribute('data-num');
    function poll(){return post('/api/dispute_chat.php',{dispute_number:num}).then(function(d){
      if(!d.ok)return; card.querySelector('.l3-msgs').innerHTML=(d.messages||[]).map(m=>'<div style="margin:4px 0"><strong>'+(m.auteur==='admin'?'toi (admin)':'demandeur')+' :</strong> '+esc(m.texte)+'</div>').join('')||'<span class="muted">aucun message</span>';
    });}
    if(card.getAttribute('data-vivant')==='1') aSuivre.push(poll);
    // Le fil se refuse sur un dossier tranché contre le demandeur. Sans ce
    // contrôle le champ se vide, rien n'est rangé, et l'arbitre croit avoir
    // répondu.
    card.querySelector('.l3-send').addEventListener('click',function(){var i=card.querySelector('.l3-in');if(!i.value.trim())return;post('/api/dispute_chat.php',{dispute_number:num,message:i.value.trim()}).then(function(d){if(d&&!d.ok){alert(d.message||'Message non enregistré.');return;}i.value='';poll();});});
    var g=card.querySelector('.l3-grant'), r=card.querySelector('.l3-refuse');
    if(g)g.addEventListener('click',()=>{post('/api/admin_dispute_decide.php',{dispute_number:num,decision:'grant'}).then(load);});
    // ⚠️ Un refus ne touche pas au compte : cette confirmation dit ce que le
    // geste fait, et n'annonce aucune suppression. Un avertissement plus large
    // que le geste ferait hésiter l'arbitre devant une décision réversible.
    if(r)r.addEventListener('click',()=>{
      if(confirm('Refuser clôt ce dossier. Les secrets du compte ne sont pas modifiés — mais si ce '
                +'demandeur est le titulaire, il repart sans secret : son seul chemin est de rouvrir '
                +'un dossier, et l\'arbitrage sera à refaire.\n'
                +'Ce refus est compté sur le compte visé. Au '+GEL.seuil+'ᵉ refus en '+GEL.fenetre+' la '
                +'console te le signale ; aucun gel ne se pose de lui-même, c\'est à toi de le décider.\n\n'
                +'Si ce dossier a été ouvert par quelqu\'un d\'autre que le titulaire, préfère '
                +'« Abandonner » : l\'abandon, lui, ne compte pas. Confirmer ?'))
        post('/api/admin_dispute_decide.php',{dispute_number:num,decision:'refuse'}).then(function(d){
          // ⚠️ On INFORME, on ne propose pas de geler dans la foulée. Un gel
          // offert au clic juste après le refus rejouerait le gel automatique
          // qui a été retiré : c'est le même enchaînement, avec une confirmation
          // de plus. Le signal se lit ici, le geste se pose au bouton.
          if(d && d.gel_suggere) alert(d.refus_dans_la_fenetre+' refus sur ce compte en '+GEL.fenetre+'.\n\n'
                                      +'Un gel de l\'ouverture est suggéré — il n\'est pas posé. '
                                      +'Avant de le poser, vérifie qui ouvre ces dossiers : si c\'est un tiers, '
                                      +'geler fermerait la dernière voie de secours du titulaire, pas celle du tiers.');
          load();
        });
    });
    // 🔑 Le refus compte sur le compte VISÉ, pas sur le demandeur : c'est ce
    // que le `confirm()` ci-dessous ne peut pas expliquer sans alourdir, et
    // c'est toute la raison d'être de ce bouton. Le reste du piège est dit à
    // l'arbitre au moment du geste, et dans `RecoverL3::adminAbandon`.
    var ab=card.querySelector('.l3-abandon');
    if(ab)ab.addEventListener('click',function(){
      var u=ab.getAttribute('data-user');
      if(confirm('Abandonner la procédure de « '+u+' » ?\n\n'
                +'Le dossier est clos sans décision et sans compter de refus. Le titulaire peut en '
                +'ouvrir un nouveau tout de suite, et l\'arbitrage sera à refaire.\n'
                +'À préférer au refus quand le demandeur n\'est pas le titulaire.'))
        post('/api/admin_dispute_abandon.php',{username:u}).then(function(d){
          if(d && !d.ok) alert(d.message||'La procédure n\'a pas pu être close.');
          load();
        });
    });
    // Le gel porte sur le COMPTE, pas sur ce dossier : c'est l'ouverture de
    // nouveaux dossiers qui est suspendue, et le lever rouvre cette porte à
    // quelqu'un qui n'a plus aucun secret. Qui lève est pris dans la session,
    // côté endpoint, et rangé dans `degele_par`.
    var dg=card.querySelector('.l3-degel');
    if(dg)dg.addEventListener('click',function(){
      var u=dg.getAttribute('data-user');
      // ⚠️ Aucun compteur n'arme un gel : une levée tient jusqu'à ce qu'un arbitre
      // en repose un. Cette confirmation doit le dire, sinon elle fait hésiter
      // devant un geste qui ne se défait pas tout seul.
      if(confirm('Lever le gel sur « '+u+' » ?\n\n'
                +'Le gel ne bloquait pas le compte : c\'est l\'ouverture de nouveaux dossiers qui '
                +'reprend. Ce gel a été posé par un arbitre — le lever ne remet aucun compteur à zéro, '
                +'et rien ne le repose sans qu\'un arbitre le demande.'))
        post('/api/admin_unfreeze.php',{username:u}).then(function(d){
          if(d && !d.ok) alert(d.message||'Le gel n\'a pas pu être levé.');
          load();
        });
    });
    // 🔑 Poser un gel retire au titulaire son DERNIER recours — celui de qui n'a
    // plus ni mot de passe, ni passphrase, ni feuille de codes. Il ne touche pas
    // au compte, et c'est ce qui le rend trompeur : la connexion ordinaire
    // continue de marcher pendant que la porte de secours est fermée. Qui gèle
    // est pris dans la session, côté endpoint, et rangé dans `gele_par`.
    var gl=card.querySelector('.l3-gel');
    if(gl)gl.addEventListener('click',function(){
      var u=gl.getAttribute('data-user');
      if(confirm('Geler l\'ouverture de nouveaux dossiers pour « '+u+' », pendant '+GEL.duree+' ?\n\n'
                +'Le compte reste connectable. Ce qui se ferme, c\'est la voie de secours de qui a '
                +'tout perdu.\n'
                +'⚠️ Si les dossiers sont ouverts par un TIERS, geler ne le gêne pas : ça ferme la '
                +'porte du titulaire, qui n\'a rien demandé. Dans ce cas, « Abandonner » suffit.\n\n'
                +'Ton nom sera inscrit comme auteur du gel. Confirmer ?'))
        post('/api/admin_freeze.php',{username:u}).then(function(d){
          if(d && !d.ok) alert(d.message||'Le gel n\'a pas pu être posé.');
          load();
        });
    });
  });
  suivreLesFils(aSuivre);
}
// Les fils partent l'un après l'autre : à deux requêtes par seconde côté vhost,
// une rafale de dix serait refusée, une file de dix passe.
function suivreLesFils(file){
  return file.reduce(function(chaine,p){ return chaine.then(p); }, Promise.resolve());
}
function load(){get('/api/admin_disputes.php').then(function(d){
  if(!d.ok&&d.message){ $('list').innerHTML='<p class="muted">'+esc(d.message)+'</p>'; return; }
  render(d.disputes||[]);
});}
load(); setInterval(load, 8000);
</script>
<?php render_footer(); ?>
