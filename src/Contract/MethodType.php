<?php

namespace Flow\Contract;

enum MethodType: string
{
    case Method = 'method';
    case LifecycleEvent = 'lifecycleEvent';
    case Watch = 'watch';

    //  case Route = 'route';
}
