<?php

use App\Domains\Accounts\Models\Company;
use App\Domains\Accounts\Models\CompanySetting;
use App\Domains\Catalog\Models\Unit;
use App\Domains\Contacts\Models\Address;
use App\Domains\Contacts\Models\Country;
use App\Domains\Contacts\Models\Customer;
use App\Domains\Money\Models\Currency;
use App\Domains\Sales\Application\EInvoiceBuilder;
use App\Domains\Sales\Application\EInvoiceRequirement;
use App\Domains\Sales\Application\EInvoiceResult;
use App\Domains\Sales\Application\EInvoiceSettings;
use App\Domains\Sales\Application\InvoiceService;
use App\Domains\Sales\Models\Invoice;
use App\Domains\Sales\Models\InvoiceItem;
use App\Domains\Taxation\Models\Tax;
use App\Domains\Taxation\Models\TaxType;
use Illuminate\Support\Facades\Artisan;

/**
 * The E-Invoice builder turns an invoice into EN 16931 CII XML or into the list
 * of requirements it does not meet yet.
 *
 * Every assertion here is on the builder's external answer: the XML that comes
 * out (validated against the Factur-X EN 16931 XSD, then read back through
 * XPath) or the requirement keys. Nothing talks to Gotenberg — the XML layer
 * stands entirely on its own, and these tests prove it by running with the
 * DomPDF driver configured.
 */
const CII_NAMESPACES = [
    'rsm' => 'urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100',
    'ram' => 'urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100',
    'udt' => 'urn:un:unece:uncefact:data:standard:UnqualifiedDataType:100',
];

const CII_SETTLEMENT = '/rsm:CrossIndustryInvoice/rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeSettlement';

const CII_AGREEMENT = '/rsm:CrossIndustryInvoice/rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeAgreement';

const CII_LINE = '/rsm:CrossIndustryInvoice/rsm:SupplyChainTradeTransaction/ram:IncludedSupplyChainTradeLineItem';

/**
 * Validate XML against the Factur-X EN 16931 schema directly, so the assertion
 * does not lean on the builder's own verdict.
 */
function ciiIsSchemaValid(string $xml): bool
{
    $document = new DOMDocument;
    $document->loadXML($xml);

    $previous = libxml_use_internal_errors(true);
    $valid = $document->schemaValidate(
        base_path('vendor/horstoeko/zugferd/src/schema/FACTUR-X_EN16931.xsd')
    );
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    return $valid;
}

/**
 * Every string an XPath expression selects from the CII XML.
 *
 * @return list<string>
 */
function ciiValues(string $xml, string $expression): array
{
    $document = new DOMDocument;
    $document->loadXML($xml);

    $xpath = new DOMXPath($document);

    foreach (CII_NAMESPACES as $prefix => $namespace) {
        $xpath->registerNamespace($prefix, $namespace);
    }

    $values = [];

    foreach ($xpath->query($expression) as $node) {
        $values[] = trim((string) $node->nodeValue);
    }

    return $values;
}

/**
 * The single string an XPath expression selects, or null when it selects none.
 */
function ciiValue(string $xml, string $expression): ?string
{
    return ciiValues($xml, $expression)[0] ?? null;
}

/**
 * The numbers an XPath expression selects, compared without decimal noise.
 *
 * @return list<float>
 */
function ciiAmounts(string $xml, string $expression): array
{
    return array_map(static fn (string $value): float => (float) $value, ciiValues($xml, $expression));
}

/**
 * An invoice with explicit amounts: the builder's job is to carry the stored
 * figures over, so no fixture leaves them to chance.
 */
function eInvoiceTestInvoice(Company $company, Customer $customer, Currency $currency, array $attributes = []): Invoice
{
    return Invoice::factory()->create(array_merge([
        'company_id' => $company->id,
        'customer_id' => $customer->id,
        'currency_id' => $currency->id,
        'recurring_invoice_id' => null,
        'type' => Invoice::TYPE_INVOICE,
        'invoice_date' => '2026-02-01',
        'due_date' => '2026-03-01',
        'invoice_number' => 'INV-000042',
        'reference_number' => 'PO-4711',
        'notes' => null,
        'status' => Invoice::STATUS_SENT,
        'paid_status' => Invoice::STATUS_UNPAID,
        'tax_per_item' => 'NO',
        'discount_per_item' => 'NO',
        'tax_included' => false,
        'discount_type' => 'fixed',
        'discount' => 0,
        'discount_val' => 0,
        'exchange_rate' => 1,
        'sub_total' => 0,
        'tax' => 0,
        'total' => 0,
        'due_amount' => 0,
        'base_discount_val' => 0,
        'base_sub_total' => 0,
        'base_tax' => 0,
        'base_total' => 0,
        'base_due_amount' => 0,
    ], $attributes));
}

