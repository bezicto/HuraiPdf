<?php

declare(strict_types=1);

namespace HuraiPdf;

final class Page
{
    public function __construct(
        private readonly int $pageNumber,
        private readonly int $objectId,
        private readonly string $text
    ) {
    }

    public function getPageNumber(): int
    {
        return $this->pageNumber;
    }

    public function getObjectId(): int
    {
        return $this->objectId;
    }

    public function getText(): string
    {
        return $this->text;
    }
}
