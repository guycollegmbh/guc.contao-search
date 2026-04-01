<?php

declare(strict_types=1);

namespace Guc\SearchBundle;

use Guc\SearchBundle\DependencyInjection\GucSearchExtension;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

class GucSearchBundle extends Bundle
{
    public function getPath(): string
    {
        return \dirname(__DIR__);
    }

    public function getContainerExtension(): ?ExtensionInterface
    {
        return new GucSearchExtension();
    }

}
