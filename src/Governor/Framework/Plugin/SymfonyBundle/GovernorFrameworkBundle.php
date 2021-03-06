<?php

namespace Governor\Framework\Plugin\SymfonyBundle;

use Symfony\Component\HttpKernel\Bundle\Bundle;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Governor\Framework\Plugin\SymfonyBundle\DependencyInjection\Compiler\EventHandlerPass;
use Governor\Framework\Plugin\SymfonyBundle\DependencyInjection\Compiler\CommandHandlerPass;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;

class GovernorFrameworkBundle extends Bundle
{

    public function build(ContainerBuilder $container)
    {
        parent::build($container);

        $container->addCompilerPass(new CommandHandlerPass(),
                PassConfig::TYPE_BEFORE_REMOVING);
        $container->addCompilerPass(new EventHandlerPass(),
                PassConfig::TYPE_BEFORE_REMOVING);
    }

}
