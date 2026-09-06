<?php

declare(strict_types=1);

namespace HuraiPdf;

use HuraiPdf\Exception\PdfParseException;
use HuraiPdf\Reader\InputSource;

final class Parser
{
    private readonly ParserOptions $options;

    private ?ParseContext $context = null;

    private bool $active = false;

    private Internal\ParseSession $session;

    public function __construct(ParserOptions $options = new ParserOptions())
    {
        $this->options = $options;
    }

    private function beginOperation(?string $password = ''): void
    {
        if ($this->active) {
            throw new \LogicException('This parser has an active operation. Close its generator or use another Parser.');
        }
        $this->context = new ParseContext();
        $this->context->password = $password ?? '';
        $this->session = new Internal\ParseSession($this->options, $this->context);
        $this->active = true;
    }

    public function parseFile(string $filePath, int $fromPage = 1, ?int $toPage = null, ?string $password = ''): Document
    {
        $this->beginOperation($password);

        try {
            $this->validatePageRange($fromPage, $toPage);

            if (!is_file($filePath) || !is_readable($filePath)) {
                throw PdfParseException::fileNotReadable($filePath);
            }

            $fileSize = filesize($filePath);
            $this->session->budget->assertInputBudget($fileSize === false ? 0 : $fileSize);
            if ($fileSize !== false && $fileSize > $this->options->streamingThreshold) {
                $this->context->metrics['streaming_path'] = true;
                return $this->parseFileStreaming($filePath, $fromPage, $toPage);
            }

            $content = file_get_contents($filePath);
            if ($content === false) {
                throw PdfParseException::fileReadFailure();
            }

            return $this->parseContentInternal($content, $fromPage, $toPage);
        } finally {
            $this->context->finish();
            $this->active = false;
            $this->context->objectLoader = null;
        }
    }

    /**
     * Metrics from the most recent parse operation.
     *
     * @return array<string, int|float|bool|string>
     */
    public function getLastMetrics(): array
    {
        return $this->context?->metrics ?? [];
    }

    /**
     * Metadata from the most recent parse operation.
     *
     * @return array{pdf_version:string,is_encrypted:bool,is_decrypted:bool,details:array<string,string>,warnings:string[],page_count:int}
     */
    public function getLastMetadata(): array
    {
        return $this->context?->metadata ?? [
            'pdf_version' => 'unknown',
            'is_encrypted' => false,
            'is_decrypted' => false,
            'details' => [],
            'warnings' => [],
            'page_count' => 0,
        ];
    }

    /**
     * Yield pages without retaining their extracted text in a Document.
     *
     * @return \Generator<int, Page>
     */
    public function parseFilePages(string $filePath, int $fromPage = 1, ?int $toPage = null, ?string $password = ''): \Generator
    {
        $this->beginOperation($password);

        try {
            $this->validatePageRange($fromPage, $toPage);
            if (!is_file($filePath) || !is_readable($filePath)) {
                throw PdfParseException::fileNotReadable($filePath);
            }

            $fileSize = filesize($filePath);
            $this->session->budget->assertInputBudget($fileSize === false ? 0 : $fileSize);
            $this->context->metrics['streaming_path'] = true;
            yield from $this->streamFilePagesInternal($filePath, $fromPage, $toPage);
        } finally {
            $this->context->finish();
            $this->active = false;
            $this->context->objectLoader = null;
        }
    }

    /**
     * Process each page through a callback and return final metadata and metrics.
     *
     * @param callable(Page):void $onPage
     * @return array{metadata:array{pdf_version:string,is_encrypted:bool,is_decrypted:bool,details:array<string,string>,warnings:string[],page_count:int},metrics:array<string,int|float|bool|string>}
     */
    public function extractFile(
        string $filePath,
        callable $onPage,
        int $fromPage = 1,
        ?int $toPage = null,
        ?string $password = ''
    ): array {
        foreach ($this->parseFilePages($filePath, $fromPage, $toPage, $password) as $page) {
            $onPage($page);
        }

        return [
            'metadata' => $this->getLastMetadata(),
            'metrics' => $this->getLastMetrics(),
        ];
    }

