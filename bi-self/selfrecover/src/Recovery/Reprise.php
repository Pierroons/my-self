<?php

declare(strict_types=1);

namespace Pierroons\SelfRecover\Recovery;

/**
 * Ce qu'une récupération acceptée remet à la suite branchée
 * (`SuiteDeReprise`), dans la transaction qui l'accepte : le chemin, le compte
 * sous sa forme rangée, le secret qui a servi et les secrets neufs.
 *
 * Elle ne vit que le temps de l'appel. Rien d'elle n'est rangé : les secrets
 * qu'elle porte sont ceux que la réponse rend déjà au titulaire, plus celui
 * qu'il vient de saisir.
 */
final class Reprise
{
    /** Niveau 1 : la passphrase a servi. */
    public const NIVEAU_1 = 'niveau_1';
    /** Niveau 2 : un code papier et le mot mémorisé ont servi. */
    public const NIVEAU_2 = 'niveau_2';
    /** Niveau 2 par appareil : aucun secret du coffre n'a servi. */
    public const APPAREIL = 'appareil';
    /** Niveau 3 : l'arbitre a accordé, aucun ancien secret n'a servi. */
    public const NIVEAU_3 = 'niveau_3';
    /** Le titulaire revient dans la fenêtre d'après un niveau 3 avec un secret d'avant. */
    public const RETOUR_TITULAIRE = 'retour_titulaire';
    /** L'arbitre clôt le litige de ce retour. */
    public const CLOTURE = 'cloture';

    /** La serrure du secret servi, quand il y en a un. */
    public const SERRURE_PASSPHRASE   = 'passphrase';
    public const SERRURE_MOT_MEMORISE = 'mot_memorise';

    public function __construct(
        public readonly string $chemin,
        public readonly int $compteId,
        /** Sous sa forme rangée : c'est sous elle que le coffre suit. */
        public readonly string $compte,
        public readonly int $instant,
        public readonly ?string $serrureServie = null,
        #[\SensitiveParameter] public readonly ?string $secretServi = null,
        #[\SensitiveParameter] public readonly ?string $motDePasse = null,
        #[\SensitiveParameter] public readonly ?string $passphrase = null,
        /** Au niveau 3 : l'empreinte dérivée du mot mémorisé neuf, telle que le navigateur l'a envoyée. */
        #[\SensitiveParameter] public readonly ?string $motMemorise = null,
        public readonly ?string $numeroLitige = null,
    ) {
    }

    /** Les secrets hors des vidages : `print_r()` d'une reprise ne les montre pas. */
    public function __debugInfo(): array
    {
        return [
            'chemin'        => $this->chemin,
            'compte'        => $this->compte,
            'instant'       => $this->instant,
            'serrureServie' => $this->serrureServie,
            'numeroLitige'  => $this->numeroLitige,
            'secrets'       => '[masqués]',
        ];
    }
}
