<?php

declare(strict_types=1);

use HuraiPdf\Exception\PdfParseException;
use HuraiPdf\Filter\StopWordFilter;
use HuraiPdf\Parser;
use HuraiPdf\ParserOptions;

$baseDir = __DIR__;
spl_autoload_register(static function (string $class) use ($baseDir): void {
    if (str_starts_with($class, 'HuraiPdf\\')) {
        $path = $baseDir . '/src/HuraiPdf/' . str_replace('\\', '/', substr($class, 9)) . '.php';
        if (is_file($path)) { require $path; }
    }
});

header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
header('Referrer-Policy: no-referrer');

// A standalone usage example: upload a PDF, extract each page, and save the results.
// Start locally with: php -S 127.0.0.1:8080
$outputDir = __DIR__ . '/output';

const MAX_UPLOAD_BYTES = 60 * 1024 * 1024;
const MAX_PREVIEW_BYTES = 100 * 1024;
const MAX_OUTPUT_BYTES = 32 * 1024 * 1024;
ini_set('max_execution_time', '300');
$maxMemoryLimit = ini_get('max_memory_limit');
$maxMemoryBytes = is_string($maxMemoryLimit) ? huraiPdfIniSizeToBytes($maxMemoryLimit) : null;
ini_set('memory_limit', (string) min($maxMemoryBytes ?? 512 * 1024 * 1024, 512 * 1024 * 1024));

