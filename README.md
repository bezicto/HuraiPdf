# HuraiPdf

A zero-dependency, pure-PHP PDF text extraction library compatible with PHP 8.3, 8.4, and 8.5.

---

## Features

- Extract text from PDF files or raw PDF string content
- Per-page text access with page numbers and PDF object IDs
- PDF metadata: version, page count, encryption status
- Handles common stream encodings: Flate/Deflate, LZW, ASCII-85, ASCII-Hex, Run-Length
- Font support: ToUnicode CMaps, standard/PDF encodings, glyph name resolution
- Built-in web interface (`index.php`) for quick upload-and-extract testing
- Parsing warnings surfaced (non-fatal issues collected and returned)

---

## Requirements

- PHP **8.3, 8.4, or 8.5**
- PHP extensions: `ctype` and `zlib`
- Recommended: `mbstring` or `iconv` for legacy font encoding conversion
- Web interface only: `fileinfo`

---

## Installation

Clone or download the repository and register the dependency-free autoloader:

```php
spl_autoload_register(static function (string $class): void {
    $prefix = 'HuraiPdf\\';
    $baseDir = __DIR__ . '/src/HuraiPdf/';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relativeClass = substr($class, strlen($prefix));
    $filePath = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (is_file($filePath)) {
        require $filePath;
    }
});
```

---

## Quick Start

```php
use HuraiPdf\Parser;
use HuraiPdf\Exception\PdfParseException;

$parser = new Parser();

try {
    $document = $parser->parseFile('/path/to/document.pdf');

    echo $document->getText();       // All extracted text
    echo $document->getPageCount();  // Number of pages
    echo $document->getPdfVersion(); // e.g. "1.4"

} catch (PdfParseException $e) {
    echo 'Failed to parse PDF: ' . $e->getMessage();
}
```

### With stopword filtering

```php
use HuraiPdf\Parser;
use HuraiPdf\Filter\StopWordFilter;
use HuraiPdf\Exception\PdfParseException;

$parser = new Parser();
$filter = new StopWordFilter();

try {
    $document = $parser->parseFile('/path/to/document.pdf');

    $keywords = $filter->filter($document->getText());
    // "invoice total amount rm payable date ..."
} catch (PdfParseException $e) {
    echo 'Failed to parse PDF: ' . $e->getMessage();
}
```

#### Optionally remove numbers and digits

By default, `StopWordFilter::filter()` retains numbers. Pass
`excludeNumbers: true` to remove ASCII digits (`0`–`9`) from the filtered output:

```php
$text = 'Invoice 2026 Batch42Code Room7';

$withNumbers = $filter->filter($text);
// "invoice 2026 batch42code room7"

$withoutNumbers = $filter->filter($text, excludeNumbers: true);
// "invoice batch code room"
```

Digits act as token separators. Numeric-only tokens disappear, while the letter
parts of mixed tokens remain. For example, `Batch42Code` becomes `batch code`.
This option affects only the filtered result; the `Document` returned by the
parser continues to contain the original extracted numbers.

The parameter is optional and defaults to `false`, so existing calls require no
changes:

```php
$filter->filter($text);                               // Keep numbers
$filter->filter($text, excludeNumbers: false);       // Keep numbers explicitly
$filter->filter($text, excludeNumbers: true);        // Remove numbers/digits
```

For incremental extraction, pass the same option to `filterChunks()`. It
preserves input keys and omits chunks that become empty after filtering:

```php
$filteredPages = $filter->filterChunks(
    (static function () use ($parser): iterable {
        foreach ($parser->parseFilePages('/path/to/document.pdf') as $number => $page) {
            yield $number => $page->getText();
        }
    })(),
    excludeNumbers: true
);

foreach ($filteredPages as $pageNumber => $keywords) {
    // Persist or index one filtered page at a time.
}
```

In the included web interface, select **Exclude numbers/digits from the final
output** before uploading the PDF. The choice is also saved as
`exclude_numbers` in the generated JSON metadata.

---

