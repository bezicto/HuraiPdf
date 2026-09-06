<?php

declare(strict_types=1);

namespace HuraiPdf\Metadata;

use HuraiPdf\Internal\ParseSession;

/** Standard document information, decoded into UTF-8 strings. */
final class DocumentInfo
{
    /** @param array<string, string> $details */
    public function __construct(private readonly array $details = []) {}

    /** @return array<string, string> */
    public function getDetails(): array { return $this->details; }

    /** @internal */
    public static function fromDictionary(string $dictionary, ParseSession $session): self
    {
        $entries = $session->syntax->dictionaryEntries($dictionary);
        $details = [];
        foreach (['Title', 'Author', 'Subject', 'Keywords', 'Creator', 'Producer', 'CreationDate', 'ModDate'] as $key) {
            $bytes = $session->syntax->stringBytes($entries[$key] ?? '');
            if ($bytes === null) { continue; }
            $value = $session->encoding->decodeDocumentString($bytes);
            if ($key === 'CreationDate' || $key === 'ModDate') { $value = self::formatDate($value); }
            $session->budget->accountTextBytes(strlen($value));
            $details[$key] = $value;
        }
        return new self($details);
    }

    public static function formatDate(string $value): string
    {
        if (preg_match("/^D:(\d{4})(?:(\d{2})(?:(\d{2})(?:(\d{2})(?:(\d{2})(\d{2})?)?)?)?)?(Z|[+-]\d{2}(?:'?\d{2}'?)?)?$/D", $value, $m) !== 1) {
            return $value;
        }
        $year = (int) $m[1];
        $month = (int) (($m[2] ?? '') !== '' ? $m[2] : 1);
        $day = (int) (($m[3] ?? '') !== '' ? $m[3] : 1);
        $hour = (int) ($m[4] ?? 0);
        $minute = (int) ($m[5] ?? 0);
        $second = (int) ($m[6] ?? 0);
        if (!checkdate($month, $day, $year) || $hour > 23 || $minute > 59 || $second > 59) { return $value; }
        $zone = $m[7] ?? '';
        if ($zone === 'Z') { $zone = '+00:00'; }
        elseif ($zone !== '') {
            $digits = str_replace("'", '', substr($zone, 1));
            $hours = (int) substr($digits, 0, 2);
            $minutes = (int) substr($digits, 2, 2);
            if ($hours > 23 || $minutes > 59) { return $value; }
            $zone = sprintf('%s%02d:%02d', $zone[0], $hours, $minutes);
        }
        // An absent timezone stays unspecified instead of inventing a UTC offset.
        return sprintf('%04d-%02d-%02dT%02d:%02d:%02d%s', $year, $month, $day, $hour, $minute, $second, $zone);
    }
}
