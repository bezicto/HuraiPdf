<?php

declare(strict_types=1);

namespace HuraiPdf\Filter;

final class StopWordFilter
{
    private const TOKEN_SEPARATOR_PATTERN = '/[^a-zA-Z0-9]+/';
    private const LETTER_SEPARATOR_PATTERN = '/[^a-zA-Z]+/';

    /** @var array<string, true> */
    private static array $baseMap = [];
    private static bool $baseLoaded = false;

    /** @var array<string, true> */
    private array $map = [];

    public function __construct(string ...$extraFilePaths)
    {
        if (!self::$baseLoaded) {
            $tmp = [];
            $this->loadFileInto(__DIR__ . '/stopwords/en.txt', $tmp);
            $this->loadFileInto(__DIR__ . '/stopwords/ms.txt', $tmp);
            self::$baseMap = $tmp;
            self::$baseLoaded = true;
        }

        $this->map = self::$baseMap;

        foreach ($extraFilePaths as $filePath) {
            $this->loadFileInto($filePath, $this->map);
        }
    }

    /**
     * Remove stopwords from the given text and return a space-separated
     * string of remaining tokens, suitable for keyword indexing.
     */
    public function filter(string $text, bool $excludeNumbers = false): string
    {
        if ($text === '') {
            return '';
        }

        // Select the separator once so excluding digits adds no per-token scan.
        $separatorPattern = $excludeNumbers
            ? self::LETTER_SEPARATOR_PATTERN
            : self::TOKEN_SEPARATOR_PATTERN;
        $tokens = preg_split($separatorPattern, strtolower($text), -1, PREG_SPLIT_NO_EMPTY);
        if ($tokens === false) {
            return $text;
        }

        $filtered = [];
        foreach ($tokens as $token) {
            // Drop single characters and any token in the stopword map.
            if (strlen($token) > 1 && !isset($this->map[$token])) {
                $filtered[] = $token;
            }
        }

        return implode(' ', $filtered);
    }

    /**
     * Filter chunks independently so callers can write output incrementally.
     * Page boundaries are treated as token boundaries.
     *
     * @param iterable<int|string, string> $chunks
     * @return \Generator<int|string, string>
     */
    public function filterChunks(iterable $chunks, bool $excludeNumbers = false): \Generator
    {
        foreach ($chunks as $key => $chunk) {
            $filtered = $this->filter($chunk, $excludeNumbers);
            if ($filtered !== '') {
                yield $key => $filtered;
            }
        }
    }

    private function loadFileInto(string $filePath, array &$map): void
    {
        if (!is_file($filePath) || !is_readable($filePath)) {
            return;
        }

        $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $word = strtolower(trim($line));
            // Skip blank lines and comment lines starting with #
            if ($word === '' || $word[0] === '#') {
                continue;
            }
            $map[$word] = true;
        }
    }
}
