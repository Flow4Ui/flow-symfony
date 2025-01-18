<?php

namespace Flow\Service;


use Symfony\Component\DependencyInjection\Attribute\TaggedIterator;
use Symfony\Component\HttpKernel\CacheWarmer\CacheWarmer;
use Symfony\Component\HttpKernel\CacheWarmer\CacheWarmerInterface;

/**
 * A simple Cache Warmer that calls our Flow Manager
 * to compile all known components at cache warmup time.
 */
class FlowComponentCacheWarmer extends CacheWarmer implements CacheWarmerInterface
{
    private Manager $manager;

    private iterable $components;
    private Registry $registry;

    public function __construct(
        Manager                                      $manager,
        Registry                                     $registry,
        #[TaggedIterator('flow.component')] iterable $components,
    )
    {
        $this->registry = $registry;
        $this->manager = $manager;
        $this->components = $components;
    }

    /**
     * This is the method Symfony calls during `cache:warmup`.
     * $cacheDir is the new cache directory Symfony is warming.
     */
    public function warmUp(string $cacheDir, null|string $buildDir = null): array
    {
        foreach ($this->components as $component) {
            $componentDefinition = $this->registry->getComponentDefinition($component::class);
            $this->manager->warmUpComponent($componentDefinition, $component::class);
        }

        return [];
    }

    /**
     * Whether this warmer is optional. If "true" and something fails,
     * Symfony can skip it. Usually you return "false" if you consider
     * this mandatory for correct operation.
     */
    public function isOptional(): bool
    {
        return false;
    }
}
