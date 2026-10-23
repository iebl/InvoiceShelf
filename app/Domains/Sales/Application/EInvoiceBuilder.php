<?php

namespace App\Domains\Sales\Application;

use App\Domains\Accounts\Models\Company;
use App\Domains\Accounts\Models\CompanySetting;
use App\Domains\Catalog\Models\Unit;
use App\Domains\Contacts\Models\Address;
use App\Domains\Sales\Models\Invoice;
use App\Domains\Sales\Models\InvoiceItem;
use App\Domains\Taxation\Models\Tax;
use App\Domains\Taxation\Models\TaxType;
use Carbon\Carbon;
use DOMDocument;
use horstoeko\zugferd\codelists\ZugferdAllowanceCodes;
use horstoeko\zugferd\codelists\ZugferdInvoiceType;
use horstoeko\zugferd\codelists\ZugferdVatTypeCodes;
use horstoeko\zugferd\ZugferdDocumentBuilder;
use horstoeko\zugferd\ZugferdProfiles;
use horstoeko\zugferd\ZugferdXsdValidator;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Turns an invoice into EN 16931 CII XML, or into the list of requirements it
 * does not meet yet.
 *
 * The Hybrid PDF pipeline, the E-Invoice Ready check and the fallback decision
 * all read this one answer. Amounts are the invoice's stored cent values
 * divided by 100 and are not recalculated; the summation is assembled so that
 * it adds up within the document (BT-106 - BT-107 = BT-109, BT-109 + BT-110 =
 * BT-112). What EN 16931 cannot express (several taxes on one line,
 * tax-inclusive prices, fixed-amount taxes, a rate that contradicts its
 * category) is reported as a requirement, so the invoice falls back to an
 * ordinary PDF.
 *
 * @see docs/architecture/0005-einvoice-gotenberg-container-horstoeko-xml.md
 */
class EInvoiceBuilder
{
    /**
     * A syntactically usable IBAN: country, check digits, then the BBAN,
     * 15 to 34 characters in total.
     */
    private const IBAN_PATTERN = '/^[A-Z]{2}[0-9]{2}[A-Z0-9]{11,30}$/';

    /**
     * Reason text put on a discount, in the document's own vocabulary rather
     * than the seller's locale — EN 16931 pairs it with the coded reason.
     */
    private const DISCOUNT_REASON = 'Discount';

    /** The reason given for a deduction line that has no name to use. */
    private const DEDUCTION_REASON = 'Deduction';

    /**
     * Build the E-Invoice XML for an invoice, or report what it still needs.
     */
    public function build(Invoice $invoice): EInvoiceResult
    {
        $invoice->loadMissing([
            'company.address.country',
            'currency',
            'customer.billingAddress.country',
            'items.taxes.taxType',
            'taxes.taxType',
        ]);

        $missing = $this->missingRequirements($invoice);

        if ($missing !== []) {
            return EInvoiceResult::incomplete($missing);
        }

        try {
            $document = $this->compose($invoice);

            if ((new ZugferdXsdValidator($document))->validate()->hasValidationErrors()) {
                return EInvoiceResult::incomplete([EInvoiceRequirement::ValidCiiXml]);
            }

            $xml = $document->getContent();

            // The XSD validator reports on a document it parses itself, so it
            // stays silent about content that never parsed at all. Reading the
            // serialized XML back is what makes "valid" mean the bytes handed
            // out are the ones that were validated.
            if (! $this->isWellFormed($xml)) {
                return EInvoiceResult::incomplete([EInvoiceRequirement::ValidCiiXml]);
            }

            return EInvoiceResult::valid($xml);
        } catch (Throwable $exception) {
            report($exception);

            return EInvoiceResult::incomplete([EInvoiceRequirement::ValidCiiXml]);
        }
    }

    /**
     * The requirements a company's own master data does not meet yet — what the
     * E-Invoice Ready indicator reports.
     *
     * These are the seller-side subset of {@see self::missingRequirements()}:
     * the gaps that hold for every invoice the company will ever issue, and the
     * only ones it can close in its own settings. An empty list therefore means
     * ready, not that any particular invoice will pass.
     *
     * @return list<EInvoiceRequirement>
     */
    public function missingCompanyRequirements(Company $company): array
    {
        $company->loadMissing('address.country');

        $missing = [];

        $this->collectSellerRequirements($missing, $company);

        return $missing;
    }

