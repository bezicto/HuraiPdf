<?php

declare(strict_types=1);

namespace HuraiPdf\Exception;

use RuntimeException;

final class PdfParseException extends RuntimeException
{
    public const int FILE_NOT_READABLE  = 1;
    public const int FILE_READ_FAILURE  = 2;
    public const int INVALID_HEADER     = 3;
    public const int NO_OBJECTS_FOUND   = 4;
    public const int NO_PAGES_FOUND     = 5;
    public const int INVALID_PAGE_RANGE = 6;
    public const int RESOURCE_LIMIT_EXCEEDED = 7;

    public static function fileNotReadable(string $filePath): self
    {
        return new self('PDF file is not readable: ' . $filePath, self::FILE_NOT_READABLE);
    }

    public static function fileReadFailure(): self
    {
        return new self('Failed to read PDF file.', self::FILE_READ_FAILURE);
    }

    public static function invalidHeader(): self
    {
        return new self('Invalid PDF header.', self::INVALID_HEADER);
    }

    public static function noObjectsFound(): self
    {
        return new self('No PDF objects found.', self::NO_OBJECTS_FOUND);
    }

    public static function noPagesFound(): self
    {
        return new self('No page objects found.', self::NO_PAGES_FOUND);
    }

    public static function invalidPageRange(string $message): self
    {
        return new self($message, self::INVALID_PAGE_RANGE);
    }

    public static function resourceLimitExceeded(string $message): self
    {
        return new self($message, self::RESOURCE_LIMIT_EXCEEDED);
    }
}
