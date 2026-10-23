<?php

namespace App\Platform\Pdf\Rendering;

use InvalidArgumentException;

/**
 * The e-invoice XML a rendered PDF carries, which makes it a Hybrid PDF.
 *
 * The PDF platform is handed finished XML and its profile and knows nothing
 * about invoices; the Sales domain decides which documents get one (see
 * EInvoiceAttachmentResolver). Only a driver that builds a PDF/A-3 container can
 * embed it: Gotenberg does, dompdf ignores it (ADR 0005).
 *
 * @see docs/architecture/0005-einvoice-gotenberg-container-horstoeko-xml.md
 */
final class FacturXAttachment
{
    /**
     * The file name ZUGFeRD/Factur-X readers look for inside the PDF.
     */
    public const FILENAME = 'factur-x.xml';

    /**
     * The archival conformance a Hybrid PDF is built to. Embedding a file is
     * only permitted from PDF/A-3 onwards, and `b` (visual conformance) is the
     * level the e-invoicing specifications ask for.
     */
    public const PDFA_CONFORMANCE = 'PDF/A-3b';

    /**
     * Profile EN 16931, spelled the way the container expects the Factur-X
     * conformance level to be spelled.
     */
    public const PROFILE_EN_16931 = 'EN 16931';

    private function __construct(
        public readonly string $xml,
        public readonly string $profile,
    ) {}

    /**
     * An attachment carrying EN 16931 CII XML — the only profile v1 issues.
     */
    public static function en16931(string $xml): self
    {
        if (trim($xml) === '') {
            throw new InvalidArgumentException('A Factur-X attachment cannot carry empty XML.');
        }

        return new self($xml, self::PROFILE_EN_16931);
    }
}
