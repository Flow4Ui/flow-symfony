<?php

namespace Flow\Service;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class FlowTwigExtension extends AbstractExtension
{
    public function __construct(
        protected Manager $manager
    )
    {
    }

    public function getFunctions()
    {
        return [
            new TwigFunction(
                'flow_options',
                function ($options = []) {
                    return $this->manager->compileJsFlowOptions($options);
                },
                [
                    'is_safe' => ['html'],
                ]
            )
        ];
    }
}