function eInvoiceTestItem(Invoice $invoice, array $attributes): InvoiceItem
{
    return $invoice->items()->create(array_merge([
        'company_id' => $invoice->company_id,
        'item_id' => null,
        'recurring_invoice_id' => null,
        'description' => null,
        'unit_name' => null,
        'discount_type' => 'fixed',
        'discount' => 0,
        'discount_val' => 0,
        'tax' => 0,
        'exchange_rate' => 1,
        'base_price' => 0,
        'base_discount_val' => 0,
        'base_tax' => 0,
        'base_total' => 0,
    ], $attributes));
}

function eInvoiceTestTax(Invoice $invoice, TaxType $taxType, int $amount, array $attributes = []): Tax
{
    return Tax::create(array_merge([
        'tax_type_id' => $taxType->id,
        'company_id' => $invoice->company_id,
        'currency_id' => $invoice->currency_id,
        'name' => $taxType->name,
        'percent' => $taxType->percent,
        'calculation_type' => $taxType->calculation_type,
        'fixed_amount' => $taxType->fixed_amount,
        'compound_tax' => false,
        'amount' => $amount,
        'base_amount' => $amount,
    ], $attributes));
}

beforeEach(function () {
    Artisan::call('db:seed', ['--class' => 'DatabaseSeeder', '--force' => true]);
    Artisan::call('db:seed', ['--class' => 'DemoSeeder', '--force' => true]);

    // The builder never touches the PDF pipeline. Leaving the instance on the
    // driver that cannot build Hybrid PDFs proves the XML layer needs neither
    // Gotenberg nor a running service.
    config()->set('pdf.driver', 'dompdf');

    $this->builder = app(EInvoiceBuilder::class);
    $this->company = Company::where('slug', 'acme-inc')->firstOrFail();
    $this->currency = Currency::where('code', 'EUR')->firstOrFail();

    CompanySetting::setSettings([
        EInvoiceSettings::ENABLED => 'YES',
        EInvoiceSettings::IBAN => 'DE02 1203 0000 0000 2020 51',
        EInvoiceSettings::BIC => 'BYLADEM1001',
        EInvoiceSettings::BANK_NAME => 'Deutsche Kreditbank',
    ], $this->company->id);

    $this->customer = Customer::factory()->create([
        'company_id' => $this->company->id,
        'name' => 'Muster GmbH',
        'company_name' => 'Muster Handelsgesellschaft mbH',
    ]);

    $this->customer->addresses()->create([
        'name' => 'Muster GmbH',
        'address_street_1' => 'Hauptstrasse 5',
        'city' => 'Berlin',
        'state' => 'Berlin',
        'zip' => '10115',
        'country_id' => Country::where('code', 'DE')->value('id'),
        'type' => Address::BILLING_TYPE,
    ]);

    $this->standardTax = TaxType::factory()->create([
        'company_id' => $this->company->id,
        'name' => 'USt 19%',
        'percent' => 19,
    ]);

    // A fully populated, standard-rated invoice: two lines at 250.00 net,
    // 19% VAT of 47.50, 297.50 to pay.
    $this->invoice = eInvoiceTestInvoice($this->company, $this->customer, $this->currency, [
        'sub_total' => 25000,
        'tax' => 4750,
        'total' => 29750,
        'due_amount' => 29750,
    ]);

    eInvoiceTestItem($this->invoice, [
        'name' => 'Beratung',
        'description' => 'Konzeption und Beratung',
        'price' => 10000,
        'quantity' => 2,
        'total' => 20000,
    ]);

    eInvoiceTestItem($this->invoice, [
        'name' => 'Lizenz',
        'price' => 5000,
        'quantity' => 1,
        'total' => 5000,
    ]);

    eInvoiceTestTax($this->invoice, $this->standardTax, 4750, ['invoice_id' => $this->invoice->id]);
});

test('a fully populated standard-rated invoice yields schema-valid EN 16931 CII XML', function () {
    $result = $this->builder->build($this->invoice);

    expect($result->isValid())->toBeTrue()
        ->and($result->missingRequirements())->toBe([])
        ->and($result->xml())->toBeString();

    expect(ciiIsSchemaValid($result->xml()))->toBeTrue();
});

test('the document metadata names the invoice, its type, date and currency', function () {
    $xml = $this->builder->build($this->invoice)->xml();

    expect(ciiValue($xml, '/rsm:CrossIndustryInvoice/rsm:ExchangedDocumentContext/ram:GuidelineSpecifiedDocumentContextParameter/ram:ID'))
        ->toBe('urn:cen.eu:en16931:2017')
        ->and(ciiValue($xml, '/rsm:CrossIndustryInvoice/rsm:ExchangedDocument/ram:ID'))->toBe('INV-000042')
        ->and(ciiValue($xml, '/rsm:CrossIndustryInvoice/rsm:ExchangedDocument/ram:TypeCode'))->toBe('380')
        ->and(ciiValue($xml, '/rsm:CrossIndustryInvoice/rsm:ExchangedDocument/ram:IssueDateTime/udt:DateTimeString'))->toBe('20260201')
        ->and(ciiValue($xml, CII_SETTLEMENT.'/ram:InvoiceCurrencyCode'))->toBe('EUR')
        ->and(ciiValue($xml, CII_AGREEMENT.'/ram:BuyerReference'))->toBe('PO-4711');
});

