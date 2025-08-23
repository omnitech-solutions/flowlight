<?php

namespace Flowlight\Enums;

enum ContextStatus: string
{
    case COMPLETE = 'COMPLETE';
    case INCOMPLETE = 'INCOMPLETE';
    case FAILED = 'FAILED';
}
