<?php

declare(strict_types=1);

/**
 * HuraiPDF: a standalone upload-and-extract example for learning the library.
 *
 * Quick start (PHP 8.3–8.5 with ctype, zlib and openssl enabled):
 * 1. From this project's root directory, run:
 *    php -d upload_max_filesize=64M -d post_max_size=65M -S 127.0.0.1:8080
 * 2. Open http://127.0.0.1:8080 and choose a PDF containing selectable text.
 * 3. Leave both page fields blank for the whole document, or enter an inclusive
 *    range such as From page 2 / To page 5. Choose the filtering options below.
 * 4. Click "Upload and Extract", review the preview and any warnings, then open
 *    the .txt link for the full filtered text or the .json link for metadata.
 *
 * This example does not perform OCR on scanned images and has no password field.
 * It always applies StopWordFilter; see the callback below to keep unfiltered text.
 * The request handler comes first, followed by helpers and the HTML form/results.
 */

// Import the library classes used below; the autoloader resolves their PHP files.
use HuraiPdf\Exception\PdfParseException;
use HuraiPdf\Filter\StopWordFilter;
use HuraiPdf\Parser;
use HuraiPdf\ParserOptions;

// Resolve library paths relative to this file so Composer is not needed to run
// this example. In a Composer application, use its vendor/autoload.php instead.
$baseDir = __DIR__;
spl_autoload_register(static function (string $class) use ($baseDir): void {
    if (str_starts_with($class, 'HuraiPdf\\')) {
        $path = $baseDir . '/src/HuraiPdf/' . str_replace('\\', '/', substr($class, 9)) . '.php';
        if (is_file($path)) { require $path; }
    }
});

// Send browser protections before any HTML: disable caching of this response,
// restrict forms to this origin, and prevent embedding this page in a frame.
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
header('Referrer-Policy: no-referrer');

// PHP must be able to create/write this directory. Successful text and JSON files
// remain here until deleted; the uploaded PDF stays in PHP's temporary storage.
// The links below serve output/ directly in this local example.
$outputDir = __DIR__ . '/output';

// Limits are in bytes: 60 MiB input, 100 KiB preview, and 32 MiB generated text.
// PHP's upload_max_filesize and post_max_size must also allow the upload (see
// the startup command above); those limits apply before this handler runs.
const MAX_UPLOAD_BYTES = 60 * 1024 * 1024;
const MAX_PREVIEW_BYTES = 100 * 1024;
const MAX_OUTPUT_BYTES = 32 * 1024 * 1024;
// Allow up to 300 seconds in PHP and request at most 512 MiB of memory, respecting
// the administrator's max_memory_limit when available. Server limits still apply.
ini_set('max_execution_time', '300');
$maxMemoryLimit = ini_get('max_memory_limit');
$maxMemoryBytes = is_string($maxMemoryLimit) ? huraiPdfIniSizeToBytes($maxMemoryLimit) : null;
ini_set('memory_limit', (string) min($maxMemoryBytes ?? 512 * 1024 * 1024, 512 * 1024 * 1024));