test('the invoice date doubles as the delivery date (BT-72)', function () {
    $xml = $this->builder->build($this->invoice)->xml();

    expect(ciiValue(
        $xml,
        '/rsm:CrossIndustryInvoice/rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeDelivery/ram:ActualDeliverySupplyChainEvent/ram:OccurrenceDateTime/udt:DateTimeString'
    ))->toBe('20260201');
});

test('a credit note is typed as one rather than as an invoice', function () {
    $this->invoice->update(['type' => Invoice::TYPE_CREDIT_NOTE]);

    $xml = $this->builder->build($this->invoice->fresh())->xml();

    expect(ciiValue($xml, '/rsm:CrossIndustryInvoice/rsm:ExchangedDocument/ram:TypeCode'))->toBe('381');
});

test('the seller carries its name, postal address and tax registrations', function () {
    $xml = $this->builder->build($this->invoice)->xml();

    expect(ciiValue($xml, CII_AGREEMENT.'/ram:SellerTradeParty/ram:Name'))->toBe('Acme Inc')
        ->and(ciiValue($xml, CII_AGREEMENT.'/ram:SellerTradeParty/ram:PostalTradeAddress/ram:LineOne'))->toBe('1180 Market Street')
        ->and(ciiValue($xml, CII_AGREEMENT.'/ram:SellerTradeParty/ram:PostalTradeAddress/ram:CityName'))->toBe('San Francisco')
        ->and(ciiValue($xml, CII_AGREEMENT.'/ram:SellerTradeParty/ram:PostalTradeAddress/ram:PostcodeCode'))->toBe('94102')
        ->and(ciiValue($xml, CII_AGREEMENT.'/ram:SellerTradeParty/ram:PostalTradeAddress/ram:CountryID'))->toBe('US')
        ->and(ciiValues($xml, CII_AGREEMENT.'/ram:SellerTradeParty/ram:SpecifiedTaxRegistration/ram:ID'))
        ->toBe(['US123456789', '84-1234567']);
});

test('the buyer carries its name, trading name and country', function () {
    $xml = $this->builder->build($this->invoice)->xml();

    expect(ciiValue($xml, CII_AGREEMENT.'/ram:BuyerTradeParty/ram:Name'))->toBe('Muster GmbH')
        ->and(ciiValue($xml, CII_AGREEMENT.'/ram:BuyerTradeParty/ram:SpecifiedLegalOrganization/ram:TradingBusinessName'))
        ->toBe('Muster Handelsgesellschaft mbH')
        ->and(ciiValue($xml, CII_AGREEMENT.'/ram:BuyerTradeParty/ram:PostalTradeAddress/ram:CityName'))->toBe('Berlin')
        ->and(ciiValue($xml, CII_AGREEMENT.'/ram:BuyerTradeParty/ram:PostalTradeAddress/ram:CountryID'))->toBe('DE');
});

test('the bank details become a SEPA credit transfer payment means', function () {
    $xml = $this->builder->build($this->invoice)->xml();

    expect(ciiValue($xml, CII_SETTLEMENT.'/ram:SpecifiedTradeSettlementPaymentMeans/ram:TypeCode'))->toBe('58')
        ->and(ciiValue($xml, CII_SETTLEMENT.'/ram:SpecifiedTradeSettlementPaymentMeans/ram:PayeePartyCreditorFinancialAccount/ram:IBANID'))
        ->toBe('DE02120300000000202051')
        ->and(ciiValue($xml, CII_SETTLEMENT.'/ram:SpecifiedTradeSettlementPaymentMeans/ram:PayeeSpecifiedCreditorFinancialInstitution/ram:BICID'))
        ->toBe('BYLADEM1001')
        ->and(ciiValue($xml, CII_SETTLEMENT.'/ram:SpecifiedTradePaymentTerms/ram:DueDateDateTime/udt:DateTimeString'))
        ->toBe('20260301');
});

test('every line carries its product, quantity with unit code, net price and net amount', function () {
    Unit::create(['name' => 'Stunden', 'unit_code' => 'HUR', 'company_id' => $this->company->id]);

    $this->invoice->items()->orderBy('id')->first()->update(['unit_name' => 'Stunden']);

    $xml = $this->builder->build($this->invoice->fresh())->xml();

    expect(ciiValues($xml, CII_LINE.'/ram:AssociatedDocumentLineDocument/ram:LineID'))->toBe(['1', '2'])
        ->and(ciiValues($xml, CII_LINE.'/ram:SpecifiedTradeProduct/ram:Name'))->toBe(['Beratung', 'Lizenz'])
        ->and(ciiValue($xml, CII_LINE.'[1]/ram:SpecifiedTradeProduct/ram:Description'))->toBe('Konzeption und Beratung')
        ->and(ciiValues($xml, CII_LINE.'/ram:SpecifiedLineTradeDelivery/ram:BilledQuantity/@unitCode'))->toBe(['HUR', 'C62'])
        ->and(ciiAmounts($xml, CII_LINE.'/ram:SpecifiedLineTradeDelivery/ram:BilledQuantity'))->toBe([2.0, 1.0])
        ->and(ciiAmounts($xml, CII_LINE.'/ram:SpecifiedLineTradeAgreement/ram:NetPriceProductTradePrice/ram:ChargeAmount'))->toBe([100.0, 50.0])
        ->and(ciiAmounts($xml, CII_LINE.'/ram:SpecifiedLineTradeSettlement/ram:SpecifiedTradeSettlementLineMonetarySummation/ram:LineTotalAmount'))
        ->toBe([200.0, 50.0]);
});

