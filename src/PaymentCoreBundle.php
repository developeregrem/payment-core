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
                    ->end()
                ->end()
            ->end();
    }

    /**
     * @param array{active_provider: string, payactive: array{api_key: string, base_url: string, webhook_secret: string}} $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->parameters()
            ->set('payment_core.active_provider', $config['active_provider'])
            ->set('payment_core.payactive.api_key', $config['payactive']['api_key'])
            ->set('payment_core.payactive.base_url', $config['payactive']['base_url'])
            ->set('payment_core.payactive.webhook_secret', $config['payactive']['webhook_secret']);

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