    /**
     * Whether a string is XML a parser accepts.
     */
    private function isWellFormed(string $xml): bool
    {
        if ($xml === '') {
            return false;
        }

        $previous = libxml_use_internal_errors(true);
        $wellFormed = (new DOMDocument)->loadXML($xml) !== false;
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $wellFormed;
    }

    /**
     * Every requirement the invoice does not meet, in a stable order.
     *
     * @return list<EInvoiceRequirement>
     */
    private function missingRequirements(Invoice $invoice): array
    {
        $missing = [];

        $customer = $invoice->customer;
        $billingAddress = $customer?->billingAddress;

        $this->requireValue($missing, EInvoiceRequirement::InvoiceNumber, $invoice->invoice_number);
        $this->requireValue($missing, EInvoiceRequirement::InvoiceDate, $invoice->invoice_date);
        $this->requireValue($missing, EInvoiceRequirement::InvoiceCurrency, $invoice->currency?->code);

        $this->collectSellerRequirements($missing, $invoice->company);

        $this->requireValue($missing, EInvoiceRequirement::BuyerName, $customer?->name);
        $this->requireValue($missing, EInvoiceRequirement::BuyerCountry, $billingAddress?->country?->code);

        if ($invoice->tax_included) {
            $this->add($missing, EInvoiceRequirement::NetPrices);
        }

        if ($this->positions($invoice)->isEmpty()) {
            $this->add($missing, EInvoiceRequirement::LineItems);
        }

        foreach ($invoice->items as $item) {
            if (blank($item->name)) {
                $this->add($missing, EInvoiceRequirement::LineItemName);
            }

            if ((int) $item->price < 0 && (int) $item->total >= 0) {
                $this->add($missing, EInvoiceRequirement::NegativePrice);
            }
        }

        foreach ($this->taxesPerLine($invoice) as $lineTaxes) {
            if ($lineTaxes->isEmpty()) {
                $this->add($missing, EInvoiceRequirement::InvoiceTax);
            } elseif ($lineTaxes->count() > 1) {
                $this->add($missing, EInvoiceRequirement::SingleTaxRatePerLine);
            }
        }

        foreach ($this->applicableTaxes($invoice) as $tax) {
            $taxType = $tax->taxType;

            if ($taxType === null) {
                $this->add($missing, EInvoiceRequirement::TaxCategoryCode);

                continue;
            }

            if ($tax->calculation_type === 'fixed') {
                $this->add($missing, EInvoiceRequirement::TaxRatePercentage);
            } elseif (! $this->rateFitsCategory($taxType->tax_category_code, (float) $tax->percent)) {
                $this->add($missing, EInvoiceRequirement::TaxRateForCategory);
            }

            if (
                in_array($taxType->tax_category_code, TaxType::EXEMPT_TAX_CATEGORY_CODES, true)
                && blank($taxType->tax_exemption_reason)
            ) {
                $this->add($missing, EInvoiceRequirement::TaxExemptionReason);
            }
        }

        return $missing;
    }

    /**
     * Record every requirement the seller's own master data does not meet: its
     * name, postal address, tax registration and the IBAN from the E-Invoice
     * settings.
     *
     * @param  list<EInvoiceRequirement>  $missing
     */
    private function collectSellerRequirements(array &$missing, ?Company $company): void
    {
        $address = $company?->address;

        $this->requireValue($missing, EInvoiceRequirement::SellerName, $company?->name);
        $this->requireValue($missing, EInvoiceRequirement::SellerStreet, $address?->address_street_1);
        $this->requireValue($missing, EInvoiceRequirement::SellerPostcode, $address?->zip);
        $this->requireValue($missing, EInvoiceRequirement::SellerCity, $address?->city);
        $this->requireValue($missing, EInvoiceRequirement::SellerCountry, $address?->country?->code);

        // BR-CO-26 accepts only BT-29, BT-30 or BT-31 as the seller's
        // identification, and of those the mapping writes just the VAT
        // identifier (BT-31). A national tax number becomes BT-32, which the
        // rule does not count — so a tax number alone must read as not ready,
        // or the XML passes the XSD here and fails Schematron at the buyer.
        if (blank($company?->vat_id)) {
            $this->add($missing, EInvoiceRequirement::SellerTaxRegistration);
        }

        if ($this->sellerIban($company?->id) === null) {
            $this->add($missing, EInvoiceRequirement::SellerIban);
        }
    }

