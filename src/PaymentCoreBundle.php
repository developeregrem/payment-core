<?php

declare(strict_types=1);

namespace Fewohbee\PaymentCore;

use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * Symfony bundle for the provider-agnostic payment-core module.
 *
 * Registering this bundle gives a consuming application:
 *  - all payment-core services (PaymentService, registries, Payactive adapter, commands),
 *  - the `app.payment.provider` / `app.payment.webhook_handler` autoconfiguration tags,
 *  - the Doctrine ORM mapping for PaymentTransaction (prepended automatically),
 * without copying any service or mapping config by hand.
 *
 * Configuration (all default to the documented env vars):
 *
 *   payment_core:
 *       active_provider:  '%env(PAYMENT_PROVIDER)%'
 *       payactive:
 *           api_key:        '%env(PAYACTIVE_API_KEY)%'
 *           base_url:       '%env(PAYACTIVE_API_BASE_URL)%'
 *           webhook_secret: '%env(PAYACTIVE_WEBHOOK_SECRET)%'
 *           invoice_payment_methods: ['ONLINE_PAYMENT', 'CREDIT_CARD']
 */
class PaymentCoreBundle extends AbstractBundle
{
    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->scalarNode('active_provider')
                    ->defaultValue('%env(PAYMENT_PROVIDER)%')
                    ->info('Id of the active payment provider, e.g. "payactive".')
                ->end()
                ->arrayNode('payactive')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('api_key')->defaultValue('%env(PAYACTIVE_API_KEY)%')->end()
                        ->scalarNode('base_url')->defaultValue('%env(PAYACTIVE_API_BASE_URL)%')->end()
                        ->scalarNode('webhook_secret')->defaultValue('%env(PAYACTIVE_WEBHOOK_SECRET)%')->end()
                        ->scalarNode('creditor_bank_account_id')
                            ->defaultValue('%env(default::PAYACTIVE_CREDITOR_BANK_ACCOUNT_ID)%')
                            ->info('Creditor bank account id used for invoices (no listing API; copy from the Payactive portal).')
                        ->end()
                        ->arrayNode('payment_methods')
                            ->scalarPrototype()->end()
                            ->defaultValue(['CUSTOMERS_CHOICE'])
                            ->info('Methods offered on POST /payments (payment-first). CUSTOMERS_CHOICE is valid here and lets the payer pick.')
                        ->end()
                        ->variableNode('invoice_payment_methods')
                            ->defaultValue(['ONLINE_PAYMENT', 'CREDIT_CARD'])
                            ->info('Methods offered by invoice payment flows. Existing direct-debit mandates take precedence at Payactive.')
                            ->validate()
                                ->ifTrue(static fn (mixed $value): bool => !is_array($value)
                                    && !(is_string($value) && str_starts_with($value, '%env(') && str_ends_with($value, ')%')))
                                ->thenInvalid('Expected a list of payment methods or a Symfony env placeholder.')
                            ->end()
                        ->end()
                        ->scalarNode('customer_payment_method')
                            ->defaultValue('ONLINE_PAYMENT')
                            ->info('Concrete method stored on the customer (ONLINE_PAYMENT|MANUAL_PAYMENT|DIRECT_DEBIT|PAPERLESS). NOT CUSTOMERS_CHOICE. Determines an invoice-first payment\'s method.')
                        ->end()
                    ->end()
                ->end()
            ->end();
    }

    /**
     * @param array{active_provider: string, payactive: array{api_key: string, base_url: string, webhook_secret: string, creditor_bank_account_id: ?string, payment_methods: list<string>, invoice_payment_methods: list<string>|string, customer_payment_method: string}} $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->parameters()
            ->set('payment_core.active_provider', $config['active_provider'])
            ->set('payment_core.payactive.api_key', $config['payactive']['api_key'])
            ->set('payment_core.payactive.base_url', $config['payactive']['base_url'])
            ->set('payment_core.payactive.webhook_secret', $config['payactive']['webhook_secret'])
            ->set('payment_core.payactive.creditor_bank_account_id', $config['payactive']['creditor_bank_account_id'])
            ->set('payment_core.payactive.payment_methods', $config['payactive']['payment_methods'])
            ->set('payment_core.payactive.invoice_payment_methods', $config['payactive']['invoice_payment_methods'])
            ->set('payment_core.payactive.customer_payment_method', $config['payactive']['customer_payment_method']);

        $container->import(\dirname(__DIR__).'/config/services.php');
    }

    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        // Register the PaymentTransaction ORM mapping so consumers don't have to.
        if (!$builder->hasExtension('doctrine')) {
            return;
        }

        $builder->prependExtensionConfig('doctrine', [
            'orm' => [
                'mappings' => [
                    'PaymentCore' => [
                        'type' => 'attribute',
                        'is_bundle' => false,
                        'dir' => \dirname(__DIR__).'/src/Entity',
                        'prefix' => 'Fewohbee\\PaymentCore\\Entity',
                        'alias' => 'PaymentCore',
                    ],
                ],
            ],
        ]);
    }
}
