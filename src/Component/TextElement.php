<?php

namespace Flow\Component;

class TextElement extends Element
{

    public function __construct(public string $text)
    {
        parent::__construct('Text');
    }

    public function render(?Context $context = null): string
    {
        return sprintf('v.createTextVNode(%s)', json_encode($this->text));
    }
}