    /**
     * Record a requirement unless the value that satisfies it is present.
     *
     * @param  list<EInvoiceRequirement>  $missing
     */
    private function requireValue(array &$missing, EInvoiceRequirement $requirement, mixed $value): void
    {
        if (blank($value)) {
            $this->add($missing, $requirement);
        }
    }

    /**
     * Record a requirement once, however many times it is hit.
     *
     * @param  list<EInvoiceRequirement>  $missing
     */
    private function add(array &$missing, EInvoiceRequirement $requirement): void
    {
        if (! in_array($requirement, $missing, true)) {
            $missing[] = $requirement;
        }
    }

    /**
     * The taxes that apply to each line, whichever way the invoice taxes.
     *
     * A per-invoice tax applies to every line, so each line sees the document's
     * own taxes — that is what EN 16931 asks a line to state (BG-30).
     *
     * @return list<Collection<int, Tax>>
     */
    private function taxesPerLine(Invoice $invoice): array
    {
        $perItem = $this->taxesPerItem($invoice);
        $lineTaxes = [];

        foreach ($this->lines($invoice) as $item) {
            $lineTaxes[] = $perItem ? $item->taxes : $invoice->taxes;
        }

        return $lineTaxes;
    }

    /**
     * The invoice's line items in a fixed order — the line numbers (BT-126)
     * they are given have to mean the same thing on every render.
     *
     * @return Collection<int, InvoiceItem>
     */
    private function lines(Invoice $invoice): Collection
    {
        return $invoice->items->sortBy('id')->values();
    }

    /**
     * The lines that become invoice lines (BG-25): everything but the
     * deductions.
     *
     * @return Collection<int, InvoiceItem>
     */
    private function positions(Invoice $invoice): Collection
    {
        return $this->lines($invoice)
            ->reject(fn (InvoiceItem $item): bool => $this->isDeduction($item))
            ->values();
    }

    /**
     * A line whose amount is negative — a down payment already settled, say.
     * EN 16931 allows no negative net price (BR-27), so it travels as a
     * document-level allowance (BG-20) in the VAT group it was taxed in.
     */
    private function isDeduction(InvoiceItem $item): bool
    {
        return (int) $item->total < 0;
    }

    /**
     * Whether a percentage rate suits a Tax Category Code: standard-rated is
     * above 0 %, the zero-rated, exempt, reverse-charge, intra-community,
     * export and out-of-scope categories are exactly 0 %. The Canary Islands
     * and Ceuta/Melilla categories carry their own rates and are left alone.
     */
    private function rateFitsCategory(?string $category, float $percent): bool
    {
        return match (true) {
            $category === TaxType::TAX_CATEGORY_CODE_STANDARD => $percent > 0,
            $category === TaxType::TAX_CATEGORY_CODE_ZERO_RATED,
            in_array($category, TaxType::EXEMPT_TAX_CATEGORY_CODES, true) => $percent == 0,
            default => true,
        };
    }

    /**
     * Every tax the invoice actually charges, without duplicates per line.
     *
     * @return Collection<int, Tax>
     */
    private function applicableTaxes(Invoice $invoice): Collection
    {
        if ($this->taxesPerItem($invoice)) {
            return $invoice->items->flatMap(static fn (InvoiceItem $item): Collection => $item->taxes);
        }

        return $invoice->taxes;
    }

    /**
     * Whether the invoice taxes each line separately rather than as a whole.
     */
    private function taxesPerItem(Invoice $invoice): bool
    {
        return $invoice->tax_per_item === 'YES';
    }

    /**
     * Assemble the CII document. Only ever called once the invoice meets every
     * requirement, so the data it reads is known to be there.
     */
    private function compose(Invoice $invoice): ZugferdDocumentBuilder
    {
        $document = ZugferdDocumentBuilder::createNew(ZugferdProfiles::PROFILE_EN16931);

        $this->writeDocumentInformation($document, $invoice);
        $this->writeSeller($document, $invoice);
        $this->writeBuyer($document, $invoice);
        $this->writePaymentDetails($document, $invoice);
        $this->writeLines($document, $invoice);
        $this->writeTaxesAndSummation($document, $invoice);

        return $document;
    }

