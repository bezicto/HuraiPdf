<?php

declare(strict_types=1);

namespace HuraiPdf\Filter;

final class StopWordFilter
{
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
    public function filter(string $text): string
    {
        if ($text === '') {
            return '';
        }

        // Split on anything that is not a plain ASCII letter or digit.
        // This strips all special characters, punctuation, and symbols from the output.
        $tokens = preg_split('/[^a-zA-Z0-9]+/', strtolower($text), -1, PREG_SPLIT_NO_EMPTY);
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
