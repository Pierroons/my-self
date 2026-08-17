/**
 * Chiffrement des rapports red team vers la clé publique du programme.
 *
 * 🔑 **Le serveur ne peut pas lire ce qu'il reçoit.** Le rapport est chiffré
 * dans le navigateur du chercheur vers une clé PGP dont la privée n'est pas sur
 * cette machine. Un serveur compromis — précisément ce qu'on invite à tenter —
 * ne donne accès à aucun rapport, pas même à celui d'un autre participant.
 *
 * C'était le seul angle par lequel quelqu'un ayant pris le lab aurait pu lire
 * les découvertes des autres, ou savoir si sa faille avait déjà été signalée.
 *
 * L'empreinte est figée ici et comparée à la clé téléchargée : si le serveur
 * servait un jour une autre clé — la sienne — le chiffrement s'arrêterait au
 * lieu de livrer le rapport à qui l'a substituée. Une clé servie par la machine
 * qu'on attaque ne se vérifie pas toute seule.
 */

const EMPREINTE_ATTENDUE = '3fa110dd0de251b001941b3d999aff39c261cd92';
const CHEMIN_CLE = '/.well-known/vdp-pubkey.asc';

let _cle = null;

/** Charge la clé publique une fois, en vérifiant son empreinte. */
async function chargerCle(openpgp) {
  if (_cle) return _cle;

  const reponse = await fetch(CHEMIN_CLE, { cache: 'no-store' });
  if (!reponse.ok) {
    throw new Error('Clé de chiffrement introuvable (' + reponse.status + ').');
  }
  const armure = await reponse.text();
  const cle = await openpgp.readKey({ armoredKey: armure });

  const empreinte = cle.getFingerprint().toLowerCase();
  if (empreinte !== EMPREINTE_ATTENDUE) {
    // Ne pas chiffrer plutôt que chiffrer vers un destinataire inconnu.
    throw new Error(
      'Empreinte de clé inattendue. Le rapport n\'a PAS été envoyé — '
      + 'vérifie la clé publiée avant de réessayer.'
    );
  }
  return (_cle = cle);
}

/**
 * Chiffre un rapport. Rend un bloc PGP en armure ASCII, déchiffrable avec
 * `gpg -d` par le détenteur de la clé privée, hors de ce serveur.
 */
export async function chiffrerRapport(rapport) {
  const openpgp = await import('/js/openpgp.min.mjs');
  const cle = await chargerCle(openpgp);

  const message = await openpgp.createMessage({
    text: JSON.stringify(rapport, null, 2),
  });

  return openpgp.encrypt({
    message,
    encryptionKeys: cle,
    format: 'armored',
  });
}

/** Empreinte affichée à l'utilisateur, pour qu'il puisse la recouper. */
export function empreinteAffichee() {
  return EMPREINTE_ATTENDUE.toUpperCase().replace(/(.{4})/g, '$1 ').trim();
}
