<?php

declare(strict_types=1);

namespace HuraiPdf\Internal;

use HuraiPdf\ParserOptions;
use HuraiPdf\ParseContext;

/** @internal Common access to operation-scoped dependencies; no parsing logic. */
abstract class Subsystem
{
    protected readonly ParserOptions $options;
    protected readonly ParseContext $context;

    public function __construct(protected readonly ParseSession $session)
    {
        $this->options = $session->options;
        $this->context = $session->context;
    }
}