test('a per-invoice tax classifies every line and the single VAT breakdown', function () {
    $xml = $this->builder->build($this->invoice)->xml();

    expect(ciiValues($xml, CII_LINE.'/ram:SpecifiedLineTradeSettlement/ram:ApplicableTradeTax/ram:CategoryCode'))->toBe(['S', 'S'])
        ->and(ciiAmounts($xml, CII_LINE.'/ram:SpecifiedLineTradeSettlement/ram:ApplicableTradeTax/ram:RateApplicablePercent'))->toBe([19.0, 19.0])
        ->and(ciiValues($xml, CII_SETTLEMENT.'/ram:ApplicableTradeTax/ram:CategoryCode'))->toBe(['S'])
        ->and(ciiValues($xml, CII_SETTLEMENT.'/ram:ApplicableTradeTax/ram:TypeCode'))->toBe(['VAT'])
        ->and(ciiAmounts($xml, CII_SETTLEMENT.'/ram:ApplicableTradeTax/ram:BasisAmount'))->toBe([250.0])
        ->and(ciiAmounts($xml, CII_SETTLEMENT.'/ram:ApplicableTradeTax/ram:CalculatedAmount'))->toBe([47.5]);
});

test('the summation adds up to the amounts the invoice stores', function () {
    $xml = $this->builder->build($this->invoice)->xml();
    $summation = CII_SETTLEMENT.'/ram:SpecifiedTradeSettlementHeaderMonetarySummation';

    expect(ciiAmounts($xml, $summation.'/ram:LineTotalAmount'))->toBe([250.0])
        ->and(ciiAmounts($xml, $summation.'/ram:AllowanceTotalAmount'))->toBe([0.0])
        ->and(ciiAmounts($xml, $summation.'/ram:TaxBasisTotalAmount'))->toBe([250.0])
        ->and(ciiAmounts($xml, $summation.'/ram:TaxTotalAmount'))->toBe([47.5])
        ->and(ciiAmounts($xml, $summation.'/ram:GrandTotalAmount'))->toBe([297.5])
        ->and(ciiAmounts($xml, $summation.'/ram:TotalPrepaidAmount'))->toBe([0.0])
        ->and(ciiAmounts($xml, $summation.'/ram:DuePayableAmount'))->toBe([297.5]);
});

test('a part-paid invoice states what was prepaid and what is still due', function () {
    $this->invoice->update(['due_amount' => 9750]);

    $xml = $this->builder->build($this->invoice->fresh())->xml();
    $summation = CII_SETTLEMENT.'/ram:SpecifiedTradeSettlementHeaderMonetarySummation';

    expect(ciiAmounts($xml, $summation.'/ram:GrandTotalAmount'))->toBe([297.5])
        ->and(ciiAmounts($xml, $summation.'/ram:TotalPrepaidAmount'))->toBe([200.0])
        ->and(ciiAmounts($xml, $summation.'/ram:DuePayableAmount'))->toBe([97.5]);
});

test('the invoice notes travel as a document note in plain text', function () {
    $this->invoice->update(['notes' => 'Zahlbar ohne Abzug.<br />Rechnung {INVOICE_NUMBER}.']);

    $xml = $this->builder->build($this->invoice->fresh())->xml();

    expect(ciiValue($xml, '/rsm:CrossIndustryInvoice/rsm:ExchangedDocument/ram:IncludedNote/ram:Content'))
        ->toContain('Zahlbar ohne Abzug.')
        ->toContain('Rechnung INV-000042.')
        ->not->toContain('<br');
});

test('an exempt invoice carries category E and its exemption reason', function () {
    $exemptTax = TaxType::factory()->exempt('§ 19 UStG')->create([
        'company_id' => $this->company->id,
        'name' => 'Umsatzsteuerbefreit',
    ]);

    $this->invoice->taxes()->delete();
    $this->invoice->update(['tax' => 0, 'total' => 25000, 'due_amount' => 25000]);
    eInvoiceTestTax($this->invoice, $exemptTax, 0, ['invoice_id' => $this->invoice->id]);

    $result = $this->builder->build($this->invoice->fresh());
    $xml = $result->xml();
    $summation = CII_SETTLEMENT.'/ram:SpecifiedTradeSettlementHeaderMonetarySummation';

    expect($result->isValid())->toBeTrue()
        ->and(ciiIsSchemaValid($xml))->toBeTrue()
        ->and(ciiValues($xml, CII_SETTLEMENT.'/ram:ApplicableTradeTax/ram:CategoryCode'))->toBe(['E'])
        ->and(ciiValues($xml, CII_SETTLEMENT.'/ram:ApplicableTradeTax/ram:ExemptionReason'))->toBe(['§ 19 UStG'])
        ->and(ciiAmounts($xml, CII_SETTLEMENT.'/ram:ApplicableTradeTax/ram:CalculatedAmount'))->toBe([0.0])
        ->and(ciiValues($xml, CII_LINE.'/ram:SpecifiedLineTradeSettlement/ram:ApplicableTradeTax/ram:CategoryCode'))->toBe(['E', 'E'])
        ->and(ciiAmounts($xml, $summation.'/ram:TaxTotalAmount'))->toBe([0.0])
        ->and(ciiAmounts($xml, $summation.'/ram:GrandTotalAmount'))->toBe([250.0]);
});

