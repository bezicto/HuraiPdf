<?php

declare(strict_types=1);

namespace HuraiPdf\Internal;

use HuraiPdf\Exception\PdfParseException;

/** @internal */
final class ResourceBudget extends Subsystem
{
    public function assertInputBudget(int $bytes): void
    {
        if ($bytes > $this->options->maxInputBytes) {
            throw PdfParseException::resourceLimitExceeded('PDF input exceeds maxInputBytes.');
        }
        $this->guardDeadline();
    }

    public function releasePageObjects(array &$objects, int $pageId): void
    {
        unset($objects[$pageId], $this->context->resourceBodiesCache[$pageId]);
        $bytes = 0;
        foreach ($objects as $id => $object) {
            if (str_contains($object->body, 'stream')) {
                unset($objects[$id], $this->context->expandedObjectStreams[$id]);
                continue;
            }
            $bytes += strlen($object->body) + 256;
        }
        $bytes += $this->context->cmapAllocationBytes;
        foreach (['decodedStreamCache', 'formTextCache', 'resourceBodiesCache', 'resourceFontMapsCache', 'resourceXObjectMapsCache'] as $cache) {
            foreach ($this->context->$cache as $value) {
                $bytes += $this->estimateCacheBytes($value);
            }
        }
        if ($bytes > $this->options->maxCacheBytes) {
            $objects = [];
            foreach (['fontMapCache', 'decodedStreamCache', 'resourceBodiesCache', 'expandedObjectStreams', 'formTextCache', 'resourceFontMapsCache', 'resourceXObjectMapsCache'] as $cache) {
                $this->context->$cache = [];
            }
            $this->context->cmapAllocationBytes = 0;
            $this->context->metrics['cache_evictions']++;
            $bytes = 0;
        }
        $this->context->metrics['cache_bytes'] = $bytes;
    }

    public function cacheStringResult(string $cache, int $id, string $value): void
    {
        if (strlen($value) + 128 > $this->options->maxCacheBytes) { return; }
        $bytes = $this->context->cmapAllocationBytes + strlen($value) + 128;
        foreach (['decodedStreamCache', 'formTextCache'] as $name) {
            foreach ($this->context->$name as $key => $entry) {
                if ($name !== $cache || $key !== $id) { $bytes += strlen($entry) + 128; }
            }
        }
        if ($bytes > $this->options->maxCacheBytes) {
            $this->context->decodedStreamCache = [];
            $this->context->formTextCache = [];
            $this->context->metrics['cache_evictions']++;
        }
        if ($this->context->cmapAllocationBytes + strlen($value) + 128 <= $this->options->maxCacheBytes) {
            $this->context->{$cache}[$id] = $value;
        }
    }

    private function estimateCacheBytes(mixed $value): int
    {
        if (is_string($value)) { return strlen($value) + 64; }
        if (!is_array($value)) { return 32; }
        $bytes = 128;
        foreach ($value as $key => $child) {
            // Font maps are already charged once in cmapAllocationBytes.
            $bytes += 64 + (is_string($key) ? strlen($key) : 0);
            if ($key !== 'map') { $bytes += $this->estimateCacheBytes($child); }
        }
        return $bytes;
    }

    public function appendBoundedText(string &$out, string $text): void
    {
        if (strlen($text) > $this->options->maxExtractedTextBytes - strlen($out)) {
            throw PdfParseException::resourceLimitExceeded('Extracted text exceeds maxExtractedTextBytes.');
        }
        $out .= $text;
    }

    public function appendTextPart(array &$parts, string $text): void
    {
        $this->accountTextBytes(strlen($text));
        $parts[] = $text;
    }

    public function accountTextBytes(int $bytes): void
    {
        $total = $this->context->metrics['generated_text_bytes'] + $bytes;
        if ($total > $this->options->maxExtractedTextBytes) {
            throw PdfParseException::resourceLimitExceeded('Generated text exceeds maxExtractedTextBytes.');
        }
        $this->context->metrics['generated_text_bytes'] = $total;
    }

    public function accountCMapEntry(int $bytes): void
    {
        $this->guardDeadline();
        if (++$this->context->metrics['cmap_entries'] > $this->options->maxCMapEntries) {
            throw PdfParseException::resourceLimitExceeded('CMap expansion exceeds maxCMapEntries.');
        }
        $this->context->cmapAllocationBytes += $bytes + 128;
        if ($this->context->cmapAllocationBytes > $this->options->maxCacheBytes) {
            throw PdfParseException::resourceLimitExceeded('CMap allocation exceeds maxCacheBytes.');
        }
    }

    public function accountIntermediateBytes(int $bytes): void
    {
        $this->context->intermediateDecodedBytes += $bytes;
        $this->context->metrics['decoded_work_bytes'] = $this->context->intermediateDecodedBytes;
        if ($this->context->intermediateDecodedBytes > $this->options->maxDecodedBytesTotal) {
            throw PdfParseException::resourceLimitExceeded('Intermediate decoded data exceeds maxDecodedBytesTotal.');
        }
    }

    public function guardDeadline(): void
    {
        if ($this->options->deadlineSeconds === null) {
            return;
        }
        $elapsed = (hrtime(true) - $this->context->startedAtNanoseconds) / 1_000_000_000;
        if ($elapsed > $this->options->deadlineSeconds) {
            throw PdfParseException::resourceLimitExceeded('PDF parsing deadline exceeded.');
        }
    }

    public function assertObjectBudget(int $objectCount): void
    {
        if ($objectCount > $this->options->maxObjects) {
            throw PdfParseException::resourceLimitExceeded('PDF object count exceeds maxObjects.');
        }
    }

    public function accountDecodedBytes(int $bytes): void
    {
        if ($bytes > $this->options->maxStreamBytes) {
            throw PdfParseException::resourceLimitExceeded('Decoded PDF stream exceeds maxStreamBytes.');
        }
        $total = (int) $this->context->metrics['decoded_bytes'] + $bytes;
        if ($total > $this->options->maxDecodedBytesTotal) {
            throw PdfParseException::resourceLimitExceeded('Decoded PDF data exceeds maxDecodedBytesTotal.');
        }
        $this->context->metrics['decoded_streams']++;
        $this->context->metrics['decoded_bytes'] = $total;
    }

    /** @param string[] $warnings */
    public function addWarning(array &$warnings, string $warning): void
    {
        if (count($warnings) < $this->options->maxWarnings) {
            $warnings[] = $warning;
            return;
        }
        if (($warnings[$this->options->maxWarnings - 1] ?? '') !== 'Additional warnings truncated.') {
            $warnings[$this->options->maxWarnings - 1] = 'Additional warnings truncated.';
        }
    }
}
