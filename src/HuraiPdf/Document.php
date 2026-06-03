<?php

declare(strict_types=1);

namespace HuraiPdf;

final class Document
{
    /**
     * @param Page[] $pages
     * @param string[] $warnings
     */
    public function __construct(
        private readonly array $pages,
        private readonly string $pdfVersion,
        private readonly bool $encrypted,
        private readonly array $warnings
    ) {
    }

    /**
     * @return Page[]
     */
    public function getPages(): array
    {
        return $this->pages;
    }

    public function getPageCount(): int
    {
        return count($this->pages);
    }

    public function getPdfVersion(): string
    {
        return $this->pdfVersion;
    }

    public function isEncrypted(): bool
    {
        return $this->encrypted;
    }

    /**
     * @return string[]
     */
    public function getWarnings(): array
    {
        return $this->warnings;
    }

    public function getText(?int $pageLimit = null): string
    {
        $chunks = [];
        $maxPages = $pageLimit ?? count($this->pages);

        foreach ($this->pages as $index => $page) {
            if ($index >= $maxPages) {
                break;
            }

            $text = trim($page->getText());
            if ($text !== '') {
                $chunks[] = $text;
            }
        }

        return trim(implode(' ', $chunks));
    }

    /**
     * Yields one page's trimmed text at a time, avoiding a full in-memory string.
     * Useful for streaming large documents to disk page-by-page.
     *
     * @return \Generator<int, string>
     */
    public function getTextGenerator(?int $pageLimit = null): \Generator
    {
        $maxPages = $pageLimit ?? count($this->pages);

        foreach ($this->pages as $index => $page) {
            if ($index >= $maxPages) {
                break;
            }

            $text = trim($page->getText());
            if ($text !== '') {
                yield $index => $text;
            }
        }
    }
}
