<?php

use App\Domains\Accounts\Models\Company;
use App\Domains\Accounts\Models\CompanySetting;
use App\Domains\Contacts\Models\Address;
use App\Domains\Contacts\Models\Country;
use App\Domains\Contacts\Models\Customer;
use App\Domains\Money\Models\Currency;
use App\Domains\Sales\Application\EInvoiceAttachmentResolver;
use App\Domains\Sales\Application\EInvoiceSettings;
use App\Domains\Sales\Models\Invoice;
use App\Domains\Taxation\Models\Tax;
use App\Domains\Taxation\Models\TaxType;
use App\Platform\Pdf\Rendering\FacturXAttachment;
use Illuminate\Support\Facades\Artisan;
use Tests\Conformance\Support\ConformanceEnvironment;

/**
 * Renders invoices with a real Gotenberg and hands the PDFs to Mustang CLI
 * (EN 16931 XSD and Schematron, container, Factur-X metadata) and veraPDF
 * (PDF/A-3b).
 *
 * Needs a Gotenberg service and two Java tools, so it is in the `conformance`
 * group that phpunit.xml excludes from ordinary runs. The CI job provisions
 * everything and selects the group by name.
 *
 * @see .github/workflows/einvoice-conformance.yaml
 * @see docs/architecture/0005-einvoice-gotenberg-container-horstoeko-xml.md
 */

/**
 * The Hybrid PDF for an invoice, rendered once per name and kept on disk so
 * the CI job can upload it as an artifact.
 */
function conformanceHybridPdf(Invoice $invoice, string $name = 'hybrid-invoice'): string
{
    static $paths = [];

    if (isset($paths[$name])) {
        return $paths[$name];
    }

    // Same path as a download, so the validated file is the one a recipient gets.
    $pdf = $invoice->getGeneratedPDFOrStream('invoice')->getContent();

    $path = ConformanceEnvironment::artifactDirectory().'/'.$name.'.pdf';

    file_put_contents($path, $pdf);

    return $paths[$name] = $path;
}

beforeEach(function () {
    Artisan::call('db:seed', ['--class' => 'DatabaseSeeder', '--force' => true]);
    Artisan::call('db:seed', ['--class' => 'DemoSeeder', '--force' => true]);

    $host = ConformanceEnvironment::gotenbergHost();

    // A Gotenberg service in CI answers on a loopback or container address,
    // which PrivateNetworkGuard blocks unless the operator names it — exactly
    // what a sidecar deployment does. Naming it here is that declaration.
    config([
        'pdf.driver' => 'gotenberg',
        'pdf.connections.gotenberg.host' => $host,
        'network.allowed_private_hosts.gotenberg' => [$host],
        // The e-invoice dictates PDF/A-3b on its own; leave the instance-wide
        // setting out of it.
        'pdf.connections.gotenberg.pdfa' => null,
    ]);

    $this->company = Company::where('slug', 'acme-inc')->firstOrFail();

    // A German seller issuing under the German mandate — the case the feature
    // exists for, and the one the validators are strictest about.
    $this->company->update(['vat_id' => 'DE123456789', 'tax_id' => null]);
    $this->company->address->update([
        'address_street_1' => 'Marienplatz 1',
        'address_street_2' => null,
        'city' => 'München',
        'state' => 'Bayern',
        'zip' => '80331',
        'country_id' => Country::where('code', 'DE')->value('id'),
    ]);

    CompanySetting::setSettings([
        EInvoiceSettings::ENABLED => 'YES',
        EInvoiceSettings::IBAN => 'DE02120300000000202051',
        EInvoiceSettings::BIC => 'BYLADEM1001',
        EInvoiceSettings::BANK_NAME => 'Deutsche Kreditbank',
    ], $this->company->id);

    $customer = Customer::factory()->create([
        'company_id' => $this->company->id,
        'name' => 'Muster GmbH',
    ]);

    $customer->addresses()->create([
        'name' => 'Muster GmbH',
        'address_street_1' => 'Hauptstrasse 5',
        'city' => 'Berlin',
        'state' => 'Berlin',
        'zip' => '10115',
        'country_id' => Country::where('code', 'DE')->value('id'),
        'type' => Address::BILLING_TYPE,
    ]);

    $this->standardTaxType = $taxType = TaxType::factory()->create([
        'company_id' => $this->company->id,
        'name' => 'USt 19%',
        'percent' => 19,
        'tax_category_code' => TaxType::TAX_CATEGORY_CODE_STANDARD,
    ]);

    // 250.00 net, 19% VAT of 47.50, 297.50 to pay.
    $this->invoice = Invoice::factory()->create([
        'company_id' => $this->company->id,
        'customer_id' => $customer->id,
        'currency_id' => Currency::where('code', 'EUR')->value('id'),
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
        'sub_total' => 25000,
        'tax' => 4750,
        'total' => 29750,
        'due_amount' => 29750,
        'base_discount_val' => 0,
        'base_sub_total' => 25000,
        'base_tax' => 4750,
        'base_total' => 29750,
        'base_due_amount' => 29750,
    ]);

    $this->invoice->items()->create([
        'company_id' => $this->company->id,
        'item_id' => null,
        'recurring_invoice_id' => null,
        'name' => 'Beratung',
        'description' => null,
        'unit_name' => null,
        'price' => 12500,
        'quantity' => 2,
        'total' => 25000,
        'discount_type' => 'fixed',
        'discount' => 0,
        'discount_val' => 0,
        'tax' => 0,
        'exchange_rate' => 1,
        'base_price' => 12500,
        'base_discount_val' => 0,
        'base_tax' => 0,
        'base_total' => 25000,
    ]);

    Tax::create([
        'tax_type_id' => $taxType->id,
        'company_id' => $this->company->id,
        'currency_id' => $this->invoice->currency_id,
        'invoice_id' => $this->invoice->id,
        'name' => $taxType->name,
        'percent' => 19,
        'calculation_type' => $taxType->calculation_type,
        'fixed_amount' => $taxType->fixed_amount,
        'compound_tax' => false,
        'amount' => 4750,
        'base_amount' => 4750,
    ]);
});