test('per-item taxes produce one VAT breakdown per rate', function () {
    $reducedTax = TaxType::factory()->create([
        'company_id' => $this->company->id,
        'name' => 'USt 7%',
        'percent' => 7,
    ]);

    $this->invoice->taxes()->delete();
    $this->invoice->update([
        'tax_per_item' => 'YES',
        'tax' => 4150,
        'total' => 29150,
        'due_amount' => 29150,
    ]);

    [$first, $second] = $this->invoice->items()->orderBy('id')->get()->all();
    $first->update(['tax' => 3800]);
    $second->update(['tax' => 350]);

    eInvoiceTestTax($this->invoice, $this->standardTax, 3800, ['invoice_item_id' => $first->id]);
    eInvoiceTestTax($this->invoice, $reducedTax, 350, ['invoice_item_id' => $second->id]);

    $result = $this->builder->build($this->invoice->fresh());
    $xml = $result->xml();
    $summation = CII_SETTLEMENT.'/ram:SpecifiedTradeSettlementHeaderMonetarySummation';

    expect($result->isValid())->toBeTrue()
        ->and(ciiIsSchemaValid($xml))->toBeTrue()
        ->and(ciiAmounts($xml, CII_LINE.'/ram:SpecifiedLineTradeSettlement/ram:ApplicableTradeTax/ram:RateApplicablePercent'))
        ->toBe([19.0, 7.0])
        ->and(ciiAmounts($xml, CII_SETTLEMENT.'/ram:ApplicableTradeTax/ram:RateApplicablePercent'))->toBe([19.0, 7.0])
        ->and(ciiAmounts($xml, CII_SETTLEMENT.'/ram:ApplicableTradeTax/ram:BasisAmount'))->toBe([200.0, 50.0])
        ->and(ciiAmounts($xml, CII_SETTLEMENT.'/ram:ApplicableTradeTax/ram:CalculatedAmount'))->toBe([38.0, 3.5])
        ->and(ciiAmounts($xml, $summation.'/ram:TaxTotalAmount'))->toBe([41.5])
        ->and(ciiAmounts($xml, $summation.'/ram:GrandTotalAmount'))->toBe([291.5]);
});

test('a document-level discount becomes an allowance that reduces the tax basis', function () {
    $this->invoice->taxes()->delete();
    $this->invoice->update([
        'discount_type' => 'fixed',
        'discount' => 50,
        'discount_val' => 5000,
        'tax' => 3800,
        'total' => 23800,
        'due_amount' => 23800,
    ]);
    eInvoiceTestTax($this->invoice, $this->standardTax, 3800, ['invoice_id' => $this->invoice->id]);

    $result = $this->builder->build($this->invoice->fresh());
    $xml = $result->xml();
    $summation = CII_SETTLEMENT.'/ram:SpecifiedTradeSettlementHeaderMonetarySummation';

    expect($result->isValid())->toBeTrue()
        ->and(ciiIsSchemaValid($xml))->toBeTrue()
        ->and(ciiValues($xml, CII_SETTLEMENT.'/ram:SpecifiedTradeAllowanceCharge/ram:ChargeIndicator/udt:Indicator'))->toBe(['false'])
        ->and(ciiAmounts($xml, CII_SETTLEMENT.'/ram:SpecifiedTradeAllowanceCharge/ram:ActualAmount'))->toBe([50.0])
        ->and(ciiValues($xml, CII_SETTLEMENT.'/ram:SpecifiedTradeAllowanceCharge/ram:CategoryTradeTax/ram:CategoryCode'))->toBe(['S'])
        ->and(ciiAmounts($xml, CII_SETTLEMENT.'/ram:ApplicableTradeTax/ram:BasisAmount'))->toBe([200.0])
        ->and(ciiAmounts($xml, $summation.'/ram:LineTotalAmount'))->toBe([250.0])
        ->and(ciiAmounts($xml, $summation.'/ram:AllowanceTotalAmount'))->toBe([50.0])
        ->and(ciiAmounts($xml, $summation.'/ram:TaxBasisTotalAmount'))->toBe([200.0])
        ->and(ciiAmounts($xml, $summation.'/ram:GrandTotalAmount'))->toBe([238.0]);
});

