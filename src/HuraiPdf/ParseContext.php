<?php

declare(strict_types=1);

namespace HuraiPdf;

/** @internal Mutable state that is deliberately scoped to one parse operation. */
final class ParseContext
{
    /** @var array<int, array{map:array<string,string>,max_code_bytes:int,encoding_name:string,differences:array<int,string>,is_multibyte:bool,has_tounicode:bool}> */
    public array $fontMapCache = [];

    /** @var array<int, string> */
    public array $decodedStreamCache = [];

    /** @var array<int, string[]> */
    public array $resourceBodiesCache = [];

    /** @var array<int, true> */
    public array $expandedObjectStreams = [];

    /** @var array<int, string> */
    public array $formTextCache = [];

    /** @var array<string, array<string, array<string, mixed>>> */
    public array $resourceFontMapsCache = [];

    /** @var array<string, array<string, int>> */
    public array $resourceXObjectMapsCache = [];

    /** @var array{pdf_version:string,is_encrypted:bool,warnings:string[],page_count:int} */
    public array $metadata = [
        'pdf_version' => 'unknown',
        'is_encrypted' => false,
        'warnings' => [],
        'page_count' => 0,
    ];

    /** @var array<string, int|float|bool|string> */
    public array $metrics = [
        'streaming_path' => false,
        'xref_sections' => 0,
        'objects_indexed' => 0,
        'objects_loaded' => 0,
        'object_bytes_read' => 0,
        'decoded_streams' => 0,
        'decoded_bytes' => 0,
        'content_operators' => 0,
        'duration_ms' => 0.0,
        'peak_memory_bytes' => 0,
    ];

    public readonly int $startedAtNanoseconds;

    public function __construct()
    {
        memory_reset_peak_usage();
        $this->startedAtNanoseconds = hrtime(true);
    }

    public function finish(): void
    {
        $this->metrics['duration_ms'] = (hrtime(true) - $this->startedAtNanoseconds) / 1_000_000;
        $this->metrics['peak_memory_bytes'] = memory_get_peak_usage(true);
    }
}