    private function writeDocumentInformation(ZugferdDocumentBuilder $document, Invoice $invoice): void
    {
        $document->setDocumentInformation(
            (string) $invoice->invoice_number,
            $invoice->isCreditNote() ? ZugferdInvoiceType::CREDITNOTE : ZugferdInvoiceType::INVOICE,
            Carbon::parse($invoice->invoice_date),
            strtoupper((string) $invoice->currency->code),
        );

        // The invoice model carries no delivery date of its own, but the
        // delivery block must not stay empty: Peppol rejects empty elements
        // (PEPPOL-EN16931-R008) and German UStG §14 expects a Leistungsdatum.
        // Until the model can state one, the invoice date is the delivery
        // date (BT-72) — the reading recipients apply to an invoice that
        // names no other.
        $document->setDocumentSupplyChainEvent(Carbon::parse($invoice->invoice_date));

        if (filled($invoice->reference_number)) {
            $document->setDocumentBuyerReference((string) $invoice->reference_number);
        }

        $notes = $this->plainText($invoice->getNotes());

        if ($notes !== '') {
            $document->addDocumentNote($notes);
        }
    }

    private function writeSeller(ZugferdDocumentBuilder $document, Invoice $invoice): void
    {
        $company = $invoice->company;
        $address = $company->address;

        $document->setDocumentSeller($company->name);
        $document->setDocumentSellerAddress(...$this->postalAddress($address));

        if (filled($company->vat_id)) {
            $document->addDocumentSellerVATRegistrationNumber((string) $company->vat_id);
        }

        if (filled($company->tax_id)) {
            $document->addDocumentSellerTaxNumber((string) $company->tax_id);
        }
    }

    private function writeBuyer(ZugferdDocumentBuilder $document, Invoice $invoice): void
    {
        $customer = $invoice->customer;
        $address = $customer->billingAddress;

        $document->setDocumentBuyer($customer->name);

        if (filled($customer->company_name)) {
            $document->setDocumentBuyerLegalOrganisation(null, null, (string) $customer->company_name);
        }

        $document->setDocumentBuyerAddress(...$this->postalAddress($address));

        // The buyer's `tax_id` is an untyped field: it may hold a VAT
        // identifier (BT-48) or a national tax number, and EN 16931 needs the
        // scheme to be right. Guessing one would misstate the buyer, so it is
        // left out until the data model tells the two apart.
    }

    /**
     * An address as the arguments a CII postal address takes: street, second
     * line, third line, post code, city, country code, subdivision.
     *
     * @return array{?string, ?string, null, ?string, ?string, string, ?string}
     */
    private function postalAddress(Address $address): array
    {
        return [
            $address->address_street_1,
            $address->address_street_2,
            null,
            $address->zip,
            $address->city,
            strtoupper((string) $address->country->code),
            $address->state,
        ];
    }

    /**
     * The bank details as the payment means EN 16931 asks for. The bank name
     * from the E-Invoice settings stays behind: BG-17 identifies the
     * institution by its BIC alone, so the name belongs on the PDF only.
     */
    private function writePaymentDetails(ZugferdDocumentBuilder $document, Invoice $invoice): void
    {
        $document->addDocumentPaymentMeanToCreditTransfer(
            (string) $this->sellerIban($invoice->company_id),
            $invoice->company->name,
            null,
            $this->sellerBic($invoice->company_id),
        );

        if (filled($invoice->due_date)) {
            $document->addDocumentPaymentTerm(null, Carbon::parse($invoice->due_date));
        }
    }

    private function writeLines(ZugferdDocumentBuilder $document, Invoice $invoice): void
    {
        $unitCodes = $this->unitCodes($invoice);
        $lineNumber = 0;

        foreach ($this->positions($invoice) as $item) {
            $lineNumber++;
            $tax = $this->lineTax($invoice, $item);
            $description = $this->plainText($item->description);

            $document->addNewPosition((string) $lineNumber);
            $document->setDocumentPositionProductDetails(
                (string) $item->name,
                $description === '' ? null : $description,
            );
            $document->setDocumentPositionNetPrice($this->amount($item->price));
            $document->setDocumentPositionQuantity(
                (float) $item->quantity,
                $unitCodes[$this->unitKey($item->unit_name)] ?? Unit::DEFAULT_UNIT_CODE,
            );
            $document->addDocumentPositionTax(
                $this->categoryCode($tax),
                ZugferdVatTypeCodes::VALUE_ADDED_TAX,
                $this->ratePercent($tax),
            );

            if ((int) $item->discount_val > 0) {
                $document->addDocumentPositionAllowanceCharge(
                    $this->amount($item->discount_val),
                    false,
                    null,
                    null,
                    ZugferdAllowanceCodes::DISCOUNT,
                    self::DISCOUNT_REASON,
                );
            }

            $document->setDocumentPositionLineSummation($this->amount($item->total));
        }
    }