    /**
     * Memory-efficient file parsing for large PDFs: reads only the xref table and
     * the individual object bytes actually needed, avoiding a full file_get_contents.
     */
    private function parseFileStreaming(string $filePath, int $fromPage = 1, ?int $toPage = null): Document
    {
        $pages = [];
        foreach ($this->streamFilePagesInternal($filePath, $fromPage, $toPage) as $page) {
            $pages[] = $page;
        }
        $metadata = $this->getLastMetadata();

        return new Document(
            $pages,
            $metadata['pdf_version'],
            $metadata['is_encrypted'],
            $metadata['warnings'],
            $this->context->documentInfo
        );
    }

    /** @return \Generator<int, Page> */
    private function streamFilePagesInternal(string $filePath, int $fromPage, ?int $toPage): \Generator
    {
        $handle = fopen($filePath, 'rb');
        if ($handle === false) {
            throw PdfParseException::fileReadFailure();
        }

        try {
            yield from $this->streamHandlePagesInternal(InputSource::fromHandle($handle), $fromPage, $toPage);
        } finally {
            fclose($handle);
        }
    }

    /** @param InputSource $handle @return \Generator<int, Page> */
    private function streamHandlePagesInternal(InputSource $handle, int $fromPage, ?int $toPage): \Generator
    {
        try {
            $fileSize = $handle->size();
            $this->session->budget->assertInputBudget($fileSize);
            $header = $this->session->reader->readFileRange($handle, 0, min(16, $fileSize));
            if (!str_starts_with($header, '%PDF-')) {
                throw PdfParseException::invalidHeader();
            }
            $warnings = [];
            $index = $this->session->reader->readFileXRefIndex($handle, $fileSize, $warnings);
            if ($index === null || $index['root_id'] === null) {
                $document = $this->parseWholeFileFallback($handle, $fromPage, $toPage);
                foreach ($document->getPages() as $page) {
                    yield $page->getPageNumber() => $page;
                }
                return;
            }

            $objects = [];
            $this->context->objectLoader = function (int $id, bool $formOnly = false) use (&$objects, $index, $handle, &$warnings): bool {
                return $this->session->reader->loadObjectFromIndex($id, $objects, $index, $handle, $warnings);
            };
            $pdfVersion = $this->extractPdfVersion($header);
            $encrypted = $this->initializeDocument($index['trailer'], $objects, $warnings);
            $pageCount = 0;
            $this->recordParseMetadata($pdfVersion, $encrypted, $warnings, 0);
            if ($encrypted) { return; }
            $pageObjectIds = [];
            $ordinal = 0;
            $pagesFound = 0;

            if (!$this->session->reader->loadObjectFromIndex($index['root_id'], $objects, $index, $handle, $warnings)) {
                throw PdfParseException::noObjectsFound();
            }
            $catalogBody = $objects[$index['root_id']]->body;
            if (preg_match('/\/Pages\s+(\d+)\s+\d+\s+R/', $catalogBody, $rootPagesMatch) !== 1) {
                throw PdfParseException::noPagesFound();
            }

            $this->session->pages->walkPageTreeFromIndex(
                (int) $rootPagesMatch[1],
                $fromPage,
                $toPage,
                $ordinal,
                $pagesFound,
                $pageObjectIds,
                $objects,
                $index,
                $handle,
                $warnings
            );

            if ($pagesFound === 0) {
                throw PdfParseException::noPagesFound();
            }

            $pageCount = 0;
            foreach ($pageObjectIds as $pageIndex => $pageObjectId) {
                $text = $this->session->content->extractPageText($pageObjectId, $objects, $warnings);
                $page = new Page($fromPage + $pageIndex, $pageObjectId, $this->session->content->normalizeText($text));
                $pageCount++;
                if ($pageCount === 1) {
                    $this->context->metrics['first_page_ms'] = (hrtime(true) - $this->context->startedAtNanoseconds) / 1_000_000;
                }
                $this->session->budget->releasePageObjects($objects, $pageObjectId);
                $this->recordParseMetadata($pdfVersion, $encrypted, $warnings, $pageCount);
                yield $page->getPageNumber() => $page;
            }
        } finally {
            if (isset($pdfVersion, $encrypted, $warnings, $pageCount)) {
                $this->recordParseMetadata($pdfVersion, $encrypted, $warnings, $pageCount);
            }
        }
    }

