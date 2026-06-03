<?php

declare(strict_types=1);

namespace HuraiPdf;

final class PdfObject
{
    public function __construct(
        public readonly int $id,
        public readonly int $generation,
        public readonly string $body,
        public readonly int $offset,
        public readonly bool $fromObjectStream
    ) {
    }
}