$errorMessage = '';
$result = null;
$excludeNumbers = false;
$preserveLineBreaks = false;
$createdFiles = [];
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    try {
        $excludeNumbers = ($_POST['exclude_numbers'] ?? '') === '1';
        $preserveLineBreaks = ($_POST['preserve_line_breaks'] ?? '') === '1';
        $uploadedFile = $_FILES['pdf_file'] ?? null;
        if (!is_array($uploadedFile) || ($uploadedFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException('Upload failed. Check the file size and server upload limits.');
        }
        $tmpPath = $uploadedFile['tmp_name'] ?? null;
        $originalName = $uploadedFile['name'] ?? null;
        if (!is_string($tmpPath) || !is_string($originalName) || !is_uploaded_file($tmpPath)) {
            throw new InvalidArgumentException('Invalid upload source.');
        }
        $fileSize = filesize($tmpPath);
        if ($fileSize === false || $fileSize < 1 || $fileSize > MAX_UPLOAD_BYTES) {
            throw new InvalidArgumentException('Upload a nonempty PDF no larger than 60 MB.');
        }
        if (strtolower(pathinfo($originalName, PATHINFO_EXTENSION)) !== 'pdf'
            || file_get_contents($tmpPath, false, null, 0, 5) !== '%PDF-') {
            throw new InvalidArgumentException('Only PDF files are allowed.');
        }
        $fromPage = parsePageField('from_page') ?? 1;
        $toPage = parsePageField('to_page');
        if ($toPage !== null && $toPage < $fromPage) {
            throw new InvalidArgumentException('To page must be greater than or equal to From page.');
        }
        if (!is_dir($outputDir) && !mkdir($outputDir, 0775, true) && !is_dir($outputDir)) {
            throw new RuntimeException('Unable to create the output directory.');
        }
        $name = bin2hex(random_bytes(16));
        $textPath = $outputDir . '/' . $name . '.txt';
        $metaPath = $outputDir . '/' . $name . '.json';
        $createdFiles = [$textPath . '.part', $metaPath . '.part', $textPath, $metaPath];
        $handle = fopen($textPath . '.part', 'xb');
        if ($handle === false) { throw new RuntimeException('Unable to create extraction output.'); }

        // Parser is the library entry point. ParserOptions lets you adjust its limits.
        $parser = new Parser(new ParserOptions(
            maxInputBytes: MAX_UPLOAD_BYTES,
            maxExtractedTextBytes: MAX_OUTPUT_BYTES,
            deadlineSeconds: 280.0,
        ));
        // Optional: remove English/Malay conjunctions and, when selected, digits.
        $filter = new StopWordFilter();
        $textLength = 0;
        $preview = '';
        // extractFile() calls this function for each page without retaining the whole document.
        // Use $page->getText() directly instead of filter() for unfiltered text.
        try {
            $extraction = $parser->extractFile($tmpPath, static function ($page) use ($filter, $handle, $excludeNumbers, $preserveLineBreaks, &$textLength, &$preview): void {
                $filtered = $filter->filter($page->getText(), $excludeNumbers, $preserveLineBreaks);
                if ($filtered === '') { return; }
                $separator = $preserveLineBreaks ? "\n\n" : ' ';
                $chunk = ($textLength > 0 ? $separator : '') . $filtered;
                if (strlen($chunk) > MAX_OUTPUT_BYTES - $textLength) {
                    throw PdfParseException::resourceLimitExceeded('Output exceeds the configured text limit.');
                }
                writeAll($handle, $chunk);
                $textLength += strlen($chunk);
                if (strlen($preview) < MAX_PREVIEW_BYTES) {
                    $preview .= substr($chunk, 0, MAX_PREVIEW_BYTES - strlen($preview));
                }
            }, $fromPage, $toPage);
        } finally { fclose($handle); }
        $metadata = $extraction['metadata'];
        $meta = $metadata + [
            'source_original_name' => $originalName,
            'extracted_at_utc' => gmdate(DATE_ATOM), 'engine' => 'HuraiPdf/Parser',
            'from_page' => $fromPage, 'to_page' => $toPage, 'exclude_numbers' => $excludeNumbers,
            'preserve_line_breaks' => $preserveLineBreaks,
            'text_length' => $textLength, 'performance' => $extraction['metrics'],
        ];
        writeAllFile($metaPath . '.part', json_encode($meta, JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR));
        if (!rename($textPath . '.part', $textPath)
            || !rename($metaPath . '.part', $metaPath)) {
            throw new RuntimeException('Unable to publish extraction output.');
        }
        $result = [
            'source_pdf' => $originalName,
            'page_range' => 'page ' . $fromPage . ($toPage === null ? ' to end' : ' to ' . $toPage),
            'text_file' => 'output/' . $name . '.txt',
            'meta_file' => 'output/' . $name . '.json',
            'exclude_numbers' => $excludeNumbers, 'warnings' => $metadata['warnings'],
            'preserve_line_breaks' => $preserveLineBreaks,
            'preview' => $preview, 'preview_truncated' => $textLength > strlen($preview),
        ];
    } catch (Throwable $exception) {
        foreach ($createdFiles as $path) { if (is_file($path)) { unlink($path); } }
        if ($exception instanceof InvalidArgumentException || $exception instanceof PdfParseException) {
            $errorMessage = $exception->getCode() === PdfParseException::FILE_NOT_READABLE
                ? 'The uploaded file could not be read.' : $exception->getMessage();
        } else {
            error_log('HuraiPDF extraction: ' . $exception->getMessage());
            $errorMessage = 'Extraction failed. Please try another PDF or contact the administrator.';
        }
    }
}

