<?php

namespace App\Domains\Sales\Application;

use App\Domains\Accounts\Models\CompanySetting;

/**
 * The per-company E-Invoice configuration: the enable switch plus the seller
 * bank details EN 16931 asks for, stored through the ordinary company settings
 * key-value mechanism.
 *
 * Issuing E-Invoices means producing a Hybrid PDF, which only the Gotenberg PDF
 * driver can build (ADR 0005). The instance-global driver therefore gates the
 * whole feature: a company that flipped its switch on before the driver changed
 * does not silently keep issuing E-Invoices.
 */
class EInvoiceSettings
{
    /**
     * The instance-global PDF driver E-Invoicing requires.
     */
    public const REQUIRED_PDF_DRIVER = 'gotenberg';

    public const ENABLED = 'einvoice_enabled';

    public const IBAN = 'einvoice_iban';

    public const BIC = 'einvoice_bic';

    public const BANK_NAME = 'einvoice_bank_name';

    /**
     * Every company setting key this tab owns.
     *
     * @var list<string>
     */
    public const KEYS = [
        self::ENABLED,
        self::IBAN,
        self::BIC,
        self::BANK_NAME,
    ];

    /**
     * Whether this instance can produce Hybrid PDFs at all.
     */
    public function isAvailable(): bool
    {
        return config('pdf.driver') === self::REQUIRED_PDF_DRIVER;
    }

    /**
     * Whether a company issues E-Invoices — its own switch and the instance
     * driver requirement both have to hold.
     */
    public function isEnabledFor(mixed $companyId): bool
    {
        return $this->isAvailable()
            && CompanySetting::getSetting(self::ENABLED, $companyId) === 'YES';
    }

    /**
     * What the E-Invoice settings tab needs to decide whether to enable its
     * controls and which requirement to name when it does not.
     *
     * @return array{available: bool, required_pdf_driver: string}
     */
    public function context(): array
    {
        return [
            'available' => $this->isAvailable(),
            'required_pdf_driver' => self::REQUIRED_PDF_DRIVER,
        ];
    }
}
