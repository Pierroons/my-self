<?php

declare(strict_types=1);

namespace Pierroons\SelfRecover\Tests;

use Pierroons\SelfRecover\Device\Appareil;
use Pierroons\SelfRecover\Recovery\Litige;
use Pierroons\SelfRecover\Storage\StorageInterface;

/**
 * Adaptateur en mémoire — sert les sondes, jamais la production.
 *
 * Il tient lieu de second consommateur : si le contrat ne se satisfaisait que
 * d'une base SQLite, il resterait taillé pour un seul appelant.
 */
// Non `final` : les sondes en dérivent pour simuler une panne au milieu d'une
// écriture, ce qui est le seul moyen de vérifier qu'une transaction annule.
class StockageMemoire implements StorageInterface
{
    /** @var array<string, array{id: int, empreinte_mot: string}> */
    public array $comptes = [];
    /** @var array<string, Appareil> */
    public array $appareils = [];
    /** @var array<string, array{credentialId: string, quand: int}> */
    public array $defis = [];
    /** @var list<array{etiquette: string, succes: bool, ip: ?string, quand: int}> */
    public array $tentatives = [];
    /** @var array<int, string> */
    public array $empreintes = [];
    /** @var list<int> */
    public array $sessionsRevoquees = [];

    public function compterEchecsIp(string $ip, int $depuis): int
    {
        return count(array_filter(
            $this->tentatives,
            static fn (array $t): bool => $t['ip'] === $ip && !$t['succes'] && $t['quand'] > $depuis,
        ));
    }

    public function tracerTentative(string $etiquette, bool $succes, ?string $ip, int $quand): void
    {
        $this->tentatives[] = compact('etiquette', 'succes', 'ip', 'quand');
    }

    public function trouverCompte(string $nomCompte): ?array
    {
        return $this->comptes[$nomCompte] ?? null;
    }

    public function enregistrerAppareil(int $compteId, string $credentialId, string $clePubliqueB64url, int $quand): void
    {
        $nom = '';
        foreach ($this->comptes as $cle => $c) {
            if ($c['id'] === $compteId) { $nom = $cle; break; }
        }
        $this->appareils[$credentialId] = new Appareil($credentialId, $clePubliqueB64url, $compteId, $nom);
    }

    public function trouverAppareil(string $credentialId): ?Appareil
    {
        return $this->appareils[$credentialId] ?? null;
    }

    public function purgerDefisExpires(int $avant): void
    {
        $this->defis = array_filter($this->defis, static fn (array $d): bool => $d['quand'] > $avant);
    }

    public function enregistrerDefi(string $defi, string $credentialId, int $quand): void
    {
        $this->defis[$defi] = ['credentialId' => $credentialId, 'quand' => $quand];
    }

    public function defiEnCours(string $defi, string $credentialId, int $depuis): bool
    {
        $d = $this->defis[$defi] ?? null;

        return $d !== null && $d['credentialId'] === $credentialId && $d['quand'] > $depuis;
    }

    public function consommerDefi(string $defi): void
    {
        unset($this->defis[$defi]);
    }

    public function remplacerEmpreinteMotDePasse(int $compteId, string $empreinte): void
    {
        $this->empreintes[$compteId] = $empreinte;
    }

    public function revoquerSessions(int $compteId): void
    {
        $this->sessionsRevoquees[] = $compteId;
    }

    // ── Récupération ───────────────────────────────────────────────────────

    /** @var array<string, array{id: int, empreinte_passphrase: string}> */
    public array $passphrases = [];
    /** @var list<array{id: int, compte_id: int, index: string, empreinte: string, utilise: bool}> */
    public array $codes = [];
    private int $prochainCodeId = 1;

    public function compterEchecsCompte(string $nomCompte, int $depuis): int
    {
        return count(array_filter(
            $this->tentatives,
            static fn (array $t): bool => $t['etiquette'] === $nomCompte && !$t['succes'] && $t['quand'] > $depuis,
        ));
    }