## API Reference

### `Parser`

The main entry point. Instantiate with `new Parser()`. An optional `ParserOptions` object can be passed to tune internal thresholds.

```php
use HuraiPdf\Parser;
use HuraiPdf\ParserOptions;
use HuraiPdf\Page;

// Default settings
$parser = new Parser();

// Custom settings
$parser = new Parser(new ParserOptions(
    streamingThreshold: 10 * 1024 * 1024, // use streaming mode above 10 MB (default: 5 MB)
    maxCMapSize:        2_097_152,          // max bytes to decode a CMap (default: 1 MB)
    objectChunkSize:    524_288,            // object scan chunk size (default: 256 KB)
    maxObjects:         250_000,
    maxPages:           20_000,
    maxDecodedBytesTotal: 256 * 1024 * 1024,
    deadlineSeconds:    120.0,
));
```

Resource options default to 500,000 objects, 100,000 emitted pages, 100 MB per
stream, 512 MB cumulative decoded data, 10 million content operators, recursion
depth 64, and 1,000 retained warnings. `deadlineSeconds` is disabled by default.

#### `parseFile(string $filePath, int $fromPage = 1, ?int $toPage = null): Document`

Reads and parses a PDF file from disk.

| Parameter | Type | Default | Description |
|---|---|---|---|
| `$filePath` | `string` | — | Absolute or relative path to the PDF file |
| `$fromPage` | `int` | `1` | First page to parse (1-based) |
| `$toPage` | `int\|null` | `null` | Last page to parse (inclusive). `null` means parse to the end |

```php
// Parse entire PDF
$document = $parser->parseFile('/path/to/file.pdf');

// Parse pages 3 to 7 only
$document = $parser->parseFile('/path/to/file.pdf', 3, 7);

// Parse from page 5 to the end
$document = $parser->parseFile('/path/to/file.pdf', 5);
```

Throws `PdfParseException` if:
- The file does not exist or is not readable
- The file cannot be read
- The content is not a valid PDF (missing header)
- No PDF objects or page objects are found
- `$fromPage` is less than `1`
- `$toPage` is less than `$fromPage`

#### `parseContent(string $content, int $fromPage = 1, ?int $toPage = null): Document`

Parses a PDF from a string (e.g. from a database column, HTTP response, or `file_get_contents()`).

| Parameter | Type | Default | Description |
|---|---|---|---|
| `$content` | `string` | — | Raw PDF binary string |
| `$fromPage` | `int` | `1` | First page to parse (1-based) |
| `$toPage` | `int\|null` | `null` | Last page to parse (inclusive). `null` means parse to the end |

```php
$pdfContent = file_get_contents('https://example.com/file.pdf');

// Parse entire PDF
$document = $parser->parseContent($pdfContent);

// Parse only the first 3 pages
$document = $parser->parseContent($pdfContent, 1, 3);
```

Throws the same `PdfParseException` cases as `parseFile()`, including `INVALID_PAGE_RANGE` when `$fromPage < 1` or `$toPage < $fromPage`.

#### Incremental extraction

`parseFilePages()` yields each page as soon as it is extracted. For large files,
this avoids retaining all extracted page text in a `Document`:

```php
foreach ($parser->parseFilePages('large.pdf', 10, 20) as $pageNumber => $page) {
    file_put_contents('pages.txt', $page->getText() . "\n", FILE_APPEND);
}
```

`extractFile()` provides a callback-oriented equivalent and returns final metadata
and metrics:

```php
$result = $parser->extractFile(
    'large.pdf',
    static function (Page $page): void {
        // Write, index, or transmit this page before the next page is parsed.
    }
);
```

#### Performance metrics

After a parse, `getLastMetrics()` returns counters for the most recent operation:

```php
$document = $parser->parseFile('report.pdf', 10, 20);
$metrics = $parser->getLastMetrics();

echo $metrics['objects_loaded'];
echo $metrics['objects_indexed'];
echo $metrics['object_bytes_read'];
echo $metrics['decoded_bytes'];
echo $metrics['duration_ms'];
```

