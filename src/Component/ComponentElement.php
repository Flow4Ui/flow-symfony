<?php

namespace Flow\Component;

use Flow\Template\Compiler;

class ComponentElement extends Element
{

    protected string|null $component;
    protected $componentIs;

    public function __construct(string|null $component = null, array $props = [], Element|array|string $children = [])
    {
        $this->component = $component;
        parent::__construct('component', $props, $children);
    }

    public function renderChildren(?Context $context = null): array
    {

        /**
         * @var $returnChildren TemplateElement[]
         */
        $returnChildren = [];
        $children = [];

        if (!is_array($this->children)) {
            $this->children = [$this->children];
        }

        foreach ($this->children as $child) {
            if ($child::class === TemplateElement::class && !empty($child->name)) {
                $returnChildren[$child->name] = isset($returnChildren[$child->name]) ? $returnChildren[$child->name]->merge($child) : $child;
            } else {
                $children[] = $child;
            }
        }

        if (!empty($children)) {
            $returnChildren['default'] = isset($returnChildren['default']) ?
                $returnChildren['default']->addChildren($children)
                : new TemplateElement('default', children: $children);
        }

        $this->children = $returnChildren;
        $renderedChildren = parent::renderChildren($context);
        // $renderedChildren[] = '_:2';
        return $renderedChildren;
    }

    protected function compile(string $template)
    {
        return (new Compiler())->compile($template, new Context());
    }


    protected function renderProp(int|string $prop, mixed $value, ?Context $context): array
    {
        if ($this->component === 'component' && $prop === 'is') {
            $this->componentIs = $this->renderValue($value, $context);
            return [];
        }
        return parent::renderProp($prop, $value, $context); // TODO: Change the autogenerated stub
    }

    protected function renderCall($props, $children, Context|null $context): string
    {
        // component render
        $componentName = empty($this->component) ? get_class($this) : $this->component;

        if ($componentName === 'component') {
            $tag = sprintf('v.resolveDynamicComponent(%s)', $this->componentIs);
        } else {
            $tag = $context->resolveComponent($componentName);
        }
        if ($context->newBlock) {
            $renderedElement = sprintf(
                '(v.openBlock(),v.createBlock(%s,%s,{%s},%s,%s))',
                $tag,
                $props,
                implode(',', $children),
                $this->pathFlags,
                json_encode($this->dynamicProperties)
            );
            $context->newBlock = false;
        } else {
            $renderedElement = sprintf(
                'v.createVNode(%s,%s,{%s},%s,%s)',
                //  'v.createVNode(%s,%s,{%s})',
                $tag,
                $props,
                implode(',', $children),
                $this->pathFlags,
                json_encode($this->dynamicProperties),
            );
            //$renderedElement = sprintf('c(%s,%s,{%s},rc)', $tag, $props, implode(',', $children));
        }

        return $renderedElement;
    }
}