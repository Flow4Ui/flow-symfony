<?php

namespace Flow\Component;

use Flow\Exception\FlowException;

class TemplateElement extends Element
{
    const V_ON = 'v-on:';
    const V_SLOT = 'v-slot:';

    const DEFAULT_VALUE = '="1" ';


    public function __construct(public string        $name = '',
                                array                $props = [],
                                Element|array|string $children = [],
                                public string|null   $propsName = null,
                                public readonly bool $compile = false,
    )
    {

        parent::__construct('Template', $props, $children);
    }

    public function render(?Context $context = null): string
    {
        if ($this->name === '') {
            //todo: check, this case will be converted to fragment at compile time
            return implode(',', $this->renderChildren($context));
        }
        $propsName = $this->propsName ?? $this->props['props-name'] ?? $this->props['propsName'] ?? '';
        if (!empty($propsName)) {
            $propsNameScope = [];
            if (str_starts_with($propsName, '{')) {
                $propsNameScope = array_map('trim', explode(',', substr($propsName, 1, -1)));
            } else {
                $propsNameScope[] = $propsName;
            }
            $context = $context->addScope($propsNameScope);
        }

        return sprintf('%s:v.withCtx((%s)=>{return [%s];}/*,undefined,true*/)', json_encode($this->name), $propsName, implode(',', $this->renderChildren($context)));
    }

    /**
     * @throws FlowException
     */
    public function renderChildren(?Context $context = null): array
    {
        if ($this->compile) {
            $children = [];
            $currentChildren = !is_array($this->children) ? [$this->children] : $children;

            foreach ($currentChildren as $index => $child) {
                if ($child instanceof Element) {
                    $children[$index] = $child->render($context);
                } else {
                    $element = $this->compile($child, $context);
                    $children[$index] = $element->render($context);
                }
            }
            return $children;
        }
        return parent::renderChildren($context);
    }


    public function merge(TemplateElement $child): self
    {
        array_push($this->children, ...(array)$child->children);
        return $this;
    }

    public function addChildren(array $children): self
    {
        array_push($this->children, ...$children);
        return $this;
    }


}