/**
 * The document itself. Asserting that the invoice produced an e-invoice — that
 * the Fallback did not apply — before looking at the bytes is what keeps a
 * green conformance run from meaning "an ordinary PDF passed a check nobody
 * made".
 */
test('a real gotenberg turns the seeded invoice into a hybrid pdf', function () {
    $attachment = app(EInvoiceAttachmentResolver::class)->resolve($this->invoice);

    expect($attachment)->toBeInstanceOf(FacturXAttachment::class)
        ->and($attachment->profile)->toBe(FacturXAttachment::PROFILE_EN_16931);

    $pdf = (string) file_get_contents(conformanceHybridPdf($this->invoice));

    expect($pdf)->toStartWith('%PDF')
        ->and(strlen($pdf))->toBeGreaterThan(1000);
});

test('mustang validates it as an EN 16931 conformant zugferd e-invoice', function () {
    $report = ConformanceEnvironment::validateWithMustang(conformanceHybridPdf($this->invoice));

    expect($report->isValid())->toBeTrue($report->summary())
        ->and($report->profile())->toBe('urn:cen.eu:en16931:2017', $report->summary());
});

test('verapdf confirms the container is PDF/A-3', function () {
    $report = ConformanceEnvironment::validateWithVeraPdf(conformanceHybridPdf($this->invoice));

    expect($report->isCompliant())->toBeTrue($report->summary())
        ->and($report->profileName())->toContain('PDF/A-3');
});

/**
 * A final invoice that settles a down payment, taxed per line with a
 * zero-rated PV line beside the 19 % one: the shape that first reached a real
 * recipient as an invalid e-invoice (a negative item price, BR-27, and a
 * standard-rated line at 0 %, BR-S-05).
 */
function conformanceFinalInvoice(object $test): Invoice
{
    $zeroTax = TaxType::factory()->create([
        'company_id' => $test->company->id,
        'name' => 'USt 0 % gemäß § 12 Abs. 3 UStG',
        'percent' => 0,
        'tax_category_code' => TaxType::TAX_CATEGORY_CODE_ZERO_RATED,
    ]);

    // 1000.00 + 300.00 net, less a 500.00 down payment: basis 800.00, VAT 95.00.
    $invoice = Invoice::factory()->create([
        'company_id' => $test->company->id,
        'customer_id' => $test->invoice->customer_id,
        'currency_id' => $test->invoice->currency_id,
        'recurring_invoice_id' => null,
        'type' => Invoice::TYPE_INVOICE,
        'invoice_date' => '2026-03-01',
        'due_date' => '2026-03-15',
        'invoice_number' => 'INV-000043',
        'reference_number' => null,
        'notes' => null,
        'status' => Invoice::STATUS_SENT,
        'paid_status' => Invoice::STATUS_UNPAID,
        'tax_per_item' => 'YES',
        'discount_per_item' => 'NO',
        'tax_included' => false,
        'discount_type' => 'fixed',
        'discount' => 0,
        'discount_val' => 0,
        'exchange_rate' => 1,
        'sub_total' => 80000,
        'tax' => 9500,
        'total' => 89500,
        'due_amount' => 89500,
        'base_discount_val' => 0,
        'base_sub_total' => 80000,
        'base_tax' => 9500,
        'base_total' => 89500,
        'base_due_amount' => 89500,
    ]);

    $lines = [
        ['Beratung', 100000, 19000, $test->standardTaxType],
        ['PV-Modul', 30000, 0, $zeroTax],
        ['Anzahlung', -50000, -9500, $test->standardTaxType],
    ];

    foreach ($lines as [$name, $total, $tax, $type]) {
        $item = $invoice->items()->create([
            'company_id' => $test->company->id,
            'item_id' => null,
            'recurring_invoice_id' => null,
            'name' => $name,
            'description' => null,
            'unit_name' => null,
            'price' => $total,
            'quantity' => 1,
            'total' => $total,
            'discount_type' => 'fixed',
            'discount' => 0,
            'discount_val' => 0,
            'tax' => $tax,
            'exchange_rate' => 1,
            'base_price' => $total,
            'base_discount_val' => 0,
            'base_tax' => $tax,
            'base_total' => $total,
        ]);

        Tax::create([
            'tax_type_id' => $type->id,
            'company_id' => $test->company->id,
            'currency_id' => $invoice->currency_id,
            'invoice_item_id' => $item->id,
            'name' => $type->name,
            'percent' => $type->percent,
            'calculation_type' => $type->calculation_type,
            'fixed_amount' => $type->fixed_amount,
            'compound_tax' => false,
            'amount' => $tax,
            'base_amount' => $tax,
        ]);
    }

    return $invoice;
}

test('a final invoice settling a down payment is a conformant e-invoice', function () {
    $invoice = conformanceFinalInvoice($this);

    expect(app(EInvoiceAttachmentResolver::class)->resolve($invoice))->toBeInstanceOf(FacturXAttachment::class);

    $path = conformanceHybridPdf($invoice, 'hybrid-final-invoice');
    $mustang = ConformanceEnvironment::validateWithMustang($path);
    $verapdf = ConformanceEnvironment::validateWithVeraPdf($path);

    expect($mustang->isValid())->toBeTrue($mustang->summary())
        ->and($verapdf->isCompliant())->toBeTrue($verapdf->summary());
});
