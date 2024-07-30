<?php

namespace Flow\Service;

use Symfony\Component\Asset\Packages;
use Twig\{Extension\AbstractExtension, TwigFunction};

class FlowTwigExtension extends AbstractExtension
{
    public function __construct(
        protected Manager  $manager,
        protected Packages $packages,
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
            ),
            new TwigFunction(
                'flow_loader',
                function ($options = []) {
                    return sprintf('<script>window.FlowOptions=%s;</script>', $this->manager->compileJsFlowOptions($options));
                },
                [
                    'is_safe' => ['html'],
                ]
            )
        ];
    }
}