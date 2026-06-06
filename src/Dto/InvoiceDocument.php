<?php

declare(strict_types=1);

namespace Fewohbee\PaymentCore\Dto;

/**
 * A downloaded invoice document (e.g. a ZUGFeRD PDF). `content` holds the raw
 * bytes, ready to be attached to an email or written to disk.
 */
final readonly class InvoiceDocument
{
    public function __construct(
        public string $filename,
        public string $contentType,
        public string $content,
    ) {
    }
}
