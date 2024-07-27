<?php

namespace Flow\Component;

use Flow\Exception\FlowException;

class ForElement extends Element
{


    public function __construct(
        public Expression   $for,
        public Expression   $in,
        public Element      $do,
        public Element|null $key = null,
    )
    {
        parent::__construct('For');
    }

    public function render(Context|null $context = null): string
    {
        $context ??= new Context();

        if (!preg_match_all('/\w+(?=\s*(,|\)|$))/', $this->for->expression, $matches)) {
            throw new FlowException('unexpected value in v-for ' . $this->for->expression);
        }


        $context = $context->addScope($matches[0]);
        return sprintf(
            '(v.renderList(%s,%s => %s))',
            $this->in->render($context),
            $this->for->render($context),
            $this->do->render(($this->key ? $context : $context->withNextKey($this->key))->withForceNewBlock()),
        );
//        return sprintf(
//            '(v.openBlock(true),v.createElementBlock(v.Fragment,null,v.renderList(%s,%s => %s),%d))',
//            $this->in->render($context),
//            $this->for->render($context),
//            $this->do->render(($this->key ? $context : $context->withNextKey($this->key))->withForceNewBlock()),
//            $this->pathFlags,// keyed and unkeyed loop
//        );
    }
}