/**
 * SelfRecover — Calcul d'entropie côté client
 *
 * Trois modes supportés :
 *   1. Diceware standard : génération depuis la wordlist EFF 7776 mots
 *   2. Passphrase libre  : entropie estimée par zxcvbn (Dropbox, MIT)
 *   3. Hybride           : N mots EFF + ajout libre par l'utilisateur
 *
 * Le calcul est purement local — aucune passphrase ne quitte le navigateur
 * pour les modes preview. Le serveur génère la passphrase finale à l'inscription
 * (random_int() côté PHP avec la même wordlist EFF).
 */

(function () {
    'use strict';

    const WORDS_EFF = 7776;
    const BITS_PER_DICEWARE_WORD = Math.log2(WORDS_EFF);   // ≈ 12.9248

    /**
     * Tirage uniforme dans [0, max[ via crypto.getRandomValues + rejection sampling.
     * Évite le biais modulo qu'on aurait avec Math.random().
     */
    function uniformInt(max) {
        const range = 0x100000000;       // 2^32
        const limit = range - (range % max);
        const buf = new Uint32Array(1);
        let v;
        do {
            crypto.getRandomValues(buf);
            v = buf[0];
        } while (v >= limit);
        return v % max;
    }

    /**
     * Génère une passphrase diceware aléatoire depuis la wordlist chargée.
     */
    function generateDiceware(count, lang, sep) {
        count = count || 4;
        lang = lang || 'en';
        sep = sep === undefined ? '-' : sep;
        const list = (window.DICEWARE_WORDS && window.DICEWARE_WORDS[lang]) || [];
        if (list.length !== WORDS_EFF) {
            throw new Error('Wordlist EFF non chargée ou tronquée (lang=' + lang + ')');
        }
        const out = [];
        for (let i = 0; i < count; i++) {
            out.push(list[uniformInt(WORDS_EFF)]);
        }
        return out.join(sep);
    }

    /**
     * Entropie d'une passphrase diceware "pure" (mots EFF uniformes).
     * Formule : count × log2(7776).
     */
    function dicewareEntropyBits(count) {
        return Number((count * BITS_PER_DICEWARE_WORD).toFixed(2));
    }

    /**
     * Entropie d'une passphrase libre, via zxcvbn.
     * Retourne { bits, score, crackTime } ou null si zxcvbn absent.
     */
    function freeEntropyBits(passphrase) {
        if (typeof zxcvbn !== 'function') return null;
        const r = zxcvbn(passphrase || '');
        const bits = r.guesses_log10 * Math.log2(10);
        return {
            bits: Number(bits.toFixed(2)),
            score: r.score,                         // 0 (faible) à 4 (excellent)
            crackTime: r.crack_times_display.offline_slow_hashing_1e4_per_second,
        };
    }

    /**
     * Entropie d'une passphrase hybride (N mots diceware + suffixe libre).
     * On somme l'entropie diceware et l'entropie zxcvbn du suffixe.
     */
    function hybridEntropyBits(dicewareCount, freeSuffix) {
        const dice = dicewareEntropyBits(dicewareCount);
        const free = freeEntropyBits(freeSuffix);
        return {
            bits: Number((dice + (free ? free.bits : 0)).toFixed(2)),
            dicewareBits: dice,
            freeBits: free ? free.bits : 0,
        };
    }

    /**
     * Convertit l'entropie en bits en équivalence "temps de cassage".
     * Hypothèse : attaquant à 10^12 essais/seconde (haut de gamme GPU).
     * On réajuste si on veut un modèle plus conservateur.
     */
    function crackTimeFromBits(bits) {
        const guessesPerSec = 1e12;
        const totalGuesses = Math.pow(2, bits);
        const seconds = totalGuesses / 2 / guessesPerSec; // espérance = moitié du space
        if (seconds < 1) return 'instantané';
        if (seconds < 60) return Math.round(seconds) + ' secondes';
        if (seconds < 3600) return Math.round(seconds / 60) + ' minutes';
        if (seconds < 86400) return Math.round(seconds / 3600) + ' heures';
        if (seconds < 31536000) return Math.round(seconds / 86400) + ' jours';
        const years = seconds / 31536000;
        if (years < 1000) return Math.round(years) + ' années';
        if (years < 1e6) return (years / 1000).toFixed(1) + ' millénaires';
        if (years < 1e9) return (years / 1e6).toFixed(1) + ' millions d\'années';
        if (years < 1e12) return (years / 1e9).toFixed(1) + ' milliards d\'années';
        return (years / 1e12).toExponential(2) + ' × 10¹² années';
    }

    /**
     * Classe l'entropie en niveau qualitatif.
     */
    function entropyLevel(bits) {
        if (bits < 40) return { label: 'Très faible', color: '#ef4444' };
        if (bits < 56) return { label: 'Faible', color: '#f97316' };
        if (bits < 72) return { label: 'Correct', color: '#eab308' };
        if (bits < 90) return { label: 'Fort', color: '#10b981' };
        if (bits < 128) return { label: 'Très fort', color: '#3b82f6' };
        return { label: 'Excessif', color: '#a78bfa' };
    }

    // Export global
    window.SelfRecoverEntropy = {
        WORDS_EFF: WORDS_EFF,
        BITS_PER_DICEWARE_WORD: BITS_PER_DICEWARE_WORD,
        generateDiceware: generateDiceware,
        dicewareEntropyBits: dicewareEntropyBits,
        freeEntropyBits: freeEntropyBits,
        hybridEntropyBits: hybridEntropyBits,
        crackTimeFromBits: crackTimeFromBits,
        entropyLevel: entropyLevel,
    };
})();
