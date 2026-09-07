<?php

declare(strict_types=1);

namespace HuraiPdf;

/** @internal Mutable state that is deliberately scoped to one parse operation. */
final class ParseContext
{
    /** @var (\Closure(int, bool): bool)|null */
    public ?\Closure $objectLoader = null;
    public ?Security\StandardSecurityHandler $security = null;
    public ?int $encryptionObjectId = null;
    /** @var array<int, int> */
    public array $objectGenerations = [];
    /** @var array<string, string> */
    public array $trailerEntries = [];
    public string $password = '';
    public bool $isDecrypted = false;
    public Metadata\DocumentInfo $documentInfo;
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

    /** @var array{pdf_version:string,is_encrypted:bool,is_decrypted:bool,details:array<string,string>,warnings:string[],page_count:int} */
    public array $metadata = [
        'pdf_version' => 'unknown',
        'is_encrypted' => false,
        'is_decrypted' => false,
        'details' => [],
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
        'decoded_work_bytes' => 0,
        'content_operators' => 0,
        'content_tokens' => 0,
        'cmap_entries' => 0,
        'generated_text_bytes' => 0,
        'cache_bytes' => 0,
        'cache_evictions' => 0,
        'first_page_ms' => 0.0,
        'recovery_path' => false,
        'duration_ms' => 0.0,
        'peak_memory_bytes' => 0,
    ];

    public readonly int $startedAtNanoseconds;

    public int $operandBytes = 0;
    public int $contentTokenDepth = 0;
    public int $arrayDepth = 0;
    public int $arrayElements = 0;
    public int $cmapAllocationBytes = 0;
    public int $intermediateDecodedBytes = 0;

    public function __construct()
    {
        $this->documentInfo = new Metadata\DocumentInfo();
        memory_reset_peak_usage();
        $this->startedAtNanoseconds = hrtime(true);
    }

    public function finish(): void
    {
        $this->password = '';
        $this->security = null;
        $this->metrics['duration_ms'] = (hrtime(true) - $this->startedAtNanoseconds) / 1_000_000;
        $this->metrics['peak_memory_bytes'] = memory_get_peak_usage(true);
        $this->objectLoader = null;
        // Release large allocations immediately, including when a generator is
        // closed early. The service graph may await PHP's cycle collector.
        foreach (['fontMapCache', 'decodedStreamCache', 'resourceBodiesCache',
            'expandedObjectStreams', 'formTextCache', 'resourceFontMapsCache',
            'resourceXObjectMapsCache', 'objectGenerations', 'trailerEntries'] as $cache) {
            $this->$cache = [];
        }
    }
}