    /** @param InputSource $handle */
    private function parseWholeFileFallback(InputSource $handle, int $fromPage, ?int $toPage): Document
    {
        $this->session->budget->assertInputBudget($handle->size());
        $content = $this->session->reader->readFileRange($handle, 0, $handle->size());
        $this->context->metrics['streaming_path'] = false;
        return $this->parseRecoveredContentInternal($content, $fromPage, $toPage);
    }

    public function parseContent(string $content, int $fromPage = 1, ?int $toPage = null, ?string $password = ''): Document
    {
        $this->beginOperation($password);

        try {
            $this->validatePageRange($fromPage, $toPage);
            return $this->parseContentInternal($content, $fromPage, $toPage);
        } finally {
            $this->context->finish();
            $this->active = false;
            $this->context->objectLoader = null;
        }
    }

    private function parseContentInternal(string $content, int $fromPage, ?int $toPage): Document
    {
        $this->session->budget->assertInputBudget(strlen($content));
        $source = InputSource::fromString($content);
        $pages = iterator_to_array($this->streamHandlePagesInternal($source, $fromPage, $toPage), false);
        $metadata = $this->getLastMetadata();
        return new Document($pages, $metadata['pdf_version'], $metadata['is_encrypted'], $metadata['warnings'], $this->context->documentInfo);
    }

    private function parseRecoveredContentInternal(string $content, int $fromPage, ?int $toPage): Document
    {
        $this->validatePageRange($fromPage, $toPage);

        if (!str_starts_with($content, '%PDF-')) {
            throw PdfParseException::invalidHeader();
        }

        $warnings = [];
        $this->session->budget->addWarning($warnings, 'Cross-reference index unavailable; using bounded full-file recovery.');
        $this->context->metrics['recovery_path'] = true;
        $pdfVersion = $this->extractPdfVersion($content);
        $objects = $this->session->reader->collectIndirectObjects($content);
        $this->session->budget->assertObjectBudget(count($objects));
        if ($this->context !== null) {
            $this->context->metrics['objects_indexed'] = count($objects);
            $this->context->metrics['objects_loaded'] = count($objects);
            $this->context->metrics['object_bytes_read'] = strlen($content);
        }

        if ($objects === []) {
            throw PdfParseException::noObjectsFound();
        }

        $encrypted = $this->initializeDocument($this->context->trailerEntries, $objects, $warnings);
        if ($encrypted) {
            $this->recordParseMetadata($pdfVersion, true, $warnings, 0);
            return new Document([], $pdfVersion, true, $warnings);
        }
        $this->session->reader->expandObjectStreams($objects, $warnings);

        $resolutionLimit = $toPage ?? ($fromPage > PHP_INT_MAX - $this->options->maxPages ? PHP_INT_MAX : $fromPage + $this->options->maxPages);
        $pageObjectIds = $this->session->pages->resolvePageObjectIds($objects, $resolutionLimit);
        if ($pageObjectIds === []) {
            throw PdfParseException::noPagesFound();
        }

        $pageObjectIds = array_slice($pageObjectIds, $fromPage - 1, $toPage !== null ? $toPage - $fromPage + 1 : null);
        if (count($pageObjectIds) > $this->options->maxPages) {
            throw PdfParseException::resourceLimitExceeded('Parsed page count exceeds maxPages.');
        }

        $pages = [];
        foreach ($pageObjectIds as $index => $pageObjectId) {
            $this->session->budget->guardDeadline();
            $text = $this->session->content->extractPageText($pageObjectId, $objects, $warnings);
            $pages[] = new Page($fromPage + $index, $pageObjectId, $this->session->content->normalizeText($text));
        }

        $this->recordParseMetadata($pdfVersion, $encrypted, $warnings, count($pages));

        return new Document($pages, $pdfVersion, $encrypted, $warnings, $this->context->documentInfo);
    }

