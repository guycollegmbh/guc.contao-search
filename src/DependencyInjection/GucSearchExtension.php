<?php

declare(strict_types=1);

namespace Guc\SearchBundle\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

class GucSearchExtension extends Extension
{
    public function getAlias(): string
    {
        return 'guc_search';
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        $loader = new YamlFileLoader($container, new FileLocator(\dirname(__DIR__, 2).'/config'));
        $loader->load('services.yaml');

        // Register Twig namespace @GucSearch → templates/
        $twigPaths = $container->hasParameter('twig.paths') ? $container->getParameter('twig.paths') : [];
        $twigPaths[\dirname(__DIR__, 2).'/templates'] = 'GucSearch';
        $container->setParameter('twig.paths', $twigPaths);
    }
}
