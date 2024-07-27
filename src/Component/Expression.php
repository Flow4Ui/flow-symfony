<?php

namespace Flow\Component;

class Expression extends Element
{

    public bool $isTextExpression = false;
    /**
     * @var true
     */
    public bool $isFnHandler = false;

    public function __construct(public string $expression)
    {
        parent::__construct('Expression');
    }

    public function render(Context|null $context = null): string
    {

        $expression = $context->parseExpression($this->expression);
        if ($this->isTextExpression) {
            return sprintf('v.createTextVNode(v.toDisplayString(%s))', $expression);
        }
        return $expression;
    }
}