    /** Set up encryption before loading compressed objects, resources, or Info. */
    private function initializeDocument(array $trailer, array &$objects, array &$warnings): bool
    {
        $encrypt = $trailer['Encrypt'] ?? 'null';
        if ($encrypt !== 'null') {
            $dictionary = $encrypt;
            if (preg_match('/^(\d+)\s+(\d+)\s+R$/', $encrypt, $ref) === 1) {
                $id = (int) $ref[1];
                $this->context->encryptionObjectId = $id;
                $dictionary = $this->session->reader->ensureObject($id, $objects)
                    && $objects[$id]->generation === (int) $ref[2] ? $objects[$id]->body : '';
            }
            $ids = $trailer['ID'] ?? '[]';
            $firstId = $this->session->syntax->parsePdfArrayItems(substr($ids, 1, -1))[0] ?? '';
            $handler = new Security\StandardSecurityHandler($this->session);
            if (!$handler->authenticate($dictionary, $this->session->syntax->stringBytes($firstId) ?? '', $this->context->password)) {
                $this->session->budget->addWarning($warnings, 'Encrypted PDF detected. Extraction quality may be limited.');
                $this->context->password = '';
                return true;
            }
            $this->context->security = $handler;
            $this->context->isDecrypted = true;
        }
        $this->context->password = '';
        foreach ($objects as $id => $object) {
            $objects[$id] = $this->session->reader->prepareObject($object, $warnings);
        }
        if ($this->context->objectLoader === null) {
            $this->session->reader->expandObjectStreams($objects, $warnings);
        }
        if (preg_match('/^(\d+)\s+(\d+)\s+R$/', $trailer['Info'] ?? '', $ref) === 1) {
            $id = (int) $ref[1];
            if ($this->session->reader->ensureObject($id, $objects) && $objects[$id]->generation === (int) $ref[2]) {
                $this->context->documentInfo = Metadata\DocumentInfo::fromDictionary($objects[$id]->body, $this->session);
            }
        }
        return false;
    }

    /** @param string[] $warnings */
    private function recordParseMetadata(string $pdfVersion, bool $encrypted, array $warnings, int $pageCount): void
    {
        $this->context->metadata = [
            'pdf_version' => $pdfVersion,
            'is_encrypted' => $encrypted,
            'is_decrypted' => $this->context->isDecrypted,
            'details' => $this->context->documentInfo->getDetails(),
            'warnings' => $warnings,
            'page_count' => $pageCount,
        ];
    }

    private function validatePageRange(int $fromPage, ?int $toPage): void
    {
        if ($fromPage < 1) {
            throw PdfParseException::invalidPageRange('fromPage must be 1 or greater.');
        }
        if ($toPage !== null && $toPage < $fromPage) {
            throw PdfParseException::invalidPageRange('toPage must be greater than or equal to fromPage.');
        }
        if ($toPage !== null && ($toPage - $fromPage + 1) > $this->options->maxPages) {
            throw PdfParseException::resourceLimitExceeded('Requested page range exceeds maxPages.');
        }

    }

    private function extractPdfVersion(string $content): string
    {
        if (preg_match('/^%PDF-([0-9.]+)/', $content, $matches) === 1) {
            return $matches[1];
        }

        return 'unknown';
    }
}
