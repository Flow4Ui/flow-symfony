<?php

namespace Flow\Contract;

use Flow\Component\Context;
use Flow\Component\Element;

interface ComponentBuilderInterface
{
    public function build(Context $context): Element;
}