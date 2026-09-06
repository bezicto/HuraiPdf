<?php

declare(strict_types=1);

namespace HuraiPdf;

final class ParserOptions
{
    public function __construct(
        /** Files larger than this threshold (bytes) use the streaming code path. */
        public readonly int $streamingThreshold = 5 * 1024 * 1024,
        /** Maximum ToUnicode CMap size in bytes before truncation. */
        public readonly int $maxCMapSize = 1_048_576,
        /** Bytes read per chunk when fetching individual objects in streaming mode. */
        public readonly int $objectChunkSize = 262_144,
        /** Maximum number of indexed objects accepted in one document. */
        public readonly int $maxObjects = 500_000,
        /** Maximum pages processed in one operation. */
        public readonly int $maxPages = 100_000,
        /** Maximum compressed or decoded bytes accepted for one stream. */
        public readonly int $maxStreamBytes = 100 * 1024 * 1024,
        /** Maximum cumulative decoded bytes across one parse. */
        public readonly int $maxDecodedBytesTotal = 512 * 1024 * 1024,
        /** Maximum content-stream operators processed in one parse. */
        public readonly int $maxContentOperators = 10_000_000,
        /** Maximum recursive page-tree, reference, or Form depth. */
        public readonly int $maxRecursionDepth = 64,
        /** Maximum warnings retained in memory. */
        public readonly int $maxWarnings = 1_000,
        /** Optional wall-clock deadline in seconds. */
        public readonly ?float $deadlineSeconds = null,
        /** Maximum input bytes accepted, including full-file recovery. */
        public readonly int $maxInputBytes = 256 * 1024 * 1024,
        /** Maximum bytes in an indirect object, including its dictionary. */
        public readonly int $maxObjectBytes = 101 * 1024 * 1024,
        /** Maximum tokens read across content streams. */
        public readonly int $maxContentTokens = 2_000_000,
        /** Maximum elements retained in one content array. */
        public readonly int $maxArrayElements = 20_000,
        /** Maximum expanded mappings across all fonts in one operation. */
        public readonly int $maxCMapEntries = 100_000,
        /** Maximum generated text bytes, including intermediate Form expansion. */
        public readonly int $maxExtractedTextBytes = 32 * 1024 * 1024,
        /** Approximate retained cache allocation budget between pages. */
        public readonly int $maxCacheBytes = 8 * 1024 * 1024,
    ) {
        if ($this->streamingThreshold < 0) {
            throw new \InvalidArgumentException('streamingThreshold must be zero or greater.');
        }
        if ($this->maxCMapSize < 1) {
            throw new \InvalidArgumentException('maxCMapSize must be greater than zero.');
        }
        if ($this->objectChunkSize < 1) {
            throw new \InvalidArgumentException('objectChunkSize must be greater than zero.');
        }
        foreach (
            [
                'maxObjects' => $this->maxObjects,
                'maxPages' => $this->maxPages,
                'maxStreamBytes' => $this->maxStreamBytes,
                'maxDecodedBytesTotal' => $this->maxDecodedBytesTotal,
                'maxContentOperators' => $this->maxContentOperators,
                'maxRecursionDepth' => $this->maxRecursionDepth,
                'maxWarnings' => $this->maxWarnings,
                'maxInputBytes' => $this->maxInputBytes,
                'maxObjectBytes' => $this->maxObjectBytes,
                'maxContentTokens' => $this->maxContentTokens,
                'maxArrayElements' => $this->maxArrayElements,
                'maxCMapEntries' => $this->maxCMapEntries,
                'maxExtractedTextBytes' => $this->maxExtractedTextBytes,
                'maxCacheBytes' => $this->maxCacheBytes,
            ] as $name => $value
        ) {
            if ($value < 1) {
                throw new \InvalidArgumentException($name . ' must be greater than zero.');
            }
        }
        if ($this->deadlineSeconds !== null && (!is_finite($this->deadlineSeconds) || $this->deadlineSeconds <= 0)) {
            throw new \InvalidArgumentException('deadlineSeconds must be greater than zero when provided.');
        }
    }
}
