<?php

declare(strict_types=1);

namespace Pierroons\SelfRecover\Tests;

use Pierroons\SelfRecover\Device\Appareil;
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