    /**
     * Write the VAT breakdown, the document-level discount that belongs to it,
     * and the summation tying both to the invoice's totals.
     */
    private function writeTaxesAndSummation(ZugferdDocumentBuilder $document, Invoice $invoice): void
    {
        $groups = $this->vatGroups($invoice);
        $lineTotal = array_sum(array_column($groups, 'lineTotal'));
        $discount = max(0, (int) $invoice->discount_val);
        $allowances = $this->allocate($discount, array_column($groups, 'lineTotal'));

        $deducted = 0;
        $taxTotal = 0;
        $taxBasisTotal = 0;

        foreach ($groups as $index => $group) {
            $deduction = array_sum(array_column($group['deductions'], 'amount'));
            $basis = $group['lineTotal'] - $deduction - $allowances[$index];
            $deducted += $deduction;
            $taxBasisTotal += $basis;
            $taxTotal += $group['amount'];

            $document->addDocumentTax(
                $group['category'],
                ZugferdVatTypeCodes::VALUE_ADDED_TAX,
                $this->amount($basis),
                $this->amount($group['amount']),
                $group['percent'],
                $group['exemptionReason'],
            );

            foreach ($group['deductions'] as $entry) {
                $this->writeAllowance($document, $group, $entry['amount'], $entry['reason'] ?: self::DEDUCTION_REASON);
            }

            if ($allowances[$index] > 0) {
                $this->writeAllowance($document, $group, $allowances[$index], self::DISCOUNT_REASON);
            }
        }

        $grandTotal = $taxBasisTotal + $taxTotal;
        $prepaid = max(0, (int) $invoice->total - (int) $invoice->due_amount);

        $document->setDocumentSummation(
            $this->amount($grandTotal),
            $this->amount($grandTotal - $prepaid),
            $this->amount($lineTotal),
            $this->amount(0),
            $this->amount($discount + $deducted),
            $this->amount($taxBasisTotal),
            $this->amount($taxTotal),
            null,
            $this->amount($prepaid),
        );
    }

    /**
     * One document-level allowance (BG-20) in a VAT group.
     *
     * @param  array{category: string, percent: float}  $group
     */
    private function writeAllowance(ZugferdDocumentBuilder $document, array $group, int $amount, string $reason): void
    {
        $document->addDocumentAllowanceCharge(
            $this->amount($amount),
            false,
            $group['category'],
            ZugferdVatTypeCodes::VALUE_ADDED_TAX,
            $group['percent'],
            null,
            null,
            null,
            null,
            null,
            ZugferdAllowanceCodes::DISCOUNT,
            $reason,
        );
    }

    /**
     * The VAT breakdown groups, one per distinct Tax Category Code and rate,
     * in the order the lines introduce them.
     *
     * `lineTotal` is the sum of the net amounts of the invoice lines the group
     * covers. Lines with a negative amount are not invoice lines: each is a
     * `deductions` entry of the group it was taxed in, written as an allowance,
     * and `amount` (the VAT) counts them with their negative sign.
     *
     * @return list<array{category: string, percent: float, exemptionReason: ?string, lineTotal: int, deductions: list<array{reason: string, amount: int}>, amount: int}>
     */
    private function vatGroups(Invoice $invoice): array
    {
        $groups = [];

        foreach ($this->lines($invoice) as $item) {
            $tax = $this->lineTax($invoice, $item);
            $category = $this->categoryCode($tax);
            $percent = $this->ratePercent($tax);
            $key = $category.'@'.$percent;

            $groups[$key] ??= [
                'category' => $category,
                'percent' => $percent,
                'exemptionReason' => $this->exemptionReason($tax),
                'lineTotal' => 0,
                'deductions' => [],
                'amount' => 0,
            ];

            if ($this->isDeduction($item)) {
                $groups[$key]['deductions'][] = [
                    'reason' => $this->plainText($item->name),
                    'amount' => abs((int) $item->total),
                ];
            } else {
                $groups[$key]['lineTotal'] += (int) $item->total;
            }

            if ($this->taxesPerItem($invoice)) {
                $groups[$key]['amount'] += (int) $tax->amount;
            }
        }

        if (! $this->taxesPerItem($invoice)) {
            // One document-level tax charged on the whole invoice: its stored
            // amount belongs to the single group all lines share.
            foreach (array_keys($groups) as $key) {
                $groups[$key]['amount'] = (int) $invoice->taxes->first()->amount;
            }
        }

        return array_values($groups);
    }

