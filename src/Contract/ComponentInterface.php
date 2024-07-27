<?php

namespace Flow\Contract;

use Flow\Component\Context;
use Flow\Component\Element;

interface ComponentInterface
{
    public function build(Context $context): Element;
}