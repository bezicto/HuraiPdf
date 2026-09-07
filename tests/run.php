<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use HuraiPdf\Document;
use HuraiPdf\Page;
use HuraiPdf\Parser;
use HuraiPdf\ParserOptions;
use HuraiPdf\Metadata\DocumentInfo;

$tests = [];
$tests['layout and literal tokenization'] = static function (): void {
    $content = 'BT /F1 12 Tf (Invoice) Tj 20 0 Td (  No\040123) Tj 0 -14 Td (Row 1) Tj '
        . 'T* (Row 2) Tj (Row 3) \' 0 0 (Row 4) " 1 0 0 1 0 0 Tm [(Total) -300 (10)] TJ ET';
    same("Invoice No 123\nRow 1\nRow 2\nRow 3\nRow 4\nTotal 10", (new Parser())->parseContent(pdf(pageObjects($content)))->getText(), 'operator layout');
    same("A B\nC\n\nD", session()->content->normalizeText("\n A\t B  \r\nC\r\n\r\n\r\nD\n"), 'line normalization');
    same("A\n\nB", (new Parser())->parseContent(pdf(pageObjects('BT (A) Tj T* T* T* (B) Tj ET')))->getText(), 'paragraph advances');
    same("One\n\nTwo", (new Document([new Page(1, 1, 'One'), new Page(2, 2, 'Two')], '1.7', false, []))->getText(), 'page separator');
    same('One', (new Document([new Page(1, 1, 'One'), new Page(2, 2, 'Two')], '1.7', false, []))->getText(1), 'page limit');
    $filter = new HuraiPdf\Filter\StopWordFilter();
    same($filter->filter('Invoice and total'), $filter->filter("Invoice\nand\ntotal"), 'stop words');
};
$tests['Info, recovery and dates'] = static function (): void {
    $objects = pageObjects();
    $objects[6] = '<< /Title (Invoice \\(draft\\)) /Author <FEFF0041007500740068006F00720020D83DDE00> '
        . '/Subject (\200 \240 \351) /Keywords (a\053b) /Creator (App) /Producer (PHP) '
        . "/CreationDate (D:20260906120000Z) /ModDate (D:20260906140000+02'00') >>";
    foreach ([true, false] as $xref) {
        $parser = new Parser();
        $doc = $parser->parseContent(pdf($objects, '/Info 6 0 R', $xref));
        same('Invoice (draft)', $doc->getTitle(), 'literal metadata');
        same('Author 😀', $doc->getAuthor(), 'UTF-16 metadata');
        same('• € é', $doc->getSubject(), 'PDFDoc metadata');
        same('a+b', $doc->getKeywords(), 'octal escape');
        same('App', $doc->getCreator(), 'creator');
        same('PHP', $doc->getProducer(), 'producer');
        same('2026-09-06T12:00:00+00:00', $doc->getCreationDate(), 'UTC date');
        same('2026-09-06T14:00:00+02:00', $doc->getModDate(), 'offset date');
        same($doc->getDetails(), $parser->getLastMetadata()['details'], 'last metadata');
    }
    foreach (['D:20260230', 'nonsense', "D:20260906120000+25'00'"] as $date) {
        same($date, DocumentInfo::formatDate($date), 'malformed date');
    }
    same('2026-01-01T00:00:00', DocumentInfo::formatDate('D:2026'), 'partial date');
    same(null, (new Parser())->parseContent(pdf(pageObjects()))->getTitle(), 'absent Info');
};
$tests['stream filters and predictors'] = static function (): void {
    $s = session();
    $warnings = [];
    $plain = 'Hello world';
    foreach ([['FlateDecode', gzcompress($plain)], ['ASCIIHexDecode', bin2hex($plain) . '>'], ['ASCII85Decode', '87cURD]j7BEbo7~>'], ['RunLengthDecode', chr(10) . $plain . chr(128)]] as [$filter, $encoded]) {
        same($plain, $s->decoder->decodeStream("<< /Filter /$filter >>", $encoded, $warnings, 0), $filter);
    }
    same('abc', $s->predictor->applyPredictor("a\x01\x01", ['Predictor' => 2, 'Columns' => 3]), 'TIFF predictor');
    foreach ([0 => "abc", 1 => "a\x01\x01", 2 => 'abc', 3 => "a\x32\x32", 4 => "a\x01\x01"] as $type => $encoded) {
        same('abc', $s->predictor->applyPredictor(chr($type) . $encoded, ['Predictor' => 15, 'Columns' => 3]), "PNG $type");
    }
    same([], $warnings, 'filter warnings');
};
$tests['CMaps and limits'] = static function (): void {
    $map = session()->cmap->parseToUnicodeCMap('2 beginbfchar <0001> <0041> <0002> <D83DDE00> endbfchar 1 beginbfrange <03> <05> <0061> endbfrange');
    same(['0001' => 'A', '0002' => '😀', '03' => 'a', '04' => 'b', '05' => 'c'], $map['map'], 'CMap ranges');
    limited(static fn() => session(new ParserOptions(maxCMapEntries: 1))->cmap->parseToUnicodeCMap('1 beginbfrange <00> <FF> <0041> endbfrange'), 'CMap expansion');
    limited(static fn() => (new Parser(new ParserOptions(maxInputBytes: 10)))->parseContent(pdf(pageObjects())), 'input budget');
    limited(static fn() => (new Parser(new ParserOptions(maxObjects: 2)))->parseContent(pdf(pageObjects())), 'object budget');
    limited(static fn() => (new Parser(new ParserOptions(maxExtractedTextBytes: 3)))->parseContent(pdf(pageObjects())), 'text budget');
    limited(static fn() => (new Parser(new ParserOptions(maxContentTokens: 2)))->parseContent(pdf(pageObjects())), 'token budget');
    limited(static fn() => (new Parser(new ParserOptions(maxContentOperators: 1)))->parseContent(pdf(pageObjects())), 'operator budget');
    limited(static fn() => (new Parser(new ParserOptions(deadlineSeconds: 0.000000001)))->parseContent(pdf(pageObjects())), 'deadline');
    $bomb = pageObjects();
    $bomb[4] = pdfStream(gzcompress(str_repeat('x', 10000)), '/Filter /FlateDecode');
    limited(static fn() => (new Parser(new ParserOptions(maxStreamBytes: 1000)))->parseContent(pdf($bomb)), 'stream budget');
    limited(static fn() => (new Parser(new ParserOptions(maxDecodedBytesTotal: 1000)))->parseContent(pdf($bomb)), 'decoded budget');
};
$tests['streaming lifecycle'] = static function (): void {
    $path = tempnam(sys_get_temp_dir(), 'hurai-test-');
    try {
        file_put_contents($path, pdf(pageObjects()));
        $parser = new Parser(new ParserOptions(streamingThreshold: 0));
        $generator = $parser->parseFilePages($path);
        same('Hello', $generator->current()->getText(), 'first page');
        try { $parser->parseContent(pdf(pageObjects())); throw new RuntimeException('Missing active-operation guard'); }
        catch (LogicException) {}
        unset($generator);
        same('Hello', $parser->parseFile($path)->getText(), 'reuse after close');
        $texts = [];
        $result = $parser->extractFile($path, static function (Page $page) use (&$texts): void { $texts[] = $page->getText(); });
        same(['Hello'], $texts, 'callback');
        same(1, $result['metadata']['page_count'], 'callback metadata');
        same(true, $result['metrics']['streaming_path'], 'streaming path');
        same([], $parser->parseContent(pdf(pageObjects()))->getDetails(), 'state reset');
    } finally { unlink($path); }
};
$tests['inline images and nested forms'] = static function (): void {
    $objects = pageObjects('BT /F1 12 Tf (Before) Tj ET BI /W 8 /H 1 /BPC 8 /CS /G ID abEIabcd EI /Fm Do BT (After) Tj ET');
    $objects[3] = '<< /Type /Page /Parent 2 0 R /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> /XObject << /Fm 6 0 R /Im 7 0 R >> >> >>';
    $objects[6] = pdfStream('BT /F1 12 Tf (Form) Tj ET', '/Type /XObject /Subtype /Form');
    $objects[7] = pdfStream(str_repeat('image', 10000), '/Type /XObject /Subtype /Image');
    $parser = new Parser();
    same("Before\nForm\nAfter", $parser->parseContent(pdf($objects))->getText(), 'inline and Form text');
    same(true, $parser->getLastMetrics()['object_bytes_read'] < 10000, 'image payload skipped');
};

