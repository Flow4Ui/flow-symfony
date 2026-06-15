<?php

namespace Flow\Component;

class FragmentElement extends Element
{

    public function render(Context|null $context = null): string
    {
        $context ??= new Context();
        $childrenCount = is_array($this->children) ? count($this->children) : 1;
        $renderAsBlock = $context->newBlock && ($childrenCount >= 2 || !empty($this->props));
        if ($renderAsBlock) {
            $context->newBlock = false;
        }

        $children = $this->renderChildren($context);
        $props = $this->renderProps($context);

        $renderedFragment = count($children) >= 2 || !empty($this->props) ?
            sprintf('(v.openBlock(),v.createElementBlock(v.Fragment,%s,[%s],%s))',
                $props,
                implode(',', $children),
                $this->pathFlags) :
            $children[0];
        if ($this->isRoot) {
            $assignments = [];
            foreach ($context->componentsElements as $component => $name) {
                $assignments[] = sprintf("%s = v.resolveComponent(%s)", $name, json_encode($component));
            }

            foreach ($context->directives as $directive => $name) {
                $assignments[] = sprintf("%s = v.resolveDirective(%s)", $name, json_encode($directive));
            }

            $renderedFragment = !empty($assignments) ?
                sprintf('const %s; return %s;', implode(',', $assignments), $renderedFragment) :
                sprintf('return %s;', $renderedFragment);
        }
        return $renderedFragment;
    }
}
