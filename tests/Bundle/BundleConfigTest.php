<?php

declare(strict_types=1);

namespace Fewohbee\PaymentCore\Tests\Bundle;

use Fewohbee\PaymentCore\Adapter\Payactive\PayactiveClient;
use Fewohbee\PaymentCore\Adapter\Payactive\PayactiveProvider;
use Fewohbee\PaymentCore\Adapter\Payactive\PayactiveWebhookHandler;
use Fewohbee\PaymentCore\PaymentCoreBundle;
use Fewohbee\PaymentCore\Provider\PaymentProviderRegistry;
use Fewohbee\PaymentCore\Service\PaymentService;
use Fewohbee\PaymentCore\Webhook\WebhookHandlerRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\Compiler\ResolveInstanceofConditionalsPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Lightweight DI smoke test: loads the bundle extension into a bare
 * ContainerBuilder (no full kernel) and asserts the wiring is in place.
 */
final class BundleConfigTest extends TestCase
{
    /** @param list<array<string, mixed>> $configs */
    private function loadContainer(array $configs = []): ContainerBuilder
    {
        $container = new ContainerBuilder();
        (new PaymentCoreBundle())->getContainerExtension()->load($configs, $container);

        return $container;
    }

    public function testServicesAreRegistered(): void
    {
        $container = $this->loadContainer();

        foreach ([
            PaymentService::class,
            PaymentProviderRegistry::class,
            WebhookHandlerRegistry::class,
            PayactiveProvider::class,
            PayactiveClient::class,
            PayactiveWebhookHandler::class,
        ] as $id) {
            self::assertTrue($container->hasDefinition($id), sprintf('Service %s should be defined.', $id));
        }
    }

    public function testConfigParametersDefaultToEnvVars(): void
    {
        $container = $this->loadContainer();

        self::assertSame('%env(PAYMENT_PROVIDER)%', $container->getParameter('payment_core.active_provider'));
        self::assertSame('%env(PAYACTIVE_API_KEY)%', $container->getParameter('payment_core.payactive.api_key'));
        self::assertSame('%env(PAYACTIVE_API_BASE_URL)%', $container->getParameter('payment_core.payactive.base_url'));
        self::assertSame('%env(PAYACTIVE_WEBHOOK_SECRET)%', $container->getParameter('payment_core.payactive.webhook_secret'));
        self::assertSame(
            ['ONLINE_PAYMENT', 'CREDIT_CARD'],
            $container->getParameter('payment_core.payactive.invoice_payment_methods'),
        );
    }

    public function testProviderRegistryWiring(): void
    {
        $container = $this->loadContainer();
        $def = $container->getDefinition(PaymentProviderRegistry::class);

        $providers = $def->getArgument('$providers');
        self::assertInstanceOf(TaggedIteratorArgument::class, $providers);
        self::assertSame('app.payment.provider', $providers->getTag());

        self::assertSame('%payment_core.active_provider%', $def->getArgument('$activeProviderId'));
    }

    public function testInvoicePaymentMethodsAreWiredToProvider(): void
    {
        $container = $this->loadContainer();

        self::assertSame(
            '%payment_core.payactive.invoice_payment_methods%',
            $container->getDefinition(PayactiveProvider::class)->getArgument('$invoicePaymentMethods'),
        );
    }

    public function testInvoicePaymentMethodsAcceptCsvEnvironmentProcessor(): void
    {
        $container = $this->loadContainer([[
            'payactive' => [
                'invoice_payment_methods' => '%env(csv:PAYACTIVE_INVOICE_PAYMENT_METHODS)%',
            ],
        ]]);

        self::assertSame(
            '%env(csv:PAYACTIVE_INVOICE_PAYMENT_METHODS)%',
            $container->getParameter('payment_core.payactive.invoice_payment_methods'),
        );
    }

    public function testInstanceofConditionalsTagImplementations(): void
    {
        $container = $this->loadContainer();
        // Apply the _instanceof conditionals so the interface-based tags land on
        // the concrete definitions, exactly as they would during a full compile.
        (new ResolveInstanceofConditionalsPass())->process($container);

        self::assertTrue(
            $container->getDefinition(PayactiveProvider::class)->hasTag('app.payment.provider'),
            'PayactiveProvider should be tagged app.payment.provider.'
        );
        self::assertTrue(
            $container->getDefinition(PayactiveWebhookHandler::class)->hasTag('app.payment.webhook_handler'),
            'PayactiveWebhookHandler should be tagged app.payment.webhook_handler.'
        );
    }
}
