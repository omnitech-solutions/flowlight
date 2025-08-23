<?php

namespace Flowlight\Exceptions;

use Flowlight\Context;
use RuntimeException;

final class ContextFailedError extends RuntimeException
{
    public readonly Context $context;

    public function __construct(
        Context $context,
        string $message = 'Flowlight context failed.',
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
        $this->context = $context;
    }

    public function getContext(): Context
    {
        return $this->context;
    }
}
