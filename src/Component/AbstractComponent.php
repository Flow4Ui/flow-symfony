<?php

namespace Flow\Component;

use Flow\Contract\ComponentInterface;
use Flow\Contract\HasCallbacks;
use Flow\Contract\HasClientSideMethods;
use Flow\Contract\Methods;
use Flow\Template\Compiler;

abstract class AbstractComponent implements ComponentInterface, HasClientSideMethods, HasCallbacks
{

    private $callbacks = [];
    private Methods $methods;

    public function addCallback(string $fn, ...$args): self
    {
        $this->callbacks[] = ['fn' => $fn, 'args' => $args];
        return $this;
    }

    /**
     * Returns callbacks
     *
     * @return array<array{fn: string, args: array}>
     */
    public function getCallbacks(): array
    {
        return $this->callbacks;
    }

    public function build(Context $context): Element
    {
        $component = $context->getComponent($this::class);

        $template = $component->template ?? ($component->templatePath ? file_get_contents($component->templatePath) : null);
        return $this->compile($template);
    }

    protected function compile(string $template)
    {
        return (new Compiler())->compile($template, new Context());
    }

    public function getClientSideMethods(): Methods
    {
        $this->methods ??= new Methods();
        return $this->methods;
    }

}