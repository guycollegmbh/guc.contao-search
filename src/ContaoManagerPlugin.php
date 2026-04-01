<?php

declare(strict_types=1);

namespace Guc\SearchBundle;

use Contao\ManagerPlugin\Bundle\BundlePluginInterface;
use Contao\ManagerPlugin\Bundle\Config\BundleConfig;
use Contao\ManagerPlugin\Bundle\Parser\ParserInterface;
use Contao\ManagerPlugin\Routing\RoutingPluginInterface;
use Symfony\Component\Config\Loader\LoaderResolverInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\RouteCollection;

class ContaoManagerPlugin implements BundlePluginInterface, RoutingPluginInterface
{
    public function getBundles(ParserInterface $parser): array
    {
        return [
            BundleConfig::create(GucSearchBundle::class)
                ->setLoadAfter([
                    \Contao\CoreBundle\ContaoCoreBundle::class,
                    \Contao\CalendarBundle\ContaoCalendarBundle::class,
                    \Contao\NewsBundle\ContaoNewsBundle::class,
                ]),
        ];
    }

    public function getRouteCollection(LoaderResolverInterface $resolver, KernelInterface $kernel): ?RouteCollection
    {
        $loader = $resolver->resolve(__DIR__ . '/../config/routes.yaml');

        return $loader ? $loader->load(__DIR__ . '/../config/routes.yaml') : null;
    }
}
