<?php

namespace Flow\Component;

class FragmentElement extends Element
{

    public function render(Context|null $context = null): string
    {
        $context ??= new Context();
        $children = $this->renderChildren($context);

        $renderedFragment = count($children) >= 2 ?
            sprintf('(v.openBlock(),v.createElementBlock(v.Fragment,null,[%s],%s))', implode(',', $children), $this->pathFlags) :
            $children[0];
        if ($this->isRoot) {
            $assignments = [];
            foreach ($context->componentsElements as $component => $name) {
                $assignments[] = sprintf("%s = v.resolveComponent(%s)", $name, json_encode($component));
            }

            $renderedFragment = !empty($assignments) ?
                sprintf('const %s; return %s;', implode(',', $assignments), $renderedFragment) :
                sprintf('return %s;', $renderedFragment);
        }
        return $renderedFragment;
    }
}