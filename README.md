# pdftract PHP SDK

PHP SDK for [pdftract](https://github.com/jedarden/pdftract) - PDF text extraction with structured output.

## Installation

```bash
composer require jedarden/pdftract
```

## Usage

```php
<?php

use Jedarden\Pdftract\Client;
use Jedarden\Pdftract\Source;

// Create client
$client = new Client('pdftract');

// Extract structured data
$result = $client->extract(Source::file('/path/to/document.pdf'), [
    'ocrLanguage' => 'eng'
]);

print_r($result);

// Extract plain text
$text = $client->extractText(Source::file('/path/to/document.pdf'));

// Extract markdown
$markdown = $client->extractMarkdown(Source::file('/path/to/document.pdf'));

// Stream extraction
foreach ($client->extractStream(Source::file('/path/to/document.pdf')) as $page) {
    echo "Page {$page['page_index']}: " . $page['content'] . "\n";
}

// Search in PDF
foreach ($client->search(Source::file('/path/to/document.pdf'), 'pattern') as $match) {
    echo "Found at page {$match['page_index']}\n";
}

// Get metadata
$metadata = $client->getMetadata(Source::file('/path/to/document.pdf'));

// Compute hash
$hash = $client->hash(Source::file('/path/to/document.pdf'));

// Classify document
$classification = $client->classify(Source::file('/path/to/document.pdf'));

// Verify receipt
$isValid = $client->verifyReceipt('/path/to/document.pdf', $receipt);
```

## Requirements

- PHP >= 8.1
- psr/log ^3.0
- pdftract binary in PATH

## Methods

### extract(Source|string $source, array $options = []): array
Extract structured data from a PDF.

### extractText(Source|string $source, array $options = []): string
Extract plain text from a PDF.

### extractMarkdown(Source|string $source, array $options = []): string
Extract markdown from a PDF.

### extractStream(Source|string $source, array $options = []): \Generator
Extract structured data as a stream (yields one page at a time).

### search(Source|string $source, string $pattern, array $options = []): \Generator
Search for text patterns in a PDF.

### getMetadata(Source|string $source, array $options = []): array
Get metadata from a PDF.

### hash(Source|string $source, array $options = []): array
Compute hash of a PDF.

### classify(Source|string $source, array $options = []): array
Classify a PDF document.

### verifyReceipt(string $path, string $receipt): bool
Verify a processing receipt.

## Options

Options use camelCase (CLI --flag becomes optionFlag):

- `ocrLanguage` - OCR language code (e.g., 'eng', 'fra')
- `caseInsensitive` - Case-insensitive search (boolean)
- `fast` - Use fast hash algorithm (boolean)

## Logging

The client accepts a PSR-3 logger for debugging:

```php
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

$logger = new Logger('pdftract');
$logger->pushHandler(new StreamHandler('php://stdout'));

$client = new Client('pdftract', $logger);
```

## License

MIT