test('a line-level discount becomes a line allowance', function () {
    $this->invoice->items()->orderBy('id')->first()->update([
        'discount_type' => 'fixed',
        'discount' => 20,
        'discount_val' => 2000,
        'total' => 18000,
    ]);

    $this->invoice->taxes()->delete();
    $this->invoice->update([
        'discount_per_item' => 'YES',
        'sub_total' => 23000,
        'tax' => 4370,
        'total' => 27370,
        'due_amount' => 27370,
    ]);
    eInvoiceTestTax($this->invoice, $this->standardTax, 4370, ['invoice_id' => $this->invoice->id]);

    $result = $this->builder->build($this->invoice->fresh());
    $xml = $result->xml();
    $lineAllowance = CII_LINE.'/ram:SpecifiedLineTradeSettlement/ram:SpecifiedTradeAllowanceCharge';

    expect($result->isValid())->toBeTrue()
        ->and(ciiIsSchemaValid($xml))->toBeTrue()
        ->and(ciiValues($xml, $lineAllowance.'/ram:ChargeIndicator/udt:Indicator'))->toBe(['false'])
        ->and(ciiAmounts($xml, $lineAllowance.'/ram:ActualAmount'))->toBe([20.0])
        ->and(ciiAmounts($xml, CII_LINE.'/ram:SpecifiedLineTradeSettlement/ram:SpecifiedTradeSettlementLineMonetarySummation/ram:LineTotalAmount'))
        ->toBe([180.0, 50.0])
        ->and(ciiAmounts($xml, CII_SETTLEMENT.'/ram:ApplicableTradeTax/ram:BasisAmount'))->toBe([230.0]);
});

test('a missing IBAN is reported instead of XML', function () {
    CompanySetting::setSettings([EInvoiceSettings::IBAN => ''], $this->company->id);

    $result = $this->builder->build($this->invoice);

    expect($result->isValid())->toBeFalse()
        ->and($result->xml())->toBeNull()
        ->and($result->missingRequirementKeys())->toBe(['seller_iban']);
});

test('a stored value that is not an IBAN counts as no IBAN', function () {
    CompanySetting::setSettings([EInvoiceSettings::IBAN => 'Sparkasse, Konto 12345'], $this->company->id);

    expect($this->builder->build($this->invoice)->missingRequirementKeys())->toBe(['seller_iban']);
});

test('a buyer without a country is reported instead of XML', function () {
    $this->customer->billingAddress->update(['country_id' => null]);

    $result = $this->builder->build($this->invoice->fresh());

    expect($result->isValid())->toBeFalse()
        ->and($result->xml())->toBeNull()
        ->and($result->missingRequirementKeys())->toBe(['buyer_country']);
});

test('a seller with only a national tax number is reported — BR-CO-26 needs the VAT identifier', function () {
    $this->company->update(['vat_id' => null]);

    $result = $this->builder->build($this->invoice->fresh());

    expect($result->isValid())->toBeFalse()
        ->and($result->missingRequirementKeys())->toBe(['seller_tax_registration']);
});

test('several gaps are reported together, each named once', function () {
    CompanySetting::setSettings([EInvoiceSettings::IBAN => ''], $this->company->id);
    $this->company->update(['vat_id' => null, 'tax_id' => null]);
    $this->company->address->update(['zip' => null]);
    $this->customer->billingAddress->update(['country_id' => null]);

    $result = $this->builder->build($this->invoice->fresh());

    expect($result->missingRequirementKeys())->toBe([
        'seller_postcode',
        'seller_tax_registration',
        'seller_iban',
        'buyer_country',
    ]);
});

test('an invoice without any tax has no VAT breakdown to write', function () {
    $this->invoice->taxes()->delete();

    expect($this->builder->build($this->invoice->fresh())->missingRequirementKeys())
        ->toBe(['invoice_tax']);
});

test('an invoice charging two taxes at once cannot classify its lines', function () {
    $reducedTax = TaxType::factory()->create([
        'company_id' => $this->company->id,
        'name' => 'USt 7%',
        'percent' => 7,
    ]);

    eInvoiceTestTax($this->invoice, $reducedTax, 1750, ['invoice_id' => $this->invoice->id]);

    expect($this->builder->build($this->invoice->fresh())->missingRequirementKeys())
        ->toBe(['single_tax_rate_per_line']);
});

test('tax-inclusive prices are reported rather than reinterpreted as net', function () {
    $this->invoice->update(['tax_included' => true]);

    expect($this->builder->build($this->invoice->fresh())->missingRequirementKeys())
        ->toBe(['net_prices']);
});

test('an exempt tax without an exemption reason is reported', function () {
    $exemptTax = TaxType::factory()->create([
        'company_id' => $this->company->id,
        'name' => 'Steuerfrei',
        'percent' => 0,
        'tax_category_code' => TaxType::TAX_CATEGORY_CODE_EXEMPT,
        'tax_exemption_reason' => null,
    ]);

    $this->invoice->taxes()->delete();
    eInvoiceTestTax($this->invoice, $exemptTax, 0, ['invoice_id' => $this->invoice->id]);

    expect($this->builder->build($this->invoice->fresh())->missingRequirementKeys())
        ->toBe(['tax_exemption_reason']);
});

