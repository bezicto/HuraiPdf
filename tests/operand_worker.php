<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

// Run separately with memory_limit=64M: a fatal error must fail the parent test.
$content = match ($argv[1] ?? 'arrays') {
    'arrays' => str_repeat('[' . str_repeat('0 ', 20000) . '] ', 70),
    'strings' => '(' . str_repeat('x', 9 * 1024 * 1024) . ')',
    'hex' => '<' . str_repeat('41', 5 * 1024 * 1024) . '>',
};
$objects = pageObjects();
$objects[4] = pdfStream(gzcompress($content), '/Filter /FlateDecode');
$input = pdf($objects);
unset($objects, $content);
$parser = new HuraiPdf\Parser();
try {
    $parser->parseContent($input);
    throw new RuntimeException('Oversized operands were accepted');
} catch (HuraiPdf\Exception\PdfParseException $error) {
    same(HuraiPdf\Exception\PdfParseException::RESOURCE_LIMIT_EXCEEDED, $error->getCode(), 'resource exception');
    same('Content operands exceed maxOperandBytes.', $error->getMessage(), 'allocation limit');
}
same('Hello', $parser->parseContent(pdf(pageObjects()))->getText(), 'reuse after allocation rejection');
echo "PASS bounded operands\n";