// A first visit shows an empty form. After submission, these values let the
// template retain checkbox choices and display either results or an error.
$errorMessage = '';
$result = null;
$excludeNumbers = false;
$preserveLineBreaks = false;
$createdFiles = [];
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    try {
        // Checkbox names match the HTML form. Unchecked boxes are absent from POST.
        $excludeNumbers = ($_POST['exclude_numbers'] ?? '') === '1';
        $preserveLineBreaks = ($_POST['preserve_line_breaks'] ?? '') === '1';
        // multipart/form-data places the PDF in $_FILES, not $_POST. Check PHP's
        // upload status before reading its temporary file (no permanent PDF copy).
        $uploadedFile = $_FILES['pdf_file'] ?? null;
        if (!is_array($uploadedFile) || ($uploadedFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException('Upload failed. Check the file size and server upload limits.');
        }
        // Only accept a file supplied by PHP's HTTP upload mechanism.
        $tmpPath = $uploadedFile['tmp_name'] ?? null;
        $originalName = $uploadedFile['name'] ?? null;
        if (!is_string($tmpPath) || !is_string($originalName) || !is_uploaded_file($tmpPath)) {
            throw new InvalidArgumentException('Invalid upload source.');
        }
        // Validate the actual file size, extension, and PDF header on the server;
        // the browser's file picker is only a convenience, not validation.
        $fileSize = filesize($tmpPath);
        if ($fileSize === false || $fileSize < 1 || $fileSize > MAX_UPLOAD_BYTES) {
            throw new InvalidArgumentException('Upload a nonempty PDF no larger than 60 MB.');
        }
        if (strtolower(pathinfo($originalName, PATHINFO_EXTENSION)) !== 'pdf'
            || file_get_contents($tmpPath, false, null, 0, 5) !== '%PDF-') {
            throw new InvalidArgumentException('Only PDF files are allowed.');
        }
        // Page numbers start at 1 and both endpoints are included. A blank start
        // means page 1; a blank end means continue through the final page.
        $fromPage = parsePageField('from_page') ?? 1;
        $toPage = parsePageField('to_page');
        if ($toPage !== null && $toPage < $fromPage) {
            throw new InvalidArgumentException('To page must be greater than or equal to From page.');
        }
        // Create output storage on demand. Random basenames keep separate uploads
        // from sharing a filename and avoid using the supplied name as a disk path.
        if (!is_dir($outputDir) && !mkdir($outputDir, 0775, true) && !is_dir($outputDir)) {
            throw new RuntimeException('Unable to create the output directory.');
        }
        $name = bin2hex(random_bytes(16));
        $textPath = $outputDir . '/' . $name . '.txt';
        $metaPath = $outputDir . '/' . $name . '.json';
        // Write temporary .part files first and track all paths for error cleanup.
        // The 'x' mode creates a new file exclusively instead of overwriting one.
        $createdFiles = [$textPath . '.part', $metaPath . '.part', $textPath, $metaPath];
        $handle = fopen($textPath . '.part', 'xb');
        if ($handle === false) { throw new RuntimeException('Unable to create extraction output.'); }

        // Parser is the library entry point. ParserOptions bounds input, generated
        // text and parsing time; the 280-second deadline leaves room for saving.
        // For a simpler in-memory integration, (new Parser())->parseFile($path)
        // returns a Document whose getText() contains the extracted page text.
        $parser = new Parser(new ParserOptions(
            maxInputBytes: MAX_UPLOAD_BYTES,
            maxExtractedTextBytes: MAX_OUTPUT_BYTES,
            deadlineSeconds: 280.0,
        ));
        // This example always filters: lowercase ASCII tokens, remove English/Malay
        // stopwords and single-character tokens, and discard punctuation. This is
        // useful for keywords; it does not preserve the original wording/layout.
        // The checkboxes additionally remove digits or retain extracted line breaks.
        $filter = new StopWordFilter();
        $textLength = 0;
        $preview = '';
        // extractFile() calls this function for each selected Page and returns
        // metadata/metrics when finished. Writing each page avoids accumulating all
        // output text in memory. The callback captures counters/preview by reference
        // so their updated values remain available when building the result.
        // To bypass keyword filtering, replace the filter call with:
        // $filtered = $page->getText();
        // That also bypasses both filter options; the separator below still applies.
        // The finally block releases the file handle even when parsing throws.
        try {
            $extraction = $parser->extractFile($tmpPath, static function ($page) use ($filter, $handle, $excludeNumbers, $preserveLineBreaks, &$textLength, &$preview): void {
                $filtered = $filter->filter($page->getText(), $excludeNumbers, $preserveLineBreaks);
                // Skip pages with no retained tokens. Join other pages with a space,
                // or a blank line when Preserve line breaks is checked.
                if ($filtered === '') { return; }
                $separator = $preserveLineBreaks ? "\n\n" : ' ';
                $chunk = ($textLength > 0 ? $separator : '') . $filtered;
                // Bound the saved output too, including separators added here.
                if (strlen($chunk) > MAX_OUTPUT_BYTES - $textLength) {
                    throw PdfParseException::resourceLimitExceeded('Output exceeds the configured text limit.');
                }
                writeAll($handle, $chunk);
                $textLength += strlen($chunk);
                // Save the full chunk to disk above, but keep only the first 100 KiB
                // for display. A truncated preview does not truncate the .txt file.
                if (strlen($preview) < MAX_PREVIEW_BYTES) {
                    $preview .= substr($chunk, 0, MAX_PREVIEW_BYTES - strlen($preview));
                }
            }, $fromPage, $toPage);
        } finally { fclose($handle); }
        // Keep parser metadata (PDF details, warnings, emitted page count) alongside
        // the chosen options and metrics. text_length counts bytes, not characters;
        // to_page remains null when the user requested extraction through the end.
        $metadata = $extraction['metadata'];
        $meta = $metadata + [
            'source_original_name' => $originalName,
            'extracted_at_utc' => gmdate(DATE_ATOM), 'engine' => 'HuraiPdf/Parser',
            'from_page' => $fromPage, 'to_page' => $toPage, 'exclude_numbers' => $excludeNumbers,
            'preserve_line_breaks' => $preserveLineBreaks,
            'text_length' => $textLength, 'performance' => $extraction['metrics'],
        ];
        // Save readable JSON, substituting invalid UTF-8, then rename completed
        // files to their final names. Only expose links after both renames succeed.
        writeAllFile($metaPath . '.part', json_encode($meta, JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR));
        if (!rename($textPath . '.part', $textPath)
            || !rename($metaPath . '.part', $metaPath)) {
            throw new RuntimeException('Unable to publish extraction output.');
        }
        // Build display values and URLs for the template. If output storage moves,
        // update these URLs as well as $outputDir (or add a download handler).
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
        // Remove this attempt's files on failure. Show actionable upload/parser
        // errors; log unexpected failures rather than exposing internal details.
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

/**
 * Read an optional page field: blank means no explicit boundary (null).
 * Reject arrays, zero, negative numbers, and non-integers before calling Parser.
 */
function parsePageField(string $name): ?int
{
    $raw = $_POST[$name] ?? '';
    if (!is_string($raw)) { throw new InvalidArgumentException('Page numbers must be positive integers.'); }
    if (trim($raw) === '') { return null; }
    $value = filter_var(trim($raw), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($value === false) { throw new InvalidArgumentException('Page numbers must be positive integers.'); }
    return $value;
}

/**
 * Convert PHP memory settings such as "512M" to bytes for the startup memory cap.
 * Return null for an unset, unlimited (-1), or unrecognized value.
 */
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

    // Clamp very large settings so multiplication cannot overflow PHP's integer.
    if ($amount > intdiv(PHP_INT_MAX, $multiplier)) {
        return PHP_INT_MAX;
    }

    return $amount * $multiplier;
}

/**
 * Write every byte, retrying short writes in chunks of at most 8 KiB.
 * Throw if writing stops so a partial extraction is handled as a failure.
 *
 * @param resource $handle Open writable stream; the caller closes it.
 */
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

/** Save a complete metadata string and close its file even if writing fails. */
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
    <!-- Submit to this same page. multipart/form-data is required for PDF uploads;
         field names correspond to the $_FILES/$_POST keys in the handler above. -->
    <form method="post" enctype="multipart/form-data">
        <label for="pdf_file">PDF file:</label>
        <input id="pdf_file" name="pdf_file" type="file" accept="application/pdf,.pdf" required>
        <br><br>
        <!-- Leave both page fields empty for all pages; use 2 and 5 for pages 2–5.
             Echoed form values are HTML-escaped so user input remains text. -->
        <label for="from_page">From page (optional):</label>
        <input id="from_page" name="from_page" type="number" min="1" value="<?php echo htmlspecialchars((is_string($_POST['from_page'] ?? null) ? $_POST['from_page'] : ''), ENT_QUOTES, 'UTF-8'); ?>">
        &nbsp;
        <label for="to_page">To page (optional):</label>
        <input id="to_page" name="to_page" type="number" min="1" value="<?php echo htmlspecialchars((is_string($_POST['to_page'] ?? null) ? $_POST['to_page'] : ''), ENT_QUOTES, 'UTF-8'); ?>">
        <br><br>
        <!-- Removing digits also splits mixed tokens: Batch42Code becomes batch code.
             Leaving this unchecked keeps digits subject to the base token filter. -->
        <label>
            <input name="exclude_numbers" type="checkbox" value="1"<?php echo $excludeNumbers ? ' checked' : ''; ?>>
            Exclude numbers/digits from the final output
        </label>
        &nbsp;
        <!-- Retain extracted lines and paragraph gaps, with a blank line between
             retained pages. This does not reconstruct the PDF's visual layout. -->
        <label>
            <input name="preserve_line_breaks" type="checkbox" value="1"<?php echo $preserveLineBreaks ? ' checked' : ''; ?>>
            Preserve line breaks
        </label>
        <br><br>
        <button type="submit">Upload and Extract</button>
    </form>

    <!-- On failure, review the message, correct the options or choose another PDF,
         and select the file again before submitting (browsers reset file inputs). -->
    <?php if ($errorMessage !== ''): ?>
        <p><?php echo htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8'); ?></p>
    <?php endif; ?>

    <!-- Successful results link to the complete filtered text and JSON metadata.
         Escape filenames, warnings and extracted text before inserting them in HTML. -->
    <?php if ($result !== null): ?>
        <h2>Result</h2>
        <p>Source PDF: <?php echo htmlspecialchars($result['source_pdf'], ENT_QUOTES, 'UTF-8'); ?></p>
        <p>Pages parsed: <?php echo htmlspecialchars($result['page_range'], ENT_QUOTES, 'UTF-8'); ?></p>
        <p>Numbers/digits excluded: <?php echo $result['exclude_numbers'] ? 'Yes' : 'No'; ?></p>
        <p>Line breaks preserved: <?php echo $result['preserve_line_breaks'] ? 'Yes' : 'No'; ?></p>
        <p>Text output: <a href="<?php echo htmlspecialchars($result['text_file'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($result['text_file'], ENT_QUOTES, 'UTF-8'); ?></a></p>
        <p>Meta output: <a href="<?php echo htmlspecialchars($result['meta_file'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($result['meta_file'], ENT_QUOTES, 'UTF-8'); ?></a></p>

        <!-- Warnings are non-fatal parsing issues; review them when output is empty
             or incomplete. Scanned PDFs need OCR elsewhere to supply text. -->
        <?php if ($result['warnings'] !== []): ?>
            <h3>Warnings</h3>
            <ul>
                <?php foreach ($result['warnings'] as $warning): ?>
                    <li><?php echo htmlspecialchars($warning, ENT_QUOTES, 'UTF-8'); ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <!-- The preview shows the start of the saved text. Use Text output for the
             entire extraction when the preview-limit message appears. -->
        <h3>Extracted Text</h3>
        <pre style="white-space:pre-wrap;word-break:break-word;max-height:80vh;overflow-y:auto;border:1px solid #ccc;padding:1em;"><?php echo htmlspecialchars($result['preview'], ENT_QUOTES, 'UTF-8'); ?></pre>
        <?php if ($result['preview_truncated']): ?>
            <p>Preview truncated. Download the text output for the complete extraction.</p>
        <?php endif; ?>
    <?php endif; ?>
</body>
</html>
