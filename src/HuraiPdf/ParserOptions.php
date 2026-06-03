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
    ) {
    }
}
