/* SelfDataGuard — module profil "coffre" (vanilla JS) */

const $ = (sel) => document.querySelector(sel);

async function call(endpoint, body) {
  const opts = { method: 'POST', headers: { 'Content-Type': 'application/json' } };
  if (body) opts.body = JSON.stringify(body);
  try {
    const res = await fetch('api/' + endpoint, opts);
    const data = await res.json().catch(() => ({}));
    return { ok: res.ok, status: res.status, data };
  } catch (e) {
    return { ok: false, status: 0, data: { error: String(e) } };
  }
}

function show(target, result) {
  const el = $(target);
  el.classList.remove('ok', 'err');
  el.classList.add(result.ok ? 'ok' : 'err');
  el.textContent = JSON.stringify(result.data, null, 2);
}

function formData(form) {
  const fd = new FormData(form);
  const obj = {};
  fd.forEach((v, k) => { obj[k] = String(v); });
  return obj;
}

// Session en mémoire uniquement (jamais localStorage) — le secret vit le temps
// de la page, comme une session connectée. Verrouiller = tout effacer.
let session = null; // { userId, secret, mode }

// -- Adapter le libellé du secret selon la méthode ---------------------------

const openMode = $('#open-mode');
const openLabel = $('#open-secret-label');
const openInput = $('#open-secret');
function syncOpenLabel() {
  if (openMode.value === 'memorized') {
    openLabel.firstChild.textContent = 'Mot de secours ';
    openInput.placeholder = 'ton/tes mot(s) de récupération';
  } else {
    openLabel.firstChild.textContent = 'Mot de passe ';
    openInput.placeholder = 'ton mot de passe';
  }
}
openMode.addEventListener('change', syncOpenLabel);
syncOpenLabel();

// -- Rendu du coffre ---------------------------------------------------------

function renderPrivate(fields) {
  const dl = $('#private-kv');
  dl.innerHTML = '';
  const entries = Object.entries(fields || {});
  if (entries.length === 0) {
    dl.innerHTML = '<dt>—</dt><dd class="muted">aucun champ privé</dd>';
    return;
  }
  for (const [k, v] of entries) {
    const dt = document.createElement('dt');
    dt.textContent = k;
    const dd = document.createElement('dd');
    dd.textContent = v;
    dl.append(dt, dd);
  }
}

function renderEscrow(escrow, hasEscrow, escrowError) {
  const badge = $('#escrow-badge');
  if (escrowError) {
    badge.textContent = 'illisible : ' + escrowError;
    badge.className = 'badge badge-off';
  } else if (hasEscrow) {
    badge.textContent = 'configurée';
    badge.className = 'badge badge-on';
  } else {
    badge.textContent = 'non configurée';
    badge.className = 'badge badge-off';
  }
  // Pré-remplir les champs avec les valeurs existantes (l'user se relit).
  $('#escrow-form').contact_secours.value = (escrow && escrow.contact_secours) || '';
  $('#escrow-form').indice_recup.value = (escrow && escrow.indice_recup) || '';
}

// « Non établi » n'est jamais « aucune remise » : une attestation absente ou fausse
// se dit comme telle. Une remise est la boîte rendue par le gardien : la lecture qui
// suit peut encore échouer, elle compte quand même.
const MOTIFS = {
  'not-keeper-sealed': "pas de gardien : l'administrateur peut lire ce séquestre seul, et aucune remise ne t'est attestée",
  'not-pinned': "scellé au gardien par l'exploitant, pas encore par toi : ton prochain dépôt le lie à ton gardien",
  'keeper-changed': "le service a changé de gardien : ce que l'ancien a remis reste chez lui",
  'keeper-mismatch': "le gardien donné à ce service n'est pas celui que sa base a inscrit",
  'no-keeper': 'aucun gardien joignable par ce service',
  'keeper-unreachable': "un gardien ne répond pas : une lecture qu'il tiendrait annoncée ne t'est pas montrée ; ce que les autres attestent suit",
  'retired': "ce séquestre est retiré du gardien qu'il désigne, qui ne le remettra plus : ton prochain dépôt le scelle au gardien de ce service",
  'retired-here': "ce séquestre est retiré du gardien de ce service lui-même : aucun dépôt ne le rend lisible, seul un changement de gardien par l'exploitant le fait",
  'escrow-replaced': "la ligne de ton séquestre en base n'est pas celle que ta clé désigne — remise d'une sauvegarde, réécrite ou supprimée ; voici ce que le gardien tient pour ton séquestre",
};

