<?php

namespace App\Platform\Pdf\Rendering;

interface PdfDriver
{
    /**
     * @param  array<string, string>  $metadata  Document properties written into
     *                                           the file: Title, Author, Subject,
     *                                           Keywords, Creator. Both drivers
     *                                           accept the same key names.
     * @param  PdfPageSetup|null  $page  Page geometry to render at. Defaults to
     *                                   the configured one; the reports pass
     *                                   PdfPageSetup::forReports() because they
     *                                   carry no inset of their own.
     * @param  FacturXAttachment|null  $eInvoice  E-invoice XML to embed, which
     *                                            makes the result a Hybrid PDF.
     *                                            Null is the ordinary PDF, which
     *                                            is also what the Fallback
     *                                            produces. Only a driver that can
     *                                            build a PDF/A-3 container honours
     *                                            it (ADR 0005).
     */
    public function loadView(
        string $template,
        array $metadata = [],
        ?PdfPageSetup $page = null,
        ?FacturXAttachment $eInvoice = null,
    ): ResponseStream;
}
