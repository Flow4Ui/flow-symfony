<?php

namespace Flow\Enum;

enum Direction: string
{
    case Server = 'Server';
    case Client = 'Client';
    case Booth = 'Booth';
    case None = 'NONE';
}
