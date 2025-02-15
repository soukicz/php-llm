<?php

namespace Soukicz\PhpLlm;

use Soukicz\PhpLlm\Message\LLMMessageImage;
use Soukicz\PhpLlm\Message\LLMMessagePdf;
use Soukicz\PhpLlm\Message\LLMMessageText;
use Soukicz\PhpLlm\Message\LLMMessageToolResult;
use Soukicz\PhpLlm\Message\LLMMessageToolUse;

class MarkdownFormatter {
    public function responseToMarkdown(LLMResponse $response): string {
        $markdown = '';
        foreach ($response->getMessages() as $message) {
            if ($message->isUser()) {
                $markdown .= '#### User:' . "\n";
            } elseif ($message->isAssistant()) {
                $markdown .= '#### Response:' . "\n";
            } else {
                throw new \RuntimeException('Unknown message role');
            }
            foreach ($message->getContents() as $content) {
                if ($content instanceof LLMMessageText) {
                    $markdown .= $content->getText();
                } elseif ($content instanceof LLMMessageImage) {
                    $markdown .= '**Image** (' . $content->getMediaType() . ' ' . $this->formatByteSize(strlen(base64_decode($content->getData()))) . ')' . "\n";
                } elseif ($content instanceof LLMMessagePdf) {
                    $markdown .= '**PDF** ' . $this->formatByteSize(strlen(base64_decode($content->getData()))) . ')';
                } elseif ($content instanceof LLMMessageToolUse) {
                    $markdown .= '**Tool use: ** ' . $content->getName() . ' (' . $content->getId() . ')' . "\n";
                    $markdown .= "```json\n";
                    $markdown .= json_encode($content->getInput(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n";
                    $markdown .= "```\n";
                } elseif ($content instanceof LLMMessageToolResult) {
                    $markdown .= "**Tool use: ** " . $content->getId() . "\n";
                    $markdown .= "```\n";
                    if (is_string($content->getContent())) {
                        $markdown .= $content->getContent() . "\n";
                    } else {
                        $markdown .= json_encode($content->getContent(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n";
                    }
                    $markdown .= $content->getContent() . "\n";
                    $markdown .= "```\n";
                } else {
                    throw new \RuntimeException('Unknown message content type');
                }
            }
        }

        $markdown .= "\n\n";
        $markdown .= '----------------------';
        $markdown .= "\n\n";

        $price = $response->getInputPriceUsd() + $response->getOutputPriceUsd();
        $markdown .= "##### Total stats\n\n";
        $markdown .= 'Finished in ' . number_format($response->getTotalTimeMs() / 1000, 3, '.') . 's' .
            ', prompt tokens: ' . $response->getInputTokens() .
            ', completion tokens: ' . $response->getOutputTokens() .
            ', maximum completion tokens: ' . $response->getMaximumOutputTokens() .
            ', total tokens: ' . ($response->getInputTokens() + $response->getOutputTokens()) .
            ', price: ' . $this->formatPrice($price) .
            "\n\n";

        return $markdown;

    }

    private function formatPrice(float $price): string {
        return '$' . number_format(round($price, 3), 3) . ' (' . number_format(round($price * 24, 2), 2) . ' Kč)';
    }

    private function formatByteSize(int $size): string {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $unit = 0;
        while ($size >= 1024 && $unit < count($units) - 1) {
            $size /= 1024;
            $unit++;
        }

        return round($size, 2) . ' ' . $units[$unit];
    }
}
