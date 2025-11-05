# Multimodal Examples

Practical examples for processing images and PDFs with AI models. Perfect for document analysis, visual understanding, and data extraction tasks.

## Image Analysis Examples

### Product Image Description Generator

Generate SEO-friendly product descriptions from images:

```php
<?php
use Soukicz\Llm\Message\LLMMessage;
use Soukicz\Llm\Message\LLMMessageContents;
use Soukicz\Llm\Message\LLMMessageImage;
use Soukicz\Llm\Message\LLMMessageText;

function generateProductDescription(string $imagePath): string {
    global $agentClient, $client, $model;

    $imageData = base64_encode(file_get_contents($imagePath));

    $response = $agentClient->run(
        client: $client,
        request: new LLMRequest(
            model: $model,
            conversation: new LLMConversation([
                LLMMessage::createFromUser(new LLMMessageContents([
                    new LLMMessageText(
                        'Create a detailed product description for this item. ' .
                        'Include: product type, colors, materials, style, and key features. ' .
                        'Write in an engaging, SEO-friendly style.'
                    ),
                    new LLMMessageImage('base64', 'image/jpeg', $imageData)
                ]))
            ])
        )
    );

    return $response->getLastText();
}

// Usage
$description = generateProductDescription('/path/to/product.jpg');
echo $description;
```

**Sample Output:**

```
Elegant Modern Leather Sofa

This stunning three-seater sofa features premium full-grain leather upholstery in a rich
cognac brown finish. The mid-century modern design includes clean lines, tapered wooden
legs, and tufted cushioning for both style and comfort...
```

### Screenshot Debugging Assistant

Analyze UI screenshots for bugs and improvements:

```php
<?php
function analyzeUIScreenshot(string $screenshotPath): array {
    global $agentClient, $client, $model;

    $imageData = base64_encode(file_get_contents($screenshotPath));

    $response = $agentClient->run(
        client: $client,
        request: new LLMRequest(
            model: $model,
            conversation: new LLMConversation([
                LLMMessage::createFromUser(new LLMMessageContents([
                    new LLMMessageText(
                        'Analyze this UI screenshot and provide:\n' .
                        '1. Accessibility issues (contrast, font sizes, etc.)\n' .
                        '2. Layout problems (alignment, spacing, overlapping)\n' .
                        '3. Responsive design concerns\n' .
                        '4. UX improvement suggestions\n\n' .
                        'Format as a structured list with severity levels.'
                    ),
                    new LLMMessageImage('base64', 'image/png', $imageData)
                ]))
            ])
        )
    );

    return [
        'analysis' => $response->getLastText(),
        'screenshot' => $screenshotPath,
        'analyzed_at' => date('c')
    ];
}
```

### Chart and Graph Data Extraction

Extract data from visualization images:

```php
<?php
function extractChartData(string $chartImagePath): array {
    global $agentClient, $client, $model;

    $imageData = base64_encode(file_get_contents($chartImagePath));

    $response = $agentClient->run(
        client: $client,
        request: new LLMRequest(
            model: $model,
            conversation: new LLMConversation([
                LLMMessage::createFromUser(new LLMMessageContents([
                    new LLMMessageText(
                        'Extract all data points from this chart/graph and return them as JSON. ' .
                        'Include labels, values, and any legends or annotations. ' .
                        'Format: {"type": "bar|line|pie", "data": [{...}], "title": "...", "axes": {...}}'
                    ),
                    new LLMMessageImage('base64', 'image/png', $imageData)
                ]))
            ])
        )
    );

    return json_decode($response->getLastText(), true);
}

// Usage
$chartData = extractChartData('/path/to/sales-chart.png');
print_r($chartData);
```

### Receipt and Invoice Processing

```php
<?php
function processReceipt(string $receiptImagePath): array {
    global $agentClient, $client, $model;

    $imageData = base64_encode(file_get_contents($receiptImagePath));

    $response = $agentClient->run(
        client: $client,
        request: new LLMRequest(
            model: $model,
            conversation: new LLMConversation([
                LLMMessage::createFromUser(new LLMMessageContents([
                    new LLMMessageText(
                        'Extract structured data from this receipt. Return JSON with: ' .
                        'merchant_name, date, total, tax, items (name, quantity, price), payment_method'
                    ),
                    new LLMMessageImage('base64', 'image/jpeg', $imageData)
                ]))
            ])
        )
    );

    return json_decode($response->getLastText(), true);
}
```

**Sample Usage:**

```php
<?php
$receipt = processReceipt('/uploads/receipt-001.jpg');

// Store in database
$pdo->prepare('INSERT INTO expenses (merchant, date, amount, tax, items) VALUES (?, ?, ?, ?, ?)')
    ->execute([
        $receipt['merchant_name'],
        $receipt['date'],
        $receipt['total'],
        $receipt['tax'],
        json_encode($receipt['items'])
    ]);
```

