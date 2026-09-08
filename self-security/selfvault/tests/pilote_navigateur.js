// Pilote de l'atelier dans un VRAI navigateur, ouvert en `file://`.
//
// Le banc concatène ce fichier à la fin de `sortie/selfvault-atelier.html` et
// ouvre le tout dans un navigateur sans interface. Les autres pilotes du module
// (`pilote_app.mjs`, `pilote_atelier.mjs`) évaluent le script dans Node avec un
// DOM factice : ils mesurent la logique, jamais le navigateur. Or la page
// affirme fonctionner hors ligne, et `crypto.subtle` n'existe que dans un
// contexte réputé sûr — ce qu'un fichier local n'est pas partout.
//
// Ce que le navigateur ne peut pas rendre autrement : `dump()` écrit sur la
// sortie standard du processus quand `browser.dom.window.dump.enabled` est vrai
// dans le profil. C'est le seul canal qui n'exige ni copie d'écran, ni serveur,
// ni pilote automatisé installé à côté.
(async () => {
  const dire = (m) => { try { dump("SV " + m + "\n"); } catch (e) { console.log("SV " + m); } };
  const fin  = (etat) => { dire("FIN=" + etat); try { window.close(); } catch (e) {} };

  try {
    dire("protocole=" + location.protocol);
    dire("securise=" + (window.isSecureContext === true));
    dire("subtle=" + (typeof (window.crypto && window.crypto.subtle)));

    // La page se désactive elle-même quand WebCrypto manque. On le lit sur le
    // bouton plutôt que de le déduire : c'est ce que verrait la titulaire.
    const bouton = document.querySelector("#faire");
    if (!bouton) return fin("sans-bouton");
    dire("bouton_actif=" + (bouton.disabled === false));

    document.querySelector("#tit").value = "Sophie MARTIN";
    document.querySelector("#nai").value = "12 mars 1961 à Sainte-Foy (33220)";
    document.querySelector("#ref").value = "SV-2026-0001";
    document.querySelector("#txt").value =
      "DIRECTIVES ET ACCÈS — Sophie MARTIN\n" +
      "Messagerie principale : fermeture après extraction des pièces administratives.\n" +
      "Personne chargée : Marie DUPONT.\n";

    // Les deux lecteurs rendent l'empreinte du clair, pas le clair. On dépose
    // donc celle du texte saisi : si elle se retrouve à la sortie d'un
    // déchiffrement fait ailleurs, c'est bien ce texte-là qui a voyagé.
    const saisi = document.querySelector("#txt").value;
    const emp = await crypto.subtle.digest("SHA-256", new TextEncoder().encode(saisi));
    dire("clair=" + [...new Uint8Array(emp)].map(o => o.toString(16).padStart(2, "0")).join(""));

    await bouton.onclick();

    const etat = (document.querySelector("#etatf") || {}).textContent || "";
    if (etat.trim()) dire("etat=" + etat.trim());

    const coffre = document.querySelector("#brut").value;
    const meta   = document.querySelector("#meta").value;
    if (!coffre) return fin("sans-coffre");

    dire("L1=" + document.querySelector("#sL1").textContent);
    dire("L2=" + document.querySelector("#sL2").textContent);
    dire("empreinte=" + document.querySelector("#semp").textContent);
    dire("meta=" + meta.replace(/\n/g, " "));

    // Le coffre part encodé, puis en morceaux. Encodé parce que le canal est
    // fait de lignes et que le JSON du coffre en contient : ses retours à la
    // ligne couperaient le message en fragments qu'on recollerait dans le
    // désordre. En morceaux parce qu'une ligne de plusieurs kilo-octets ne
    // traverse pas toujours la sortie standard sans être tronquée.
    const b64 = btoa(String.fromCharCode(...new TextEncoder().encode(coffre)));
    const TAILLE = 200;
    dire("morceaux=" + Math.ceil(b64.length / TAILLE));
    for (let i = 0; i * TAILLE < b64.length; i++) {
      dire("c" + i + "=" + b64.slice(i * TAILLE, (i + 1) * TAILLE));
    }
    fin("ok");
  } catch (err) {
    dire("erreur=" + ((err && err.name) || "?") + ": " + ((err && err.message) || err));
    fin("ko");
  }
})();
