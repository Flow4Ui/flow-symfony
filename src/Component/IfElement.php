<?php

namespace Flow\Component;

class IfElement extends Element
{
    public function __construct(public Element $if, public Element $do, public Element|null $else = null)
    {
        parent::__construct('If');
    }

    public function render(Context|null $context = null): string
    {
        $condition = sprintf('(%s)', $this->if->render($context));
        $do = $this->do->render($context ?? new Context());

        if ($this->else !== null) {
            $else = $this->else->render($context ?? new Context());

            return sprintf('(%s?%s:%s)', $condition, $do, $else);
        } else {
            return sprintf('(%s?%s:v.createCommentVNode("v-if", true))', $condition, $do);
        }
    }
}