    public function trouverComptePourPassphrase(string $nomCompte): ?array
    {
        return $this->passphrases[$nomCompte] ?? null;
    }

    public function remplacerEmpreintes(int $compteId, string $empreinteMotDePasse, string $empreintePassphrase): void
    {
        $this->empreintes[$compteId] = $empreinteMotDePasse;
        foreach ($this->passphrases as $nom => $p) {
            if ($p['id'] === $compteId) {
                $this->passphrases[$nom]['empreinte_passphrase'] = $empreintePassphrase;
            }
        }
    }

    public function purgerCodes(int $compteId): void
    {
        $this->codes = array_values(array_filter(
            $this->codes,
            static fn (array $c): bool => $c['compte_id'] !== $compteId,
        ));
    }

    public function enregistrerCode(int $compteId, string $indexRecherche, string $empreinteCode, int $quand): void
    {
        $this->codes[] = [
            'id'         => $this->prochainCodeId++,
            'compte_id'  => $compteId,
            'index'      => $indexRecherche,
            'empreinte'  => $empreinteCode,
            'utilise'    => false,
        ];
    }

    public function trouverCodeParIndex(string $indexRecherche): ?array
    {
        foreach ($this->codes as $c) {
            if (!hash_equals($c['index'], $indexRecherche)) {
                continue;
            }
            $nom = '';
            $mot = '';
            foreach ($this->comptes as $cle => $compte) {
                if ($compte['id'] === $c['compte_id']) { $nom = $cle; $mot = $compte['empreinte_mot']; break; }
            }

            return [
                'code_id'        => $c['id'],
                'empreinte_code' => $c['empreinte'],
                'deja_utilise'   => $c['utilise'],
                'compte_id'      => $c['compte_id'],
                'nom_compte'     => $nom,
                'empreinte_mot'  => $mot,
            ];
        }

        return null;
    }

    public function consommerCode(int $codeId, int $quand): void
    {
        foreach ($this->codes as $i => $c) {
            if ($c['id'] === $codeId) { $this->codes[$i]['utilise'] = true; }
        }
    }

    public function compterCodesRestants(int $compteId): int
    {
        return count(array_filter(
            $this->codes,
            static fn (array $c): bool => $c['compte_id'] === $compteId && !$c['utilise'],
        ));
    }

    // ── Récupération de niveau 3 : dossier et arbitrage humain ─────────────

    /** @var list<array<string, mixed>> */
    public array $litiges = [];
    /** @var list<array{litige_id: int, auteur: string, texte: string, ecrit_le: int}> */
    public array $messages = [];
    /** @var array<int, array{jusqua: int, degele_par: ?string, degele_le: ?int}> */
    public array $gels = [];
    /**
     * Faits de connexion par compte.
     *
     * ⚠️ `derniere_connexion` et `nombre_connexions` valent `null` par défaut,
     * comme un déploiement qui ne les enregistre pas. Une sonde qui les
     * remplirait d'office ne verrait jamais l'état `indisponible` du faisceau.
     *
     * @var array<int, array{cree_le: int, derniere_connexion: ?int, nombre_connexions: ?int}>
     */
    public array $faits = [];

    private function litigeDepuis(array $l): Litige
    {
        return new Litige(
            id: $l['id'],
            numero: $l['numero'],
            compteId: $l['compte_id'],
            nomCompte: $l['nom_compte'],
            statut: $l['statut'],
            empreinteSesame: $l['empreinte_sesame'],
            creeLe: $l['cree_le'],
            expireLe: $l['expire_le'],
            deposeLe: $l['depose_le'],
            trancheLe: $l['tranche_le'],
            tranchePar: $l['tranche_par'],
            demandeursConcurrents: $l['demandeurs_concurrents'],
        );
    }

    public function trouverLitigeParNumero(string $numero): ?Litige
    {
        foreach ($this->litiges as $l) {
            if ($l['numero'] === $numero) {
                return $this->litigeDepuis($l);
            }
        }

        return null;
    }

