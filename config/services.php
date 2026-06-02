<?php

declare(strict_types=1);

use Fewohbee\PaymentCore\Adapter\Payactive\PayactiveClient;
use Fewohbee\PaymentCore\Adapter\Payactive\PayactiveWebhookHandler;
use Fewohbee\PaymentCore\Provider\PaymentProviderInterface;
use Fewohbee\PaymentCore\Provider\PaymentProviderRegistry;
use Fewohbee\PaymentCore\Webhook\WebhookHandlerInterface;
use Fewohbee\PaymentCore\Webhook\WebhookHandlerRegistry;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;

/*
 * Canonical DI wiring for fewohbee/payment-core, loaded by PaymentCoreBundle.
 * Env-dependent scalars come in as container parameters set in the bundle's
 * loadExtension() (payment_core.* — overridable via the bundle config).
 */
return static function (Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator $container): void {
    $services = $container->services();

    $services->defaults()
        ->autowire()
        ->autoconfigure();

    // Tag every provider / webhook handler so the registries can collect them.
    $services->instanceof(PaymentProviderInterface::class)
        ->tag('app.payment.provider');
    $services->instanceof(WebhookHandlerInterface::class)
        ->tag('app.payment.webhook_handler');

    // Register all package classes as services, except value objects / entities.
    $services->load('Fewohbee\\PaymentCore\\', \dirname(__DIR__).'/src/')
        ->exclude([
            \dirname(__DIR__).'/src/Dto/',
            \dirname(__DIR__).'/src/Enum/',
            \dirname(__DIR__).'/src/Event/',
            \dirname(__DIR__).'/src/Entity/',
            \dirname(__DIR__).'/src/Exception/',
            \dirname(__DIR__).'/src/PaymentCoreBundle.php',
        ]);

    $services->get(PaymentProviderRegistry::class)
        ->arg('$providers', tagged_iterator('app.payment.provider'))
        ->arg('$activeProviderId', param('payment_core.active_provider'));

    $services->get(WebhookHandlerRegistry::class)
        ->arg('$handlers', tagged_iterator('app.payment.webhook_handler'));

    $services->get(PayactiveClient::class)
        ->arg('$apiKey', param('payment_core.payactive.api_key'))
        ->arg('$baseUrl', param('payment_core.payactive.base_url'));

    $services->get(PayactiveWebhookHandler::class)
        ->arg('$signingSecret', param('payment_core.payactive.webhook_secret'));
};