$tests['independent encrypted fixtures, R2 through R6'] = static function (): void {
    foreach (range(2, 6) as $revision) {
        foreach (['empty' => '', 'password' => 'secret'] as $suffix => $password) {
            $path = __DIR__ . "/fixtures/r$revision-$suffix.pdf";
            $bytes = file_get_contents($path);
            foreach (['buffer', 'stream', 'recovery', 'owner'] as $mode) {
                $parser = new Parser(new ParserOptions(streamingThreshold: 0));
                $doc = match ($mode) {
                    'stream' => $parser->parseFile($path, password: $password),
                    'recovery' => $parser->parseContent(preg_replace('/startxref\s+\d+/', "startxref\n0", $bytes), password: $password),
                    'owner' => $parser->parseContent($bytes, password: 'owner-secret'),
                    default => $parser->parseContent($bytes, password: $password),
                };
                same("Page 1\nInvoice total 123\n\nPage 2\nInvoice total 123", $doc->getText(), "R$revision $suffix $mode text");
                same('Encrypted invoice', $doc->getTitle(), "R$revision $mode title");
                same('Author 😀', $doc->getAuthor(), "R$revision $mode author");
                same(false, $doc->isEncrypted(), "R$revision $mode unlocked");
                same(true, $parser->getLastMetadata()['is_decrypted'], "R$revision $mode metadata");
            }
            $parser = new Parser();
            $doc = $parser->parseContent($bytes, password: 'wrong');
            same(true, $doc->isEncrypted(), 'wrong password encrypted');
            same('', $doc->getText(), 'wrong password no garbled text');
            same(false, $parser->getLastMetadata()['is_decrypted'], 'wrong password state');
            same(true, count($doc->getWarnings()) > 0, 'wrong password warning');
            same(false, $parser->parseContent(pdf(pageObjects()))->isEncrypted(), 'reuse after encrypted input');
            same(false, $parser->getLastMetadata()['is_decrypted'], 'decryption state reset');
        }
        $parser = new Parser();
        $pages = iterator_to_array($parser->parseFilePages(__DIR__ . "/fixtures/r$revision-empty.pdf"));
        same(2, count($pages), 'encrypted generator');
        same('Encrypted invoice', $parser->getLastMetadata()['details']['Title'], 'generator Info');
    }
};

