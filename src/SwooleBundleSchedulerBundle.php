<?php

declare(strict_types=1);

namespace SwooleBundle\Scheduler;

use SwooleBundle\Scheduler\DependencyInjection\SwooleBundleSchedulerExtension;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

final class SwooleBundleSchedulerBundle extends Bundle
{
    public function getContainerExtension(): ?ExtensionInterface
    {
        if ($this->extension === null) {
            $this->extension = new SwooleBundleSchedulerExtension();
        }

        return $this->extension === false ? null : $this->extension;
    }
}