function parsePageField(string $name): ?int
{
    $raw = $_POST[$name] ?? '';
    if (!is_string($raw)) { throw new InvalidArgumentException('Page numbers must be positive integers.'); }
    if (trim($raw) === '') { return null; }
    $value = filter_var(trim($raw), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($value === false) { throw new InvalidArgumentException('Page numbers must be positive integers.'); }
    return $value;
}

function huraiPdfIniSizeToBytes(string $value): ?int
{
    $value = trim($value);
    if ($value === '' || $value === '-1') {
        return null;
    }

    if (preg_match('/^(\d+)\s*([KMG])?$/i', $value, $matches) !== 1) {
        return null;
    }

    $amount = (int) $matches[1];
    $multiplier = match (strtoupper($matches[2] ?? '')) {
        'G' => 1024 * 1024 * 1024,
        'M' => 1024 * 1024,
        'K' => 1024,
        default => 1,
    };

    if ($amount > intdiv(PHP_INT_MAX, $multiplier)) {
        return PHP_INT_MAX;
    }

    return $amount * $multiplier;
}

/** @param resource $handle */
function writeAll($handle, string $data): void
{
    $offset = 0;
    $length = strlen($data);
    while ($offset < $length) {
        $written = fwrite($handle, substr($data, $offset, 8192));
        if ($written === false || $written === 0) {
            throw new \RuntimeException('Failed while writing extracted text.');
        }
        $offset += $written;
    }
}

function writeAllFile(string $path, string $data): void
{
    $handle = fopen($path, 'wb');
    if ($handle === false) {
        throw new \RuntimeException('Unable to create the output metadata file.');
    }
    try {
        writeAll($handle, $data);
    } finally {
        fclose($handle);
    }
}

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>HuraiPdf Parser</title>
</head>
<body>
    <h1>HuraiPdf Parser Usage Example</h1>
    <form method="post" enctype="multipart/form-data">
        <label for="pdf_file">PDF file:</label>
        <input id="pdf_file" name="pdf_file" type="file" accept="application/pdf,.pdf" required>
        <br><br>
        <label for="from_page">From page (optional):</label>
        <input id="from_page" name="from_page" type="number" min="1" value="<?php echo htmlspecialchars((is_string($_POST['from_page'] ?? null) ? $_POST['from_page'] : ''), ENT_QUOTES, 'UTF-8'); ?>">
        &nbsp;
        <label for="to_page">To page (optional):</label>
        <input id="to_page" name="to_page" type="number" min="1" value="<?php echo htmlspecialchars((is_string($_POST['to_page'] ?? null) ? $_POST['to_page'] : ''), ENT_QUOTES, 'UTF-8'); ?>">
        <br><br>
        <label>
            <input name="exclude_numbers" type="checkbox" value="1"<?php echo $excludeNumbers ? ' checked' : ''; ?>>
            Exclude numbers/digits from the final output
        </label>
        &nbsp;
        <label>
            <input name="preserve_line_breaks" type="checkbox" value="1"<?php echo $preserveLineBreaks ? ' checked' : ''; ?>>
            Preserve line breaks
        </label>
        <br><br>
        <button type="submit">Upload and Extract</button>
    </form>

    <?php if ($errorMessage !== ''): ?>
        <p><?php echo htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8'); ?></p>
    <?php endif; ?>

    <?php if ($result !== null): ?>
        <h2>Result</h2>
        <p>Source PDF: <?php echo htmlspecialchars($result['source_pdf'], ENT_QUOTES, 'UTF-8'); ?></p>
        <p>Pages parsed: <?php echo htmlspecialchars($result['page_range'], ENT_QUOTES, 'UTF-8'); ?></p>
        <p>Numbers/digits excluded: <?php echo $result['exclude_numbers'] ? 'Yes' : 'No'; ?></p>
        <p>Line breaks preserved: <?php echo $result['preserve_line_breaks'] ? 'Yes' : 'No'; ?></p>
        <p>Text output: <a href="<?php echo htmlspecialchars($result['text_file'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($result['text_file'], ENT_QUOTES, 'UTF-8'); ?></a></p>
        <p>Meta output: <a href="<?php echo htmlspecialchars($result['meta_file'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($result['meta_file'], ENT_QUOTES, 'UTF-8'); ?></a></p>

        <?php if ($result['warnings'] !== []): ?>
            <h3>Warnings</h3>
            <ul>
                <?php foreach ($result['warnings'] as $warning): ?>
                    <li><?php echo htmlspecialchars($warning, ENT_QUOTES, 'UTF-8'); ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <h3>Extracted Text</h3>
        <pre style="white-space:pre-wrap;word-break:break-word;max-height:80vh;overflow-y:auto;border:1px solid #ccc;padding:1em;"><?php echo htmlspecialchars($result['preview'], ENT_QUOTES, 'UTF-8'); ?></pre>
        <?php if ($result['preview_truncated']): ?>
            <p>Preview truncated. Download the text output for the complete extraction.</p>
        <?php endif; ?>
    <?php endif; ?>
</body>
</html>