`getLastMetadata()` returns `pdf_version`, `is_encrypted`, `warnings`, and the
number of pages actually emitted. Metadata and metrics are finalized even when a
page generator is closed early.

For large files with a usable cross-reference table or stream, HuraiPdf loads the
catalog, requested page-tree segment, and reachable page dependencies lazily.
Malformed or unsupported cross-reference structures fall back to the compatible
full-file parser.

---

### `Document`

Returned by both parser methods. Holds all extracted data.

| Method | Return type | Description |
|---|---|---|
| `getText(?int $pageLimit = null)` | `string` | Concatenated text from all pages. Pass an integer to limit how many pages are included. |
| `getTextGenerator(?int $pageLimit = null)` | `Generator<int, string>` | Yields retained page text without building a combined string. Use `Parser::parseFilePages()` for incremental parsing. |
| `getPages()` | `Page[]` | Array of all `Page` objects |
| `getPageCount()` | `int` | Total number of pages extracted |
| `getPdfVersion()` | `string` | PDF version string (e.g. `"1.4"`, `"1.7"`, or `"unknown"`) |
| `isEncrypted()` | `bool` | `true` if the PDF has an encryption dictionary |
| `getWarnings()` | `string[]` | Non-fatal issues encountered during parsing |

#### Examples

```php
$document = $parser->parseFile('report.pdf');

// Get all text
$allText = $document->getText();

// Get text from first 5 pages only
$preview = $document->getText(5);

// Parse only pages 3–7, then get their text
$document = $parser->parseFile('report.pdf', 3, 7);
$ranged   = $document->getText();
echo $document->getPageCount(); // 5

// Iterate pages
foreach ($document->getPages() as $page) {
    printf("Page %d:\n%s\n\n", $page->getPageNumber(), $page->getText());
}

// Metadata
echo $document->getPdfVersion(); // "1.6"
echo $document->getPageCount();  // 12
var_dump($document->isEncrypted()); // bool(false)

// Warnings (non-fatal parsing issues)
foreach ($document->getWarnings() as $warning) {
    echo 'Warning: ' . $warning . "\n";
}

// Stream text page-by-page without building one large string (useful for large PDFs)
foreach ($document->getTextGenerator() as $pageIndex => $pageText) {
    file_put_contents("page_{$pageIndex}.txt", $pageText);
}
```

---

### `Page`

Represents a single extracted page.

| Method | Return type | Description |
|---|---|---|
| `getPageNumber()` | `int` | 1-based page number |
| `getObjectId()` | `int` | Internal PDF object ID for this page |
| `getText()` | `string` | Extracted, whitespace-normalized text for this page |

#### Example

```php
$pages = $document->getPages();

$firstPage = $pages[0];

echo $firstPage->getPageNumber(); // 1
echo $firstPage->getObjectId();   // e.g. 4 (PDF internal object id)
echo $firstPage->getText();       // "Hello World ..."
```

---

### `PdfParseException`

Thrown when the parser encounters a fatal error. Extends `\RuntimeException`.

| Code constant | Value | Cause |
|---|---|---|
| `FILE_NOT_READABLE` | `1` | File does not exist or cannot be read |
| `FILE_READ_FAILURE` | `2` | `file_get_contents` returned `false` |
| `INVALID_HEADER` | `3` | Content does not start with `%PDF-` |
| `NO_OBJECTS_FOUND` | `4` | No indirect PDF objects located |
| `NO_PAGES_FOUND` | `5` | No page objects resolved in the document |
| `INVALID_PAGE_RANGE` | `6` | `$fromPage < 1` or `$toPage < $fromPage` |
| `RESOURCE_LIMIT_EXCEEDED` | `7` | A configured object, page, stream, decoded-byte, operator, recursion, or deadline limit was exceeded |

```php
use HuraiPdf\Exception\PdfParseException;

try {
    $document = $parser->parseFile('bad.pdf');
} catch (PdfParseException $e) {
    // Handle parse failure
    echo $e->getMessage();
    echo $e->getCode(); // one of the PdfParseException::* constants
}
```