    public function litigeActifDuCompte(int $compteId, int $maintenant): ?Litige
    {
        foreach (array_reverse($this->litiges) as $l) {
            if ($l['compte_id'] === $compteId
                && in_array($l['statut'], [Litige::OUVERT, Litige::A_LIRE], true)
                && $l['expire_le'] > $maintenant) {
                return $this->litigeDepuis($l);
            }
        }

        return null;
    }

    public function ouvrirLitige(
        int $compteId,
        string $numero,
        string $empreinteSesame,
        int $quand,
        int $expireLe,
    ): void {
        $nom = '';
        foreach ($this->comptes as $cle => $c) {
            if ($c['id'] === $compteId) {
                $nom = $cle;
                break;
            }
        }
        $this->litiges[] = [
            'id' => count($this->litiges) + 1, 'numero' => $numero, 'compte_id' => $compteId,
            'nom_compte' => $nom, 'statut' => Litige::OUVERT, 'empreinte_sesame' => $empreinteSesame,
            'cree_le' => $quand, 'expire_le' => $expireLe, 'depose_le' => 0,
            'tranche_le' => null, 'tranche_par' => null, 'demandeurs_concurrents' => 0,
            'faisceau' => null,
        ];
    }

    /** @param callable(array<string, mixed>): array<string, mixed> $muter */
    private function majLitige(int $litigeId, callable $muter): void
    {
        foreach ($this->litiges as $i => $l) {
            if ($l['id'] === $litigeId) {
                $this->litiges[$i] = $muter($l);

                return;
            }
        }
    }

    public function compterDemandeurConcurrent(int $litigeId): void
    {
        $this->majLitige($litigeId, static function (array $l): array {
            $l['demandeurs_concurrents']++;

            return $l;
        });
    }

    public function enregistrerFaisceau(int $litigeId, string $faisceauJson, int $quand): void
    {
        $this->majLitige($litigeId, static function (array $l) use ($faisceauJson, $quand): array {
            $l['faisceau']  = $faisceauJson;
            $l['statut']    = Litige::A_LIRE;
            $l['depose_le'] = $quand;

            return $l;
        });
    }

    public function trancherLitige(int $litigeId, string $statut, string $par, int $quand): void
    {
        $this->majLitige($litigeId, static function (array $l) use ($statut, $par, $quand): array {
            $l['statut']      = $statut;
            $l['tranche_par'] = $par;
            $l['tranche_le']  = $quand;

            return $l;
        });
    }

    public function cloreLitige(int $litigeId, int $quand): void
    {
        $this->majLitige($litigeId, static function (array $l) use ($quand): array {
            $l['statut'] = Litige::CLOS;
            // Le sésame ne rouvre plus rien : l'empreinte cesse de correspondre
            // à quoi que ce soit, plutôt que d'être laissée en place.
            $l['empreinte_sesame'] = str_repeat('f', 64);
            $l['tranche_le']       = $l['tranche_le'] ?? $quand;

            return $l;
        });
    }

    public function compterRefusRecents(int $compteId, int $depuis): int
    {
        return count(array_filter(
            $this->litiges,
            static fn (array $l): bool => $l['compte_id'] === $compteId
                && $l['statut'] === Litige::REFUSE
                && (int) ($l['tranche_le'] ?? 0) > $depuis,
        ));
    }

    public function poserGel(int $compteId, int $jusqua, int $quand): void
    {
        $this->gels[$compteId] = ['jusqua' => $jusqua, 'degele_par' => null, 'degele_le' => null];
    }

    public function gelJusqua(int $compteId, int $maintenant): int
    {
        $g = $this->gels[$compteId] ?? null;

        return ($g !== null && $g['jusqua'] > $maintenant) ? $g['jusqua'] : 0;
    }

