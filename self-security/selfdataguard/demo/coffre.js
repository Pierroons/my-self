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

function renderEscrow(escrow, hasEscrow) {
  const badge = $('#escrow-badge');
  if (hasEscrow) {
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

function renderCoffre(data) {
  $('#who').textContent = data.userId;
  renderPrivate(data.private);
  renderEscrow(data.escrow, data.hasEscrow);
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