test('a fixed-amount tax carries no rate a VAT breakdown could state', function () {
    $fixedTax = TaxType::factory()->create([
        'company_id' => $this->company->id,
        'name' => 'Pauschale',
        'calculation_type' => 'fixed',
        'percent' => null,
        'fixed_amount' => 500,
    ]);

    $this->invoice->taxes()->delete();
    eInvoiceTestTax($this->invoice, $fixedTax, 500, ['invoice_id' => $this->invoice->id]);

    expect($this->builder->build($this->invoice->fresh())->missingRequirementKeys())
        ->toBe(['tax_rate_percentage']);
});

test('an invoice without line items is reported', function () {
    $this->invoice->items()->delete();

    expect($this->builder->build($this->invoice->fresh())->missingRequirementKeys())
        ->toBe(['line_items']);
});

test('an unnamed line item is reported', function () {
    $this->invoice->items()->orderBy('id')->first()->update(['name' => '']);

    expect($this->builder->build($this->invoice->fresh())->missingRequirementKeys())
        ->toBe(['line_item_name']);
});

test('a result is either XML or requirements, never both and never neither', function () {
    $valid = EInvoiceResult::valid('<xml/>');

    expect($valid->isValid())->toBeTrue()
        ->and($valid->xml())->toBe('<xml/>')
        ->and($valid->missingRequirements())->toBe([]);

    $incomplete = EInvoiceResult::incomplete([EInvoiceRequirement::SellerIban]);

    expect($incomplete->isValid())->toBeFalse()
        ->and($incomplete->xml())->toBeNull()
        ->and($incomplete->missingRequirements())->toBe([EInvoiceRequirement::SellerIban])
        ->and($incomplete->missingRequirementKeys())->toBe(['seller_iban']);

    expect(fn () => EInvoiceResult::incomplete([]))->toThrow(InvalidArgumentException::class);
});

test('a deducted down payment becomes a document allowance instead of a negative line', function () {
    $this->invoice->taxes()->delete();
    $this->invoice->update([
        'tax_per_item' => 'YES',
        'sub_total' => 15000,
        'tax' => 2850,
        'total' => 17850,
        'due_amount' => 17850,
    ]);

    $items = $this->invoice->items()->orderBy('id')->get();
    $items[0]->update(['tax' => 3800]);
    $items[1]->update(['tax' => 950]);

    $down = eInvoiceTestItem($this->invoice, [
        'name' => 'Anzahlung',
        'price' => -10000,
        'quantity' => 1,
        'total' => -10000,
        'tax' => -1900,
    ]);

    eInvoiceTestTax($this->invoice, $this->standardTax, 3800, ['invoice_item_id' => $items[0]->id]);
    eInvoiceTestTax($this->invoice, $this->standardTax, 950, ['invoice_item_id' => $items[1]->id]);
    eInvoiceTestTax($this->invoice, $this->standardTax, -1900, ['invoice_item_id' => $down->id]);

    $result = $this->builder->build($this->invoice->fresh());
    $xml = $result->xml();
    $summation = CII_SETTLEMENT.'/ram:SpecifiedTradeSettlementHeaderMonetarySummation';

    expect($result->isValid())->toBeTrue()
        ->and(ciiIsSchemaValid($xml))->toBeTrue()
        ->and(ciiValues($xml, CII_LINE.'/ram:AssociatedDocumentLineDocument/ram:LineID'))->toBe(['1', '2'])
        ->and(ciiAmounts($xml, CII_LINE.'/ram:SpecifiedLineTradeAgreement/ram:NetPriceProductTradePrice/ram:ChargeAmount'))
        ->each->toBeGreaterThanOrEqual(0)
        ->and(ciiAmounts($xml, CII_SETTLEMENT.'/ram:SpecifiedTradeAllowanceCharge/ram:ActualAmount'))->toBe([100.0])
        ->and(ciiValues($xml, CII_SETTLEMENT.'/ram:SpecifiedTradeAllowanceCharge/ram:Reason'))->toBe(['Anzahlung'])
        ->and(ciiValues($xml, CII_SETTLEMENT.'/ram:SpecifiedTradeAllowanceCharge/ram:CategoryTradeTax/ram:CategoryCode'))->toBe(['S'])
        ->and(ciiAmounts($xml, CII_SETTLEMENT.'/ram:ApplicableTradeTax/ram:BasisAmount'))->toBe([150.0])
        ->and(ciiAmounts($xml, CII_SETTLEMENT.'/ram:ApplicableTradeTax/ram:CalculatedAmount'))->toBe([28.5])
        ->and(ciiAmounts($xml, $summation.'/ram:LineTotalAmount'))->toBe([250.0])
        ->and(ciiAmounts($xml, $summation.'/ram:AllowanceTotalAmount'))->toBe([100.0])
        ->and(ciiAmounts($xml, $summation.'/ram:TaxBasisTotalAmount'))->toBe([150.0])
        ->and(ciiAmounts($xml, $summation.'/ram:GrandTotalAmount'))->toBe([178.5])
        ->and(ciiAmounts($xml, $summation.'/ram:DuePayableAmount'))->toBe([178.5]);
});

