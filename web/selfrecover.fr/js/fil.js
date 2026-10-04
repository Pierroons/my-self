/* selfrecover.fr — le fil du forum (maquette) : répondre et signaler. Ce que ferait le serveur est simulé ;
 * le texte du visiteur entre dans la page en texte, jamais en HTML, et n'est envoyé nulle part. */
(function () {
  'use strict';
  var C = window.Coulisses || { emit: function () {} };
  var form = document.getElementById('f-reponse');
  if (!form) return;
  var liste = document.getElementById('messages'), zone = document.getElementById('r-texte');
  var retour = document.getElementById('r-retour');
  var pause = function (ms) { return new Promise(function (r) { window.setTimeout(r, ms); }); };

  function signaler(bouton) {
    bouton.disabled = true;
    bouton.textContent = 'signalé';
    C.emit('wire', 'Ton signalement part au serveur, avec le numéro du message.', [['requête', 'POST /forum/signalement']], { maquette: true });
    C.emit('srv', 'Un modérateur humain le lira. Ce qui est manifestement illicite est retiré vite : c\'est ce que la loi demande à un forum.', null, { maquette: true });
  }

  liste.addEventListener('click', function (e) {
    var b = e.target.closest ? e.target.closest('.signaler') : null;
    if (b && !b.disabled) signaler(b);
  });

  form.addEventListener('submit', async function (e) {
    e.preventDefault();
    var texte = zone.value.trim();
    if (texte.length < 2) { retour.textContent = 'Écris au moins deux caractères.'; return; }
    retour.textContent = 'Publication…';
    C.emit('nav', 'Un message public n\'est pas chiffré : tout le monde pourra le lire, c\'est son but.');
    C.emit('wire', 'Envoi au serveur sous TLS : ton message et ta session.', [['requête', 'POST /forum/fil/reponse']], { maquette: true });
    await pause(350);
    C.emit('srv', 'Le serveur range le message tel quel. Seuls les messages privés passent par SelfDataGuard.', null, { maquette: true });
    C.emit('db', 'Il garde un an l\'identifiant de la connexion qui a créé ce message : la loi l\'impose à tout hébergeur de contenus.', [
      ['conservé un an', 'identifiant de connexion, protocoles, date et heure'],
      ['texte de référence', 'décret n° 2021-1362, art. 5 et 6']], { maquette: true });
    var li = document.createElement('li');
    li.className = 'post hud';
    var tete = document.createElement('p');
    tete.className = 'auteur';
    tete.textContent = 'toi · à l\'instant';
    var corps = document.createElement('p');
    corps.textContent = texte;
    li.appendChild(tete); li.appendChild(corps);
    liste.appendChild(li);
    zone.value = '';
    retour.textContent = 'Publié (maquette) : rien n\'a quitté ta page.';
  });
})();