---

## Saving Output to Files

```php
use HuraiPdf\Parser;

$parser   = new Parser();
$document = $parser->parseFile('invoice.pdf');

// Save extracted text
file_put_contents('invoice.txt', $document->getText());

// Save metadata as JSON
$meta = [
    'pdf_version' => $document->getPdfVersion(),
    'page_count'  => $document->getPageCount(),
    'is_encrypted' => $document->isEncrypted(),
    'warnings'    => $document->getWarnings(),
    'extracted_at' => gmdate(DATE_ATOM),
];
file_put_contents('invoice.json', json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
```

---

## Web Interface

The included `index.php` provides a browser-based upload form for quick testing.

**Setup:**

1. Ensure the `uploads/` and `output/` directories are writable by your web server.
2. Serve the project root with PHP (built-in server, Apache, Nginx, etc.).
3. Open the page in a browser, choose a PDF, and submit.

**Using PHP's built-in server:**

```bash
php -S localhost:8080
```

Then open `http://localhost:8080` in your browser.

**Limits imposed by the web interface:**
- Max file size: **60 MB**
- Accepted type: `application/pdf` only (validated by MIME type, magic bytes, and extension)
- Max execution time: 300 seconds
- Requested memory limit: 512 MB; on PHP 8.5, a lower server-level `max_memory_limit` takes precedence
- Browser preview: first **100 KB**; complete text remains available through the output file
- Optional **Exclude numbers/digits** checkbox removes ASCII digits from filtered output

The web interface consumes pages incrementally, filters and writes each page
directly to a temporary output, then atomically publishes the completed text and
metadata files. The metadata records the `exclude_numbers` selection. Failed
extractions remove partial output files.

Output files are written to `output/`:
- `<filename>.txt` — extracted plain text
- `<filename>.json` — metadata (page count, PDF version, warnings, timestamps, etc.)

---

## Large PDF Considerations

For large or complex PDFs, increase PHP's limits before calling the parser:

```php
ini_set('max_execution_time', '300');
ini_set('memory_limit', '512M');
```

PHP 8.5 administrators can cap application-level memory changes with
`max_memory_limit`. Ensure that server-level value is large enough for the PDFs
you expect to process.

## Development

Install development dependencies and run the regression suite:

```bash
composer install
composer test
```

Run the synthetic range/full-document benchmark:

```bash
composer benchmark
```

The benchmark reports elapsed time, page count, objects loaded versus indexed,
object bytes read, decoded bytes, content operators, and peak memory. Production
decisions should also be checked against a representative corpus of real PDFs.

---

## Limitations

- **Scanned / image-only PDFs** — No OCR is performed. PDFs that contain only scanned images will return empty or minimal text.
- **Encrypted / password-protected PDFs** — Encrypted PDFs are detected and flagged via `isEncrypted()`, but decryption is not supported. Text extraction will likely be empty or fail.
- **Complex layouts** — Multi-column documents, tables, and text inside vector graphics may not preserve their visual reading order.
- **Prototype status** — This is an evolving prototype. Edge cases in the PDF specification may not be handled.

---

## Project Structure

```
.
├── index.php                          # Web interface
├── src/
│   └── HuraiPdf/
│       ├── Parser.php                 # Main parsing engine
│       ├── ParserOptions.php          # Tunable thresholds passed to Parser
│       ├── ParseContext.php            # Per-operation caches, counters, and metadata
│       ├── Document.php               # Parsed document container
│       ├── Page.php                   # Single page with text
│       ├── PdfObject.php              # Internal typed value object for PDF indirect objects
│       ├── Exception/
│       │   └── PdfParseException.php  # Custom exception
│       └── Filter/
│           └── StopWordFilter.php     # Stopword filter
├── uploads/                           # Temp storage for uploaded PDFs
└── output/                            # Extracted text and metadata output
```

---

## License

MIT
