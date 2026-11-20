<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Evolve\Webhooks\Signature;
use Evolve\Webhooks\WebhookEvent;
use Evolve\Webhooks\WebhooksMigrationContributor;
use Evolve\Webhooks\WebhooksModule;
use Evolve\Webhooks\WebhookSubscription;

foreach ([Signature::class, WebhookEvent::class, WebhookSubscription::class, WebhooksMigrationContributor::class, WebhooksModule::class] as $symbol) {
    if (!class_exists($symbol)) {
        throw new RuntimeException('Missing installed symbol: ' . $symbol);
    }
}
$event = new WebhookEvent('consumer-event-1', 'user.created', 'tenant-1', ['id' => 1]);
$subscription = new WebhookSubscription('consumer-sub-1', 'user.created', 'tenant-1', 'https://example.com/hook', 'key-1');
if (!$subscription->matches($event) || $event->payloadJson() !== '{"id":1}') {
    throw new RuntimeException('Independent event matching failed.');
}
$headers = Signature::sign($event->payloadJson(), 'consumer-delivery-1', 'key-1', 'secret', 1000);
if (!Signature::verify($event->payloadJson(), $headers, 'secret', 1000, 60)) {
    throw new RuntimeException('Independent signature check failed.');
}
if (count(iterator_to_array((new WebhooksMigrationContributor())->definitions())) !== 1) {
    throw new RuntimeException('Independent migration discovery failed.');
}
echo "Independent webhooks consumer passed\n";