test('a deduction and a document discount both reduce the tax basis', function () {
    $this->invoice->taxes()->delete();
    $this->invoice->update([
        'tax_per_item' => 'YES',
        'discount_type' => 'fixed',
        'discount' => 50,
        'discount_val' => 5000,
        'sub_total' => 10000,
        'tax' => 1900,
        'total' => 11900,
        'due_amount' => 11900,
    ]);

    $items = $this->invoice->items()->orderBy('id')->get();
    $down = eInvoiceTestItem($this->invoice, [
        'name' => 'Anzahlung',
        'price' => -10000,
        'quantity' => 1,
        'total' => -10000,
        'tax' => -1900,
    ]);

    eInvoiceTestTax($this->invoice, $this->standardTax, 3800, ['invoice_item_id' => $items[0]->id]);
    eInvoiceTestTax($this->invoice, $this->standardTax, 950, ['invoice_item_id' => $items[1]->id]);
    eInvoiceTestTax($this->invoice, $this->standardTax, -2850, ['invoice_item_id' => $down->id]);

    $xml = $this->builder->build($this->invoice->fresh())->xml();
    $summation = CII_SETTLEMENT.'/ram:SpecifiedTradeSettlementHeaderMonetarySummation';

    expect(ciiIsSchemaValid($xml))->toBeTrue()
        ->and(ciiAmounts($xml, CII_SETTLEMENT.'/ram:SpecifiedTradeAllowanceCharge/ram:ActualAmount'))->toBe([100.0, 50.0])
        ->and(ciiAmounts($xml, CII_SETTLEMENT.'/ram:ApplicableTradeTax/ram:BasisAmount'))->toBe([100.0])
        ->and(ciiAmounts($xml, $summation.'/ram:AllowanceTotalAmount'))->toBe([150.0])
        ->and(ciiAmounts($xml, $summation.'/ram:TaxBasisTotalAmount'))->toBe([100.0]);
});

test('an invoice made only of deductions has no line to state', function () {
    $this->invoice->items()->delete();

    eInvoiceTestItem($this->invoice, [
        'name' => 'Anzahlung',
        'price' => -10000,
        'quantity' => 1,
        'total' => -10000,
    ]);

    expect($this->builder->build($this->invoice->fresh())->missingRequirementKeys())
        ->toContain('line_items');
});

test('a negative net price on a positive amount is reported — BR-27', function () {
    $this->invoice->items()->first()->update(['price' => -10000, 'quantity' => -2, 'total' => 20000]);

    expect($this->builder->build($this->invoice->fresh())->missingRequirementKeys())
        ->toBe(['negative_price']);
});

test('a standard-rated tax at 0% is reported — BR-S-05', function () {
    $zeroStandard = TaxType::factory()->create([
        'company_id' => $this->company->id,
        'name' => 'USt 0%',
        'percent' => 0,
        'tax_category_code' => TaxType::TAX_CATEGORY_CODE_STANDARD,
    ]);

    $this->invoice->taxes()->delete();
    eInvoiceTestTax($this->invoice, $zeroStandard, 0, ['invoice_id' => $this->invoice->id]);

    expect($this->builder->build($this->invoice->fresh())->missingRequirementKeys())
        ->toBe(['tax_rate_for_category']);
});

test('a zero-rated tax with a rate above 0% is reported — BR-Z-05', function () {
    $mislabelled = TaxType::factory()->create([
        'company_id' => $this->company->id,
        'name' => 'USt 19% als Z',
        'percent' => 19,
        'tax_category_code' => TaxType::TAX_CATEGORY_CODE_ZERO_RATED,
    ]);

    $this->invoice->taxes()->delete();
    eInvoiceTestTax($this->invoice, $mislabelled, 4750, ['invoice_id' => $this->invoice->id]);

    expect($this->builder->build($this->invoice->fresh())->missingRequirementKeys())
        ->toBe(['tax_rate_for_category']);
});

test('rendering the pdf data does not disturb the taxes the e-invoice states', function () {
    $this->invoice->taxes()->delete();
    $this->invoice->update(['tax_per_item' => 'YES']);

    foreach ($this->invoice->items()->orderBy('id')->get() as $item) {
        $item->update(['tax' => (int) round($item->total * 0.19)]);
        eInvoiceTestTax($this->invoice, $this->standardTax, (int) round($item->total * 0.19), ['invoice_item_id' => $item->id]);
    }

    $invoice = $this->invoice->fresh();
    $invoice->loadMissing('items.taxes.taxType');

    // The document template folds the per-item taxes into one running total
    // per tax type for display; that must happen on copies.
    app(InvoiceService::class)->getPdfData($invoice);

    $xml = $this->builder->build($invoice)->xml();
    $summation = CII_SETTLEMENT.'/ram:SpecifiedTradeSettlementHeaderMonetarySummation';

    expect(ciiAmounts($xml, CII_SETTLEMENT.'/ram:ApplicableTradeTax/ram:CalculatedAmount'))->toBe([47.5])
        ->and(ciiAmounts($xml, $summation.'/ram:TaxTotalAmount'))->toBe([47.5]);
});
