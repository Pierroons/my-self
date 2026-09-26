/*
 * Éprouve `public/js/e2e-memo.js` — le mémo chiffré du lab — en exécutant
 * réellement le scellement, l'ouverture par chacun des deux secrets, et les refus.
 *
 * Usage : node demo/lab/tests/sanity_memo_client.js
 * Sort 0 si tout passe, 1 sinon. Demande `php` avec sodium et openssl, pour l'oracle.
 *
 * Ce qui est éprouvé est le VRAI fichier, chargé comme dans la page : argon2id.js,
 * puis sr-kdf.js, puis e2e-memo.js.
 *
 * 🔑 L'enveloppe A produite ici est rouverte par PHP — libsodium pour Argon2id,
 * hash_hkdf, openssl pour AES-GCM. Deux implémentations sans une ligne commune :
 * si le JS dérivait autrement que ce qu'il annonce (profil, sel, étiquette), la
 * vault_key rendue par PHP ne serait pas la sienne.
 */
const fs = require('fs');
const os = require('os');
const path = require('path');
const { spawnSync } = require('child_process');
const lab = path.join(__dirname, '..');
const racine = path.join(lab, '..', '..');

let echecs = 0; let total = 0;
const verdict = (quoi, ok, detail = '') => {
    total++;
    console.log(`  ${ok ? 'ok    ' : 'RATE  '} ${quoi}${detail ? ' — ' + detail : ''}`);
    if (!ok) echecs++;
};
const leve = async (fn) => { try { await fn(); return null; } catch (e) { return e.message; } };

globalThis.srArgon2id = require(path.join(racine, 'bi-self/selfrecover/client/argon2id.js')).argon2id;
const srKdf = require(path.join(racine, 'bi-self/selfrecover/client/sr-kdf.js'));
const E2EMemo = require(path.join(lab, 'public/js/e2e-memo.js'));

const MDP = 'mot-de-passe-du-banc';
const PASS = 'quatre mots de secours pour le banc';

(async () => {
    console.log('▸ Scellement');
    const coffre = await E2EMemo.createVault(MDP, PASS, 'premier mémo');
    const kdf = JSON.parse(coffre.kdf);
    const p = srKdf.PROFIL;
    verdict('le coffre porte le profil de sr-kdf.js', kdf.alg === p.alg && kdf.t === p.t && kdf.m === p.m && kdf.p === p.p,
        coffre.kdf);
    verdict("il n'écrit plus de nombre de tours PBKDF2", !('kdf_iter' in coffre));
    const cle = coffre._vaultKeyB64;
    verdict('la clé du coffre est rendue à la page (32 octets)', typeof cle === 'string' && Buffer.from(cle, 'base64').length === 32);
    delete coffre._vaultKeyB64; // la page la retire avant l'envoi : le serveur ne la voit pas

    console.log('▸ Ouverture');
    const parMdp = await E2EMemo.unlock(MDP, coffre, 'pw');
    verdict('le mot de passe ouvre le coffre', parMdp.memo === 'premier mémo' && parMdp.vaultKeyB64 === cle);
    const parPass = await E2EMemo.unlock(PASS, coffre, 'rec');
    verdict('la passphrase de secours ouvre le même coffre', parPass.memo === 'premier mémo' && parPass.vaultKeyB64 === cle);

    const maj = await E2EMemo.reEncryptMemo(cle, 'mémo réécrit');
    const apres = await E2EMemo.unlock(MDP, { ...coffre, ...maj }, 'pw');
    verdict('la clé rendue au scellement réécrit le mémo', apres.memo === 'mémo réécrit');

    console.log('▸ Refus');
    verdict('un mauvais mot de passe rend secret_incorrect',
        (await leve(() => E2EMemo.unlock('pas-le-bon', coffre, 'pw'))) === 'secret_incorrect');
    verdict("le mot de passe n'ouvre pas l'enveloppe de secours (étiquettes séparées)",
        (await leve(() => E2EMemo.unlock(MDP, coffre, 'rec'))) === 'secret_incorrect');
    const ancien = { ...coffre, kdf: null, kdf_iter: 600000 };
    verdict('un coffre sans paramètres Argon2id rend coffre_ancien',
        (await leve(() => E2EMemo.unlock(MDP, ancien, 'pw'))) === 'coffre_ancien');
    const abaisse = { ...coffre, kdf: JSON.stringify({ alg: 'argon2id', t: 1, m: 8, p: 1 }) };
    const refus = await leve(() => E2EMemo.unlock(MDP, abaisse, 'pw'));
    verdict('des paramètres sous le plancher sont refusés avant toute dérivation', /plancher/.test(refus || ''), refus || 'aucun refus');

    console.log('▸ Oracle indépendant (PHP : libsodium, hash_hkdf, openssl)');
    const f = path.join(fs.mkdtempSync(path.join(os.tmpdir(), 'banc-memo-')), 'enveloppe.json');
    fs.writeFileSync(f, JSON.stringify({ mdp: MDP, sel: coffre.kdf_salt, kdf, iv: coffre.wrap_pw_iv, ct: coffre.wrap_pw_ct }));
    const php = spawnSync('php', ['-r', `
        $e = json_decode(file_get_contents($argv[1]), true);
        $maitre = sodium_crypto_pwhash(32, $e['mdp'], base64_decode($e['sel']), $e['kdf']['t'],
            $e['kdf']['m'] * 1024, SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13);
        $cle = hash_hkdf('sha256', $maitre, 32, 'myself-lab/memo/data-enc', '');
        $ct = base64_decode($e['ct']);
        $vk = openssl_decrypt(substr($ct, 0, -16), 'aes-256-gcm', $cle, OPENSSL_RAW_DATA,
            base64_decode($e['iv']), substr($ct, -16));
        echo $vk === false ? 'ECHEC' : base64_encode($vk);`, f], { encoding: 'utf8' });
    fs.rmSync(path.dirname(f), { recursive: true, force: true });
    verdict("PHP rouvre l'enveloppe A et retrouve la même clé de coffre", php.stdout.trim() === cle,
        php.status === 0 ? php.stdout.trim().slice(0, 12) + '…' : (php.stderr || 'php absent').trim().slice(0, 80));

    console.log('');
    if (echecs === 0) {
        console.log(`OK — ${total}/${total} — le mémo scelle en Argon2id, s'ouvre par ses deux secrets, et refuse ce qu'il doit.`);
        process.exit(0);
    }
    console.log(`ÉCHEC — ${echecs} contrôle(s) sur ${total}.`);
    process.exit(1);
})();
