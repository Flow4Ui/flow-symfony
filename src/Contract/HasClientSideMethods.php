<?php

namespace Flow\Contract;

interface HasClientSideMethods
{
    /**
     * Return clientSideMethods
     * @return Methods
     */
    public function getClientSideMethods(): Methods;
}