<?php

declare(strict_types=1);

namespace App\Services\ObfuscationRecovery;

use aharen\OMDbAPI;
use GuzzleHttp\Client;

final class RecoveryOmdbClient extends OMDbAPI
{
    /** @var Client */
    protected $client; // @phpstan-ignore property.phpDocType (The vendor PHPDoc incorrectly resolves GuzzleHttp\Client relative to aharen.)

    public function __construct(string $apiKey)
    {
        parent::__construct($apiKey);
        $this->client = RecoveryCatalog::client(['base_uri' => $this->host]);
    }
}
