<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'HuraiPdf\\')) {
        require dirname(__DIR__) . '/src/' . str_replace('\\', '/', $class) . '.php';
    }
});

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) { return false; }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

function same(mixed $expected, mixed $actual, string $label): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($label . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function limited(callable $operation, string $label): void
{
    try { $operation(); }
    catch (HuraiPdf\Exception\PdfParseException $exception) {
        same(HuraiPdf\Exception\PdfParseException::RESOURCE_LIMIT_EXCEEDED, $exception->getCode(), $label);
        return;
    }
    throw new RuntimeException($label . ': resource limit was not enforced');
}

function pdfStream(string $content, string $entries = ''): string
{
    return '<< /Length ' . strlen($content) . ' ' . $entries . ">>\nstream\n" . $content . "\nendstream";
}

/** @param array<int, string> $objects */
function pdf(array $objects, string $trailer = '', bool $xref = true): string
{
    $out = "%PDF-1.7\n";
    $offsets = [];
    foreach ($objects as $id => $body) {
        $offsets[$id] = strlen($out);
        $out .= "$id 0 obj\n$body\nendobj\n";
    }
    $start = strlen($out);
    $size = max(array_keys($objects)) + 1;
    if ($xref) {
        $out .= "xref\n0 $size\n0000000000 65535 f \n";
        for ($id = 1; $id < $size; $id++) {
            $out .= isset($offsets[$id]) ? sprintf("%010d 00000 n \n", $offsets[$id]) : "0000000000 00000 f \n";
        }
    }
    return $out . "trailer\n<< /Size $size /Root 1 0 R $trailer >>\nstartxref\n" . ($xref ? $start : 0) . "\n%%EOF\n";
}

function pageObjects(string $content = 'BT /F1 12 Tf (Hello) Tj ET'): array
{
    return [
        1 => '<< /Type /Catalog /Pages 2 0 R >>',
        2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 /Resources << /Font << /F1 5 0 R >> >> >>',
        3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R >>',
        4 => pdfStream($content),
        5 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
    ];
}

function session(?HuraiPdf\ParserOptions $options = null): HuraiPdf\Internal\ParseSession
{
    return new HuraiPdf\Internal\ParseSession($options ?? new HuraiPdf\ParserOptions(), new HuraiPdf\ParseContext());
}
