<?php

declare(strict_types=1);

namespace HuraiPdf\Internal;

use HuraiPdf\ParserOptions;
use HuraiPdf\ParseContext;

/** @internal Explicit service graph, created afresh for every parse operation. */
final class ParseSession
{
    public readonly \HuraiPdf\Reader\XRefReader $reader;
    public readonly \HuraiPdf\Reader\PageTree $pages;
    public readonly \HuraiPdf\Content\ResourceResolver $resources;
    public readonly \HuraiPdf\Filter\StreamDecoder $decoder;
    public readonly \HuraiPdf\Filter\Predictor $predictor;
    public readonly \HuraiPdf\Font\CMapParser $cmap;
    public readonly \HuraiPdf\Font\FontEncoding $encoding;
    public readonly \HuraiPdf\Content\ContentStreamInterpreter $content;
    public readonly \HuraiPdf\Internal\PdfSyntax $syntax;
    public readonly \HuraiPdf\Internal\ResourceBudget $budget;

    public function __construct(
        public readonly ParserOptions $options,
        public readonly ParseContext $context,
    ) {
        $this->reader = new \HuraiPdf\Reader\XRefReader($this);
        $this->pages = new \HuraiPdf\Reader\PageTree($this);
        $this->resources = new \HuraiPdf\Content\ResourceResolver($this);
        $this->decoder = new \HuraiPdf\Filter\StreamDecoder($this);
        $this->predictor = new \HuraiPdf\Filter\Predictor($this);
        $this->cmap = new \HuraiPdf\Font\CMapParser($this);
        $this->encoding = new \HuraiPdf\Font\FontEncoding($this);
        $this->content = new \HuraiPdf\Content\ContentStreamInterpreter($this);
        $this->syntax = new \HuraiPdf\Internal\PdfSyntax($this);
        $this->budget = new \HuraiPdf\Internal\ResourceBudget($this);
    }
}
