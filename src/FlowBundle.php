<?php

namespace Flow;

use Flow\{Compiler\AttributeCompilerPass};
use Symfony\Component\DependencyInjection\{ContainerBuilder};
use Symfony\Component\HttpKernel\Bundle\Bundle;

class FlowBundle extends Bundle
{

    public function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new AttributeCompilerPass());
    }
}
