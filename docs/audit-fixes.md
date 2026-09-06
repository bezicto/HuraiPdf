# Audit fixes and verification — 6 September 2026

This change addresses the code review findings against `54a5a90`. It preserves
the earlier lazy-loading and encoding optimizations. It does not deploy a server,
change existing uploaded documents, or delete legacy uploads/results.

## Implemented changes

| Finding | Resolution | Regression coverage |
|---|---|---|
| Arrays can exhaust memory before operator limits | Token, operand-array element and nesting limits checked during tokenization | Isolated 32 MiB worker rejects the 400 KB input with a resource exception |
| Repeated indirect content arrays expand exponentially | Per-page reference traversal budget and incremental reference scanning | Tiny nested-reference fixture stops with a resource exception in a 32 MiB worker |
| CMap expansion bypasses byte limits/deadlines | Incremental range parsing, mapping/allocation budgets and loop deadline checks | Expanded range fails at the configured mapping limit |
| Stale incremental revisions | Shared xref reader, current compressed membership and free-entry tombstones | Updated uncompressed/compressed objects and deleted entries |
| Incorrect Unicode conversion | Explicit UTF-16BE decoding, surrogate pairs and multi-character mappings | Chinese, Arabic, emoji and ligature mappings |
| Content-stream state resets and deduplication | Persistent page interpreter and separator state, ordered repeated references, graphics-state font restoration | Split text objects, split words, whitespace-only streams, line moves, repeated streams and q/Q |
| `endobj` inside text terminates an object | Token-aware object boundaries and resolved direct/indirect stream lengths | Embedded terminators, dictionary strings and comments |
| Inline image bytes become text tokens | Length/filter-aware binary skipping, including JPEG segment lengths | Unfiltered, Flate, ASCIIHex, ASCII85, RunLength and JPEG data |
| Resource names lose font mappings | Full resource-name syntax and PDF name-escape decoding | Hyphens, underscores and escaped font names |
| Interleaved parser operations share caches | Active-operation guard, released on completion/error/generator destruction | Rejected overlap leaves the original generator usable |
| Inconsistent header and object limits | Shared header validation and configured object-size exceptions | Invalid header and oversized object tests |
| Incremental extraction retains unnecessary images | Demand loading at extraction points, page-stream release and bounded retained caches | 24 unused 1 MiB images skipped; cache eviction preserves output |

The real-document corpus also exposed CR-only xref lines in a linearized PDF.
These now remain on the indexed reader rather than triggering recovery.

## Historical verification

These results were recorded before the requested removal of PHPUnit, the test
suite, benchmarks and development dependencies. Those tools are no longer
included. The current GitHub workflow validates Composer metadata and PHP syntax
on PHP 8.3, 8.4 and 8.5; it does not run the former regression suite.

- PHP 8.5.9: **59 tests, 325 assertions**, including a real localhost HTTP server.
- Syntax checks cover all **18 PHP files**; Composer validation and diff whitespace
  checks pass.
- **10 distinct existing local PDFs, 78 pages**: matching page counts and output
  hashes across file/memory readers, with no errors, warnings or recovery cases.
- File-reader process peaks on that corpus were at most **6 MiB** in this environment.
- No local document text was printed, sent to a service, or committed as a fixture.
- Only PHP 8.5 was installed locally; PHP 8.3 and 8.4 were not executed here.
- The standalone `index.php` HTTP test covers uploads, text/JSON downloads,
  page selection, digit filtering, invalid files/ranges and failed-output cleanup.

## Final review after the interrupted session

The final review found and fixed three additional issues. A content stream that
contained only a separator could be discarded, or a line move at the start of the
next stream could lose its separator; `Hello world` could become `Helloworld`.
Separator state now survives stream boundaries, and generated newlines count
toward the text budget.

A tiny PDF containing repeated indirect content arrays could exhaust the PHP
worker's memory before operator limits applied. This was reproduced as a fatal
error in an isolated 32 MiB process. Reference traversal now stops at
`maxArrayElements` per page with a catchable resource-limit exception, including
repeated visits through nested arrays.

The web application scaffolding from the earlier audit was subsequently removed
at the user's request. `index.php` is a standalone learning example with direct
output links, without authentication, sessions, a separate public entry point,
private-job storage or a cleanup CLI. The earlier private-web deployment assessment
no longer describes this example. Core parser fixes and performance work remain.

The parser fixes remain in the distributed library after development tooling removal.

## Spacing and word grouping comparison

macOS PDFKit agreed on all document page counts. We compared NFKC-normalized,
case-folded unique word sets against both the revised parser and the pre-change
parser. No reference words previously extracted by HuraiPDF became newly missing
in the revised parser on this corpus.

Of 87 unique reference-word differences counted across the documents, 80 were
present in the extracted character sequence with different grouping/spacing.
Seven other differences also existed in the pre-change parser. These measurements
do not establish exact reading-order or layout fidelity. Complex positioning,
tables and multi-column text retain the documented extraction limitations.

## Performance observations

Fresh-process synthetic measurements on this machine, rounded:

| Case | Elapsed | Object bytes read | Process peak |
|---|---:|---:|---:|
| Pages 100–110 of 250 | 3.5 ms | 28,820 | 8 MiB |
| All 250 pages | 11.5 ms | 366,595 | 8 MiB |
| 250-page callback | 12.2 ms | 366,595 | 8 MiB |
| 24 pages with unused images | 1.1 ms | 4,529 | 4 MiB |

The image case loads 50 of 74 indexed objects, with roughly 148 KB live memory
growth during callbacks. The audit previously observed about 24 MiB of object
reads and memory growth proportional to image payloads. The CR-only real PDF now
requires approximately 172 KB of object reads instead of a 7.5 MB recovery read.

These timings are observations, not thresholds or cross-machine guarantees.
Safety checks add overhead to some small range operations; bulk literal-string
reading improves the text-heavy full-document case. The measurement scripts were
subsequently removed at the user's request.

## Deployment and remaining limits

Run the standalone example from the project directory:

```sh
php -d upload_max_filesize=64M -d post_max_size=65M -S 127.0.0.1:8080
```

The example saves directly accessible text/JSON files in `output/` and leaves
retention to the user. It does not retain source uploads. It is a local usage
example, not a private document service; see [the README](../README.md#web-interface).

Cache accounting estimates PHP allocation and does not replace process-level
memory/time limits. Recovery still retains a full input buffer. Unsupported or
ambiguous inline-image boundaries produce a warning and skip the rest of that
content stream. OCR, decryption and exact visual layout reconstruction remain
outside the library's supported behavior.
