<?php

declare(strict_types=1);

namespace HuraiPdf\Reader;

/** @internal Seekable file or immutable string; the caller owns file handles. */
final class InputSource
{
    private int $position = 0;

    /** @param resource|string $source */
    private function __construct(private readonly mixed $source) {}

    public static function fromString(string $content): self { return new self($content); }

    /** @param resource $handle */
    public static function fromHandle($handle): self
    {
        if (!is_resource($handle) || get_resource_type($handle) !== 'stream') {
            throw new \InvalidArgumentException('Expected an open stream.');
        }
        return new self($handle);
    }

    public function size(): int
    {
        if (is_string($this->source)) { return strlen($this->source); }
        $stat = fstat($this->source);
        return $stat === false ? 0 : (int) $stat['size'];
    }

    public function seek(int $offset): int
    {
        if ($offset < 0) { return -1; }
        if (!is_string($this->source)) { return fseek($this->source, $offset); }
        $this->position = $offset;
        return 0;
    }

    public function read(int $length): string|false
    {
        if ($length <= 0) { return ''; }
        if (!is_string($this->source)) { return fread($this->source, $length); }
        $slice = substr($this->source, $this->position, $length);
        $this->position += strlen($slice);
        return $slice;
    }

    public function eof(): bool
    {
        return is_string($this->source) ? $this->position >= strlen($this->source) : feof($this->source);
    }
}