$tests['encrypted xref and object streams'] = static function (): void {
    foreach ([4, 6] as $revision) {
        $path = __DIR__ . "/fixtures/r$revision-object-stream.pdf";
        $parser = new Parser(new ParserOptions(streamingThreshold: 0));
        $doc = $parser->parseFile($path);
        same("Page 1\nInvoice total 123\n\nPage 2\nInvoice total 123", $doc->getText(), 'encrypted ObjStm text');
        same('Author 😀', $doc->getAuthor(), 'compressed Info is decrypted once');
        same(false, $parser->getLastMetrics()['recovery_path'], 'xref stream used');
        same(true, $parser->getLastMetadata()['is_decrypted'], 'xref stream encryption state');
        $doc = $parser->parseContent(preg_replace('/startxref\s+\d+/', "startxref\n0", file_get_contents($path)));
        same(2, $doc->getPageCount(), 'xref stream recovery');
        same('Author 😀', $doc->getAuthor(), 'compressed Info recovery');
    }
};

$tests['crypt filters and EncryptMetadata false'] = static function (): void {
    foreach (['r4-rc4', 'r4-metadata-clear', 'r6-metadata-clear', 'r4-identity-stream'] as $name) {
        $parser = new Parser();
        $doc = $parser->parseFile(__DIR__ . "/fixtures/$name.pdf");
        same(false, $doc->isEncrypted(), $name . ' authentication');
        same('Encrypted invoice', $doc->getTitle(), $name . ' Info strings remain encrypted');
        same("Page 1\nInvoice total 123\n\nPage 2\nInvoice total 123", $doc->getText(), $name . ' text');
    }
};

$tests['optional stopword line breaks'] = static function (): void {
    $filter = new HuraiPdf\Filter\StopWordFilter();
    $text = "Invoice 2026\r\nBatch42Code\r\n\r\nRoom7";
    same('invoice 2026 batch42code room7', $filter->filter($text), 'default stays flat');
    same('invoice 2026 batch42code room7', $filter->filter($text, preserveLineBreaks: false), 'explicit flat');
    same("invoice 2026\nbatch42code\n\nroom7", $filter->filter($text, preserveLineBreaks: true), 'retain lines and paragraph');
    same('invoice batch code room', $filter->filter($text, excludeNumbers: true), 'digits removed and flat');
    same("invoice\nbatch code\n\nroom", $filter->filter($text, excludeNumbers: true, preserveLineBreaks: true), 'both options');
    same("invoice\n\nroom", $filter->filter("\nInvoice\rand\ror\r\rRoom\n\n", preserveLineBreaks: true), 'empty lines normalized');
    same("invoice 2026\nroom", $filter->filter("Invoice\t2026\nRoom", preserveLineBreaks: true), 'horizontal whitespace');
    same('', $filter->filter("and\n123\n\nor", true, true), 'all tokens removed');
    same('', $filter->filter('', preserveLineBreaks: true), 'empty input');
    $chunks = static function (): Generator {
        yield 'first' => "Invoice 2026\nBatch42Code";
        yield 'empty' => "and\n123";
        yield 'last' => "Room7\n\nInvoice";
    };
    same(['first' => "invoice\nbatch code", 'last' => "room\n\ninvoice"], iterator_to_array($filter->filterChunks($chunks(), true, true)), 'chunk keys and omitted empty chunks');
    same(['first' => 'invoice batch code', 'last' => 'room invoice'], iterator_to_array($filter->filterChunks($chunks(), true)), 'default chunks stay flat');
};

require __DIR__ . '/structure.php';

$failed = 0;
foreach ($tests as $name => $test) {
    try { $test(); echo "PASS $name\n"; }
    catch (Throwable $error) { $failed++; fwrite(STDERR, "FAIL $name: $error\n"); }
}
if ($failed > 0) { exit(1); }
echo count($tests) . " test groups passed.\n";