    /**
     * Split an amount across weights, in whole cents and without losing one.
     *
     * @param  list<int>  $weights
     * @return list<int>
     */
    private function allocate(int $amount, array $weights): array
    {
        $total = array_sum($weights);
        $remaining = $amount;
        $shares = [];
        $last = count($weights) - 1;

        foreach ($weights as $index => $weight) {
            if ($index === $last) {
                $shares[] = $remaining;

                break;
            }

            $share = $total === 0 ? 0 : (int) round($amount * $weight / $total);
            $shares[] = $share;
            $remaining -= $share;
        }

        return $shares;
    }

    /**
     * The single tax that applies to a line.
     */
    private function lineTax(Invoice $invoice, InvoiceItem $item): Tax
    {
        return $this->taxesPerItem($invoice) ? $item->taxes->first() : $invoice->taxes->first();
    }

    private function categoryCode(Tax $tax): string
    {
        return (string) $tax->taxType->tax_category_code;
    }

    private function ratePercent(Tax $tax): float
    {
        return round((float) $tax->percent, 2);
    }

    private function exemptionReason(Tax $tax): ?string
    {
        if (! in_array($tax->taxType->tax_category_code, TaxType::EXEMPT_TAX_CATEGORY_CODES, true)) {
            return null;
        }

        return $this->plainText($tax->taxType->tax_exemption_reason) ?: null;
    }

    /**
     * The Unit Code of every unit the invoice's lines name, keyed for lookup.
     *
     * @return array<string, string>
     */
    private function unitCodes(Invoice $invoice): array
    {
        $names = $invoice->items
            ->pluck('unit_name')
            ->filter(static fn (?string $name): bool => filled($name))
            ->unique()
            ->values();

        if ($names->isEmpty()) {
            return [];
        }

        return Unit::query()
            ->where('company_id', $invoice->company_id)
            ->whereIn('name', $names->all())
            ->get(['name', 'unit_code'])
            ->mapWithKeys(fn (Unit $unit): array => [
                $this->unitKey($unit->name) => $unit->unit_code ?: Unit::DEFAULT_UNIT_CODE,
            ])
            ->all();
    }

    private function unitKey(?string $name): string
    {
        return mb_strtolower(trim((string) $name));
    }

    /**
     * The seller IBAN from the E-Invoice settings, or null when it is missing
     * or not an IBAN at all.
     */
    private function sellerIban(mixed $companyId): ?string
    {
        if ($companyId === null) {
            return null;
        }

        $iban = $this->bankIdentifier(CompanySetting::getSetting(EInvoiceSettings::IBAN, $companyId));

        return $iban !== null && preg_match(self::IBAN_PATTERN, $iban) === 1 ? $iban : null;
    }

    /**
     * The seller BIC from the E-Invoice settings — optional under EN 16931.
     */
    private function sellerBic(mixed $companyId): ?string
    {
        return $this->bankIdentifier(CompanySetting::getSetting(EInvoiceSettings::BIC, $companyId));
    }

    /**
     * A bank identifier as it is written in the XML: no spacing, upper case.
     */
    private function bankIdentifier(mixed $value): ?string
    {
        $identifier = strtoupper((string) preg_replace('/\s+/', '', (string) $value));

        return $identifier === '' ? null : $identifier;
    }

    /**
     * An integer-cent amount as the decimal amount EN 16931 states.
     */
    private function amount(int|float|null $cents): float
    {
        return round((int) $cents / 100, 2);
    }

    /**
     * Rich text as the plain text an XML element can carry: markup resolved to
     * line breaks and control characters — which no XML document may hold —
     * dropped.
     */
    private function plainText(?string $value): string
    {
        if ($value === null) {
            return '';
        }

        $text = preg_replace('/<br\s*\/?>/i', "\n", $value);
        $text = html_entity_decode(strip_tags((string) $text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', (string) $text);

        return trim((string) $text);
    }
}
