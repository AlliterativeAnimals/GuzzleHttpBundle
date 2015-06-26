<?php
namespace M6Web\Bundle\GuzzleHttpBundle\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\DependencyInjection\Loader;
use Symfony\Component\Config\FileLocator;

/**
 * This is the class that loads and manages bundle configuration
 *
 * To learn more see {@link http://symfony.com/doc/current/cookbook/bundles/extension.html}
 */
class M6WebGuzzleHttpExtension extends Extension
{
    /**
     * {@inheritDoc}
     */
    public function load(array $configs, ContainerBuilder $container)
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        $loader = new Loader\YamlFileLoader($container, new FileLocator(__DIR__.'/../Resources/config'));
        $loader->load('services.yml');

        foreach ($config['clients'] as $clientId => $clientConfig) {
            $this->loadClient($container, $clientId, $clientConfig);
        }

        if ($container->getParameter('kernel.debug')) {
            $loader = new Loader\YamlFileLoader($container, new FileLocator(__DIR__.'/../Resources/config'));
            $loader->load('datacollector.yml');
        }
    }

    /**
     * @param ContainerBuilder $container
     * @param string           $clientId
     * @param array            $config
     */
    protected function loadClient(ContainerBuilder $container, $clientId, array $config)
    {

        if ($config['allow_redirects']) {
            $config['allow_redirects'] = $config['redirects'];
        }
        unset($config['redirects']);

        $handlerStackReference = new Reference('m6web_guzzlehttp.guzzle.handlerstack');

        $middlewareEventDispatcherDefinition = new Definition('%m6web_guzzlehttp.middleware.eventdispatcher.class%');
        $middlewareEventDispatcherDefinition->setArguments([new Reference('event_dispatcher')]);
        $middlewareEventDispatcherDefinition->addMethodCall('push', [$handlerStackReference]);

        // we must assign middleware for build process
        $config['middleware'][] = $middlewareEventDispatcherDefinition;
        $config['handler'] = $handlerStackReference;

        if ($config['redirect_handler'] == 'curl') {
            $config['curl'] = $this->getCurlConfig($config);
        }

        if (array_key_exists('guzzlehttp_cache', $config)) {
            $defaultTtl = $config['guzzlehttp_cache']['default_ttl'];
            $headerTtl = $config['guzzlehttp_cache']['use_header_ttl'];
            $cacheService = new Reference($config['guzzlehttp_cache']['service']);

            $curlHandler = $container->getDefinition('m6web_guzlehttp.handler.curlhandler');
            $curlMultiHandler = $container->getDefinition('m6web_guzlehttp.handler.curlmultihandler');

            $curlHandler->addMethodCall('setCache', [$cacheService, $defaultTtl, $headerTtl]);
            $curlMultiHandler->addMethodCall('setCache', [$cacheService, $defaultTtl, $headerTtl]);
        }


        $guzzleClientDefintion = new Definition('%m6web_guzzlehttp.guzzle.client.class%');
        $guzzleClientDefintion->addArgument($config);

        $containerKey = ($clientId == 'default') ? 'm6web_guzzlehttp' : 'm6web_guzzlehttp_'.$clientId;

        $container->setDefinition($containerKey, $guzzleClientDefintion);

    }

    protected function getCurlConfig(array $config)
    {
        $followLocation = (!empty($config['allow_redirects']));
        $maxRedir = $followLocation ? $config['allow_redirects']['max'] : 5;
        $autoReferer = $followLocation ? $config['allow_redirects']['referer'] : true;

        $curlInfo = [
            CURLOPT_FOLLOWLOCATION => $followLocation,
            CURLOPT_MAXREDIRS => $maxRedir,
            CURLOPT_AUTOREFERER => $autoReferer
        ];

        if ($followLocation) {
            $redirProtocols = 0;

            $protocols = array_map('strtolower', $config['allow_redirects']['protocols']);

            if (in_array('http', $protocols)) {
                $redirProtocols |= CURLPROTO_HTTP;
            }

            if (in_array('https', $protocols)) {
                $redirProtocols |= CURLPROTO_HTTPS;
            }

            $curlInfo[CURLOPT_REDIR_PROTOCOLS] = $redirProtocols;
        }

        return $curlInfo;
    }

    /**
     * @return string
     */
    public function getAlias()
    {
        return 'm6web_guzzlehttp';
    }
}