## PDF Analysis Examples

### Contract Review Assistant

```php
<?php
function reviewContract(string $contractPdfPath): array {
    global $agentClient, $client, $model;

    $pdfData = base64_encode(file_get_contents($contractPdfPath));

    $response = $agentClient->run(
        client: $client,
        request: new LLMRequest(
            model: $model,
            conversation: new LLMConversation([
                LLMMessage::createFromUser(new LLMMessageContents([
                    new LLMMessageText(
                        'Review this contract and provide:\n' .
                        '1. Key terms (parties, dates, amounts)\n' .
                        '2. Obligations and responsibilities\n' .
                        '3. Termination clauses\n' .
                        '4. Potential red flags or unusual clauses\n' .
                        '5. Missing standard clauses\n\n' .
                        'Format as a structured report.'
                    ),
                    new LLMMessagePdf('base64', $pdfData)
                ]))
            ])
        )
    );

    return [
        'summary' => $response->getLastText(),
        'filename' => basename($contractPdfPath),
        'reviewed_at' => date('c'),
        'tokens_used' => $response->getInputTokens() + $response->getOutputTokens()
    ];
}
```

### Research Paper Summarizer

```php
<?php
function summarizeResearchPaper(string $paperPdfPath): string {
    global $agentClient, $client, $model;

    $pdfData = base64_encode(file_get_contents($paperPdfPath));

    $response = $agentClient->run(
        client: $client,
        request: new LLMRequest(
            model: $model,
            conversation: new LLMConversation([
                LLMMessage::createFromUser(new LLMMessageContents([
                    new LLMMessageText(
                        'Summarize this research paper. Include:\n' .
                        '- Research question/hypothesis\n' .
                        '- Methodology\n' .
                        '- Key findings\n' .
                        '- Conclusions\n' .
                        '- Limitations\n\n' .
                        'Write for a technical but non-specialist audience (max 500 words).'
                    ),
                    new LLMMessagePdf('base64', $pdfData)
                ]))
            ])
        )
    );

    return $response->getLastText();
}
```

### Multi-Document Comparison

```php
<?php
function compareDocuments(string $pdf1Path, string $pdf2Path): string {
    global $agentClient, $client, $model;

    $pdf1Data = base64_encode(file_get_contents($pdf1Path));
    $pdf2Data = base64_encode(file_get_contents($pdf2Path));

    $response = $agentClient->run(
        client: $client,
        request: new LLMRequest(
            model: $model,
            conversation: new LLMConversation([
                LLMMessage::createFromUser(new LLMMessageContents([
                    new LLMMessageText('Compare these two documents and highlight:'),
                    new LLMMessagePdf('base64', $pdf1Data),
                    new LLMMessageText('vs'),
                    new LLMMessagePdf('base64', $pdf2Data),
                    new LLMMessageText(
                        'Key differences, additions, removals, and modifications. ' .
                        'Focus on substantive changes, not formatting.'
                    )
                ]))
            ])
        )
    );

    return $response->getLastText();
}

// Usage: Compare contract versions
$diff = compareDocuments(
    '/contracts/version1.pdf',
    '/contracts/version2.pdf'
);
```

### Table Extraction from PDFs

```php
<?php
function extractTablesFromPdf(string $pdfPath): array {
    global $agentClient, $client, $model;

    $pdfData = base64_encode(file_get_contents($pdfPath));

    $response = $agentClient->run(
        client: $client,
        request: new LLMRequest(
            model: $model,
            conversation: new LLMConversation([
                LLMMessage::createFromUser(new LLMMessageContents([
                    new LLMMessageText(
                        'Extract all tables from this PDF. ' .
                        'Return each table as a JSON array with headers and rows. ' .
                        'Format: [{"table_number": 1, "title": "...", "headers": [...], "rows": [[...]]}]'
                    ),
                    new LLMMessagePdf('base64', $pdfData)
                ]))
            ])
        )
    );

    return json_decode($response->getLastText(), true);
}

// Convert to CSV
$tables = extractTablesFromPdf('/reports/annual-report.pdf');
foreach ($tables as $i => $table) {
    $csv = fopen("table_{$i}.csv", 'w');
    fputcsv($csv, $table['headers']);
    foreach ($table['rows'] as $row) {
        fputcsv($csv, $row);
    }
    fclose($csv);
}
```

## Mixed Media Examples

### Document Analysis with Supporting Images

```php
<?php
function analyzeProposal(string $proposalPdf, array $mockupImagePaths): array {
    global $agentClient, $client, $model;

    $pdfData = base64_encode(file_get_contents($proposalPdf));

    // Build contents array
    $contents = [
        new LLMMessageText('Review this proposal document and the accompanying design mockups:'),
        new LLMMessagePdf('base64', $pdfData),
    ];

    foreach ($mockupImagePaths as $i => $imagePath) {
        $imageData = base64_encode(file_get_contents($imagePath));
        $contents[] = new LLMMessageText("Design Mockup " . ($i + 1) . ":");
        $contents[] = new LLMMessageImage('base64', 'image/png', $imageData);
    }

    $contents[] = new LLMMessageText(
        'Provide: 1) Proposal evaluation, 2) Design mockup analysis, ' .
        '3) Alignment between proposal and designs, 4) Recommendations'
    );

    $response = $agentClient->run(
        client: $client,
        request: new LLMRequest(
            model: $model,
            conversation: new LLMConversation([
                LLMMessage::createFromUser(new LLMMessageContents($contents))
            ])
        )
    );

    return [
        'evaluation' => $response->getLastText(),
        'cost' => ($response->getInputPriceUsd() ?? 0) + ($response->getOutputPriceUsd() ?? 0),
        'tokens' => $response->getInputTokens() + $response->getOutputTokens()
    ];
}
```

## Performance and Cost Optimization

### Image Compression

Reduce costs by compressing images before sending:

```php
<?php
function compressImage(string $imagePath, int $maxWidth = 1024): string {
    $image = imagecreatefromstring(file_get_contents($imagePath));
    $width = imagesx($image);
    $height = imagesy($image);

    if ($width > $maxWidth) {
        $newWidth = $maxWidth;
        $newHeight = (int)(($height / $width) * $maxWidth);

        $resized = imagecreatetruecolor($newWidth, $newHeight);
        imagecopyresampled($resized, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        ob_start();
        imagejpeg($resized, null, 85); // 85% quality
        $compressed = ob_get_clean();

        imagedestroy($image);
        imagedestroy($resized);

        return base64_encode($compressed);
    }

    return base64_encode(file_get_contents($imagePath));
}

// Usage
$imageData = compressImage('/path/to/large-image.jpg', 1024);
```

### Caching Multimodal Requests

```php
<?php
use Soukicz\Llm\Cache\FileCache;

$cache = new FileCache(sys_get_temp_dir());

// Identical multimodal requests will use cache
// Saves API costs for repeated analyses
$client = new AnthropicClient('sk-xxxxx', $cache);
```

### Prompt Caching for Large Documents

Use Claude's prompt caching to reduce costs for repeated PDF analysis:

```php
<?php
// Mark large PDFs as cacheable
$message = LLMMessage::createFromUser(new LLMMessageContents([
    new LLMMessageText('Analyze this document:'),
    new LLMMessagePdf('base64', $pdfData, cached: true)  // Cache this PDF
]));

// Subsequent requests with the same PDF will use cached version
// Reduces costs by ~90% for the cached portion
```

## Error Handling

### File Size Validation

```php
<?php
function validateFileSize(string $filePath, int $maxSizeMB = 10): void {
    $sizeMB = filesize($filePath) / 1024 / 1024;

    if ($sizeMB > $maxSizeMB) {
        throw new Exception(
            "File too large: {$sizeMB}MB (max {$maxSizeMB}MB). " .
            "Consider compressing or splitting the file."
        );
    }
}

// Usage
try {
    validateFileSize($imagePath, 5);
    $imageData = base64_encode(file_get_contents($imagePath));
    // ... process image
} catch (Exception $e) {
    error_log($e->getMessage());
}
```

### Provider Compatibility Check

```php
<?php
function supportsMultimodal($model): bool {
    // Check if model supports images/PDFs
    return $model instanceof AnthropicClaude45Sonnet ||
           $model instanceof GPT5 ||
           $model instanceof Gemini25Pro;
}

if (!supportsMultimodal($model)) {
    throw new Exception('Selected model does not support multimodal input');
}
```

## Best Practices

1. **Compress images** before sending to reduce costs and latency
2. **Use caching** for repeated analyses of the same documents
3. **Validate file sizes** to avoid API errors
4. **Choose the right format**: JPEG for photos, PNG for screenshots/diagrams
5. **Clear prompts**: Specify exactly what you want extracted or analyzed
6. **Structure outputs**: Request JSON for easy parsing and storage
7. **Monitor costs**: Track tokens for multimodal requests (images use more tokens)

## See Also

- [Multimodal Guide](../guides/multimodal.md) - Technical details and provider support
- [Tools & Function Calling](tools-and-function-calling.md) - Combine tools with multimodal input
- [State Management](state-management.md) - Saving multimodal conversations
