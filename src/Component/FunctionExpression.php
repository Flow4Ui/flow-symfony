<?php

namespace Flow\Component;

class FunctionExpression extends Expression
{
    public function __construct(public Expression|string $body, public $args = [])
    {
        parent::__construct('On');
    }

    public function render(?Context $context = null): string
    {
        return sprintf('(%s) => %s', implode(',', $this->args), $this->body); // TODO: Change the autogenerated stub
    }

}