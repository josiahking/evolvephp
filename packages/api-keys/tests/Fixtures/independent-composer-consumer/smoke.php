<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Evolve\ApiKeys\ApiKeyIssueRequest;
use Evolve\ApiKeys\ApiKeys;
use Evolve\ApiKeys\ApiKeysMigrationContributor;
use Evolve\ApiKeys\ApiKeysModule;
use Evolve\ApiKeys\ApiKeyVerificationRequest;
use Evolve\ApiKeys\SqlApiKeyStore;

foreach ([ApiKeys::class, ApiKeysModule::class, ApiKeysMigrationContributor::class, ApiKeyIssueRequest::class, ApiKeyVerificationRequest::class, SqlApiKeyStore::class] as $symbol) {
    if (!class_exists($symbol)) {
        throw new RuntimeException('Missing installed API Keys symbol: ' . $symbol);
    }
}
if (count(iterator_to_array((new ApiKeysMigrationContributor())->definitions())) !== 1) {
    throw new RuntimeException('Independent API Keys migration discovery failed.');
}
echo "Independent API Keys consumer passed\n";