    public function leverGel(int $compteId, string $par, int $quand): void
    {
        // La ligne est gardée, pas supprimée : qui a dégelé et quand vaut d'être
        // conservé, y compris pour l'arbitre suivant.
        $this->gels[$compteId] = ['jusqua' => 0, 'degele_par' => $par, 'degele_le' => $quand];
    }

    public function ajouterMessageLitige(int $litigeId, string $auteur, string $texte, int $quand): void
    {
        $this->messages[] = ['litige_id' => $litigeId, 'auteur' => $auteur,
                             'texte' => $texte, 'ecrit_le' => $quand];
    }

    public function messagesDuLitige(int $litigeId): array
    {
        return array_values(array_map(
            static fn (array $m): array => ['auteur' => $m['auteur'], 'texte' => $m['texte'],
                                            'ecrit_le' => $m['ecrit_le']],
            array_filter($this->messages, static fn (array $m): bool => $m['litige_id'] === $litigeId),
        ));
    }

    public function listerLitiges(int $limite): array
    {
        $tous = array_reverse($this->litiges);

        return array_slice($tous, 0, $limite);
    }

    public function purgerLitigesExpires(int $avant): int
    {
        $garde = array_filter($this->litiges, static fn (array $l): bool => $l['expire_le'] > $avant);
        $n     = count($this->litiges) - count($garde);
        $this->litiges = array_values($garde);

        return $n;
    }

    public function faitsDuCompte(int $compteId): ?array
    {
        $nom = null;
        foreach ($this->comptes as $cle => $c) {
            if ($c['id'] === $compteId) {
                $nom = $cle;
                break;
            }
        }
        if ($nom === null) {
            return null;
        }
        $f = $this->faits[$compteId] ?? ['cree_le' => 0, 'derniere_connexion' => null, 'nombre_connexions' => null];

        return ['id' => $compteId, 'nom_compte' => $nom, 'cree_le' => $f['cree_le'],
                'derniere_connexion' => $f['derniere_connexion'], 'nombre_connexions' => $f['nombre_connexions']];
    }

    public function reposerSecrets(
        int $compteId,
        string $empreinteMotDePasse,
        string $empreintePassphrase,
        string $empreinteMotDerive,
        string $sel,
    ): void {
        $this->empreintes[$compteId] = $empreinteMotDePasse;
        foreach ($this->passphrases as $nom => $p) {
            if ($p['id'] === $compteId) {
                $this->passphrases[$nom]['empreinte_passphrase'] = $empreintePassphrase;
            }
        }
        foreach ($this->comptes as $nom => $c) {
            if ($c['id'] === $compteId) {
                $this->comptes[$nom]['empreinte_mot'] = $empreinteMotDerive;
            }
        }
        $this->sels[$compteId] = $sel;
    }

    /** @var array<int, string> */
    public array $sels = [];

    // ── Atomicité ──────────────────────────────────────────────────────────
    //
    // Adaptateur en mémoire : la transaction copie l'état et le restaure en cas
    // d'annulation. Grossier, mais suffisant pour que la sonde puisse vérifier
    // qu'un échec en cours de route ne laisse rien à moitié écrit.

    /** @var array<string, mixed>|null */
    private ?array $avant = null;

    public function commencerTransaction(): void
    {
        $this->avant = [
            'comptes' => $this->comptes, 'appareils' => $this->appareils,
            'defis' => $this->defis, 'tentatives' => $this->tentatives,
            'empreintes' => $this->empreintes, 'sessionsRevoquees' => $this->sessionsRevoquees,
            'passphrases' => $this->passphrases, 'codes' => $this->codes,
            'litiges' => $this->litiges, 'messages' => $this->messages,
            'gels' => $this->gels, 'faits' => $this->faits, 'sels' => $this->sels,
        ];
    }

    public function validerTransaction(): void
    {
        $this->avant = null;
    }

    public function annulerTransaction(): void
    {
        if ($this->avant === null) {
            return;
        }
        foreach ($this->avant as $champ => $valeur) {
            $this->$champ = $valeur;
        }
        $this->avant = null;
    }
}
