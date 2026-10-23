<?php

namespace App\Domains\Sales\Application;

use App\Domains\Accounts\Models\Company;
use App\Domains\Sales\Models\Invoice;

/**
 * The E-Invoice Ready verdict for a company and the fallback verdict for one
 * invoice, both taken from the builder's missing-requirements check.
 */
class EInvoiceReadiness
{
    public function __construct(
        private readonly EInvoiceBuilder $builder,
        private readonly EInvoiceSettings $settings,
    ) {}

    /**
     * The E-Invoice Ready verdict for a company's master data.
     *
     * Readiness is reported whether or not the company has switched the feature
     * on: it is what tells an owner the master data is complete enough to
     * enable it in the first place.
     *
     * @return array{available: bool, enabled: bool, required_pdf_driver: string, ready: bool, missing_requirements: list<string>}
     */
    public function forCompany(Company $company): array
    {
        return $this->report($company->id, array_map(
            static fn (EInvoiceRequirement $requirement): string => $requirement->value,
            $this->builder->missingCompanyRequirements($company)
        ));
    }

    /**
     * Whether an invoice would become an E-Invoice, and what stands in the way.
     *
     * `fallback` is the decision the PDF pipeline makes: an ordinary PDF plus a
     * warning. It only ever holds for a company that issues E-Invoices at all,
     * so an incomplete invoice of a company with the feature off is reported as
     * not ready without claiming anything fell back.
     *
     * @return array{available: bool, enabled: bool, required_pdf_driver: string, ready: bool, fallback: bool, missing_requirements: list<string>}
     */
    public function forInvoice(Invoice $invoice): array
    {
        $result = $this->builder->build($invoice);

        $report = $this->report($invoice->company_id, $result->missingRequirementKeys());

        return [
            ...$report,
            'fallback' => $report['enabled'] && ! $report['ready'],
        ];
    }

    /**
     * @param  list<string>  $missingRequirements
     * @return array{available: bool, enabled: bool, required_pdf_driver: string, ready: bool, missing_requirements: list<string>}
     */
    private function report(mixed $companyId, array $missingRequirements): array
    {
        return [
            ...$this->settings->context(),
            'enabled' => $this->settings->isEnabledFor($companyId),
            'ready' => $missingRequirements === [],
            'missing_requirements' => $missingRequirements,
        ];
    }
}
