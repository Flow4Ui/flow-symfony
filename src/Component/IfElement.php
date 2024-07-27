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
        if ($this->else !== null) {
            return sprintf('(%s?(v.openBlock(),%s):(v.openBlock(),%s))', $this->if->render($context), $this->do->render($context), $this->else->render($context));
        } else {
            return sprintf('(%s?(v.openBlock(),%s):v.createCommentVNode("v-if", true))', $this->if->render($context), $this->do->render($context));
        }
    }
}