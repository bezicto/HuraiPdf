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
        private readonly array $warnings,
        private readonly Metadata\DocumentInfo|array $details = [],
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

        return trim(implode("\n\n", $chunks));
    }

    /** @return array<string, string> */
    public function getDetails(): array
    {
        return $this->details instanceof Metadata\DocumentInfo ? $this->details->getDetails() : $this->details;
    }

    public function getTitle(): ?string { return $this->getDetails()['Title'] ?? null; }
    public function getAuthor(): ?string { return $this->getDetails()['Author'] ?? null; }
    public function getSubject(): ?string { return $this->getDetails()['Subject'] ?? null; }
    public function getKeywords(): ?string { return $this->getDetails()['Keywords'] ?? null; }
    public function getCreator(): ?string { return $this->getDetails()['Creator'] ?? null; }
    public function getProducer(): ?string { return $this->getDetails()['Producer'] ?? null; }
    public function getCreationDate(): ?string { return $this->getDetails()['CreationDate'] ?? null; }
    public function getModDate(): ?string { return $this->getDetails()['ModDate'] ?? null; }

    /**
     * Yields retained page text without constructing one combined string.
     * Use Parser::parseFilePages() to avoid retaining every page during parsing.
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
