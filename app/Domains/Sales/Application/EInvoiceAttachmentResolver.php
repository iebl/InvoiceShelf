<?php

namespace App\Domains\Sales\Application;

use App\Domains\Sales\Models\Invoice;
use App\Platform\Pdf\Rendering\FacturXAttachment;
use Throwable;

/**
 * Decides whether an invoice's PDF becomes a Hybrid PDF, and with which XML.
 *
 * The XML is embedded only when the instance runs the Gotenberg driver, the
 * company has E-Invoicing switched on and the builder returned valid XML.
 * Otherwise this returns null and the ordinary PDF is produced. Every delivery
 * path (download, email attachment, customer portal) goes through the
 * invoice's PDF generation, so they all get the same decision.
 *
 * @see docs/architecture/0005-einvoice-gotenberg-container-horstoeko-xml.md
 */
class EInvoiceAttachmentResolver
{
    public function __construct(
        private readonly EInvoiceSettings $settings,
        private readonly EInvoiceBuilder $builder,
    ) {}

    /**
     * The Factur-X attachment for an invoice, or null when the ordinary PDF
     * applies.
     */
    public function resolve(Invoice $invoice): ?FacturXAttachment
    {
        // An error here must not block billing: report it and fall back to
        // the ordinary PDF.
        try {
            if (! $this->settings->isEnabledFor($invoice->company_id)) {
                return null;
            }

            $result = $this->builder->build($invoice);

            if (! $result->isValid()) {
                return null;
            }

            return FacturXAttachment::en16931((string) $result->xml());
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }
}
