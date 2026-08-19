<?php

declare(strict_types=1);

namespace Pierroons\SelfRecover\Tests;

use Pierroons\SelfRecover\Device\Appareil;
use Pierroons\SelfRecover\Device\StorageInterface;

/**
 * Adaptateur en mémoire — sert les sondes, jamais la production.
 *
 * Il tient lieu de second consommateur : si le contrat ne se satisfaisait que
 * d'une base SQLite, il resterait taillé pour un seul appelant.
 */
final class StockageMemoire implements StorageInterface
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
}
