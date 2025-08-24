<?php

declare(strict_types=1);

namespace Flowlight\Enums;

enum ContextOperation: string
{
    case CREATE = 'CREATE';
    case UPDATE = 'UPDATE';
}