function listeDesRemises(reads) {
  const remises = reads.reads.map((r) => `${r.case} le ${new Date(r.at * 1000).toLocaleString()}`);
  const annoncees = reads.pending.map((r) => `${r.case} ${r.objected ? 'refusée par toi' : 'annoncée pour le ' + new Date(r.due * 1000).toLocaleString()}`);
  return [...remises, ...annoncees].join(' · ');
}

function renderReads(reads) {
  const p = $('#escrow-reads');
  if (!reads) { p.textContent = ''; return; }
  if (!reads.established) {
    const vues = listeDesRemises(reads);
    const motif = reads.reason === 'retired' && !reads.reseal ? 'retired-here' : reads.reason;
    const muets = (reads.unanswered || []).map((u) => `${u.keeper.slice(0, 12)}… (${u.reason})`).join(', ');
    p.textContent = '🔎 Remises non établies — ' + (MOTIFS[motif] || `attestation refusée (${reads.reason})`) + '.'
      + (muets ? ' Sans attestation valable : ' + muets + '.' : '')
      + (vues ? ' Les gardiens attestent : ' + vues + '.' : '');
    return;
  }
  if (reads.keeper === null) {
    p.textContent = '🔎 Aucun séquestre déposé : rien à remettre.';
    return;
  }
  p.textContent = '🔎 Remises attestées par le gardien : ' + (listeDesRemises(reads) || 'aucune') + '.';
}

function renderCoffre(data) {
  $('#who').textContent = data.userId;
  renderPrivate(data.private);
  renderEscrow(data.escrow, data.hasEscrow, data.escrowError);
  renderReads(data.escrowReads);
  $('#profile-section').classList.remove('hidden');
}

// -- Ouvrir le coffre --------------------------------------------------------

$('#open-form').addEventListener('submit', async (ev) => {
  ev.preventDefault();
  const f = formData(ev.target);
  const res = await call('coffre_open.php', { userId: f.userId, secret: f.secret, mode: f.mode });
  show('#open-result', res);
  if (res.ok) {
    session = { userId: f.userId, secret: f.secret, mode: f.mode };
    $('#open-result').textContent = '✅ coffre ouvert';
    renderCoffre(res.data);
  } else {
    session = null;
    $('#profile-section').classList.add('hidden');
  }
});

// -- Déposer les champs escrow -----------------------------------------------

$('#escrow-form').addEventListener('submit', async (ev) => {
  ev.preventDefault();
  if (!session) { show('#escrow-result', { ok: false, data: { error: 'coffre non ouvert' } }); return; }
  const f = formData(ev.target);
  const res = await call('escrow_set.php', {
    userId: session.userId,
    secret: session.secret,
    mode: session.mode,
    fields: { contact_secours: f.contact_secours, indice_recup: f.indice_recup },
  });
  show('#escrow-result', res);
  if (res.ok) {
    // Recharger pour refléter l'état (badge + relecture).
    const reopen = await call('coffre_open.php', session);
    if (reopen.ok) renderCoffre(reopen.data);
  }
});

// -- Verrouiller -------------------------------------------------------------

$('#lock-btn').addEventListener('click', () => {
  session = null;
  $('#profile-section').classList.add('hidden');
  $('#open-form').reset();
  $('#open-result').textContent = '🔒 verrouillé';
  $('#open-result').classList.remove('ok', 'err');
  syncOpenLabel();
});
