<?php

use App\Domains\Accounts\Models\Company;
use App\Domains\Accounts\Models\CompanySetting;
use App\Domains\Accounts\Models\User;
use App\Domains\Contacts\Models\Address;
use App\Domains\Contacts\Models\Country;
use App\Domains\Contacts\Models\Customer;
use App\Domains\Money\Models\Currency;
use App\Domains\Sales\Application\EInvoiceSettings;
use App\Domains\Sales\Models\Invoice;
use App\Domains\Taxation\Models\Tax;
use App\Domains\Taxation\Models\TaxType;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\getJson;

/**
 * The E-Invoice Ready indicator and the Fallback warning, read through the API.
 *
 * Both answers come from the same builder check, so the tests assert on what a
 * user is told: whether the company is ready, whether an invoice would fall
 * back, and exactly which requirements are named when it would.
 */
beforeEach(function () {
    Artisan::call('db:seed', ['--class' => 'DatabaseSeeder', '--force' => true]);
    Artisan::call('db:seed', ['--class' => 'DemoSeeder', '--force' => true]);

    config()->set('pdf.driver', 'gotenberg');

    // The demo company is the one with complete master data — a postal address
    // and both tax registrations — so it is what "ready" is measured against.
    $this->company = Company::where('slug', 'acme-inc')->firstOrFail();
    $this->owner = User::findOrFail($this->company->owner_id);

    $this->withHeaders(['company' => $this->company->id]);
    Sanctum::actingAs($this->owner, ['*']);

    CompanySetting::setSettings([
        EInvoiceSettings::ENABLED => 'YES',
        EInvoiceSettings::IBAN => 'DE02120300000000202051',
        EInvoiceSettings::BIC => 'BYLADEM1001',
        EInvoiceSettings::BANK_NAME => 'Deutsche Kreditbank',
    ], $this->company->id);
});

/**
 * A customer whose billing address carries everything EN 16931 asks of a buyer.
 */
function readinessCustomer(int $companyId): Customer
{
    $customer = Customer::factory()->create([
        'company_id' => $companyId,
        'name' => 'Muster GmbH',
    ]);

    $customer->addresses()->create([
        'name' => 'Muster GmbH',
        'address_street_1' => 'Hauptstrasse 5',
        'city' => 'Berlin',
        'zip' => '10115',
        'country_id' => Country::where('code', 'DE')->value('id'),
        'type' => Address::BILLING_TYPE,
    ]);

    return $customer;
}

/**
 * A standard-rated invoice that meets every requirement: one line at 100.00
 * net, 19% VAT of 19.00.
 */
function readinessInvoice(Company $company, Customer $customer): Invoice
{
    $currency = Currency::where('code', 'EUR')->firstOrFail();

    $invoice = Invoice::factory()->create([
        'company_id' => $company->id,
        'customer_id' => $customer->id,
        'currency_id' => $currency->id,
        'recurring_invoice_id' => null,
        'type' => Invoice::TYPE_INVOICE,
        'invoice_date' => '2026-02-01',
        'due_date' => '2026-03-01',
        'invoice_number' => 'INV-000042',
        'reference_number' => null,
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
        'sub_total' => 10000,
        'tax' => 1900,
        'total' => 11900,
        'due_amount' => 11900,
        'base_discount_val' => 0,
        'base_sub_total' => 10000,
        'base_tax' => 1900,
        'base_total' => 11900,
        'base_due_amount' => 11900,
    ]);

    $invoice->items()->create([
        'company_id' => $company->id,
        'item_id' => null,
        'recurring_invoice_id' => null,
        'name' => 'Beratung',
        'description' => null,
        'unit_name' => null,
        'price' => 10000,
        'quantity' => 1,
        'total' => 10000,
        'discount_type' => 'fixed',
        'discount' => 0,
        'discount_val' => 0,
        'tax' => 0,
        'exchange_rate' => 1,
        'base_price' => 10000,
        'base_discount_val' => 0,
        'base_tax' => 0,
        'base_total' => 10000,
    ]);

    $taxType = TaxType::factory()->create([
        'company_id' => $company->id,
        'name' => 'USt 19%',
        'percent' => 19,
    ]);

    Tax::create([
        'tax_type_id' => $taxType->id,
        'company_id' => $company->id,
        'currency_id' => $currency->id,
        'invoice_id' => $invoice->id,
        'name' => $taxType->name,
        'percent' => 19,
        'calculation_type' => $taxType->calculation_type,
        'fixed_amount' => $taxType->fixed_amount,
        'compound_tax' => false,
        'amount' => 1900,
        'base_amount' => 1900,
    ]);

    return $invoice->fresh();
}

test('the readiness endpoint reports a company with complete master data as ready', function () {
    getJson('/api/v1/e-invoice/readiness')
        ->assertOk()
        ->assertExactJson([
            'available' => true,
            'enabled' => true,
            'required_pdf_driver' => 'gotenberg',
            'ready' => true,
            'missing_requirements' => [],
        ]);
});

test('the readiness endpoint names exactly the master data that is missing', function () {
    CompanySetting::setSettings([EInvoiceSettings::IBAN => ''], $this->company->id);
    $this->company->address()->update(['country_id' => null, 'zip' => null]);

    getJson('/api/v1/e-invoice/readiness')
        ->assertOk()
        ->assertJson([
            'ready' => false,
            'missing_requirements' => ['seller_postcode', 'seller_country', 'seller_iban'],
        ]);
});

test('an IBAN that is not one at all counts as missing', function () {
    CompanySetting::setSettings([EInvoiceSettings::IBAN => 'not-an-iban'], $this->company->id);

    getJson('/api/v1/e-invoice/readiness')
        ->assertOk()
        ->assertJson([
            'ready' => false,
            'missing_requirements' => ['seller_iban'],
        ]);
});

test('the readiness endpoint reports e-invoicing as unavailable under a non gotenberg pdf driver', function () {
    config()->set('pdf.driver', 'dompdf');

    getJson('/api/v1/e-invoice/readiness')
        ->assertOk()
        ->assertJson([
            'available' => false,
            'enabled' => false,
            'required_pdf_driver' => 'gotenberg',
            'ready' => true,
        ]);
});

test('readiness is scoped to the company the request is made for', function () {
    $otherCompany = Company::factory()->create(['owner_id' => $this->owner->id]);
    $this->owner->companies()->attach($otherCompany->id);

    $this->withHeaders(['company' => $otherCompany->id]);

    getJson('/api/v1/e-invoice/readiness')
        ->assertOk()
        ->assertJson([
            'enabled' => false,
            'ready' => false,
        ])
        ->assertJsonFragment(['missing_requirements' => [
            'seller_street',
            'seller_postcode',
            'seller_city',
            'seller_country',
            'seller_tax_registration',
            'seller_iban',
        ]]);
});

test('a complete invoice reports no fallback and no missing requirements', function () {
    $invoice = readinessInvoice($this->company, readinessCustomer($this->company->id));

    getJson("/api/v1/invoices/{$invoice->id}/e-invoice-readiness")
        ->assertOk()
        ->assertExactJson([
            'available' => true,
            'enabled' => true,
            'required_pdf_driver' => 'gotenberg',
            'ready' => true,
            'fallback' => false,
            'missing_requirements' => [],
        ]);
});

test('an invoice that would fall back names the requirements it does not meet', function () {
    $customer = readinessCustomer($this->company->id);
    $customer->billingAddress->update(['country_id' => null]);

    $invoice = readinessInvoice($this->company, $customer->fresh());

    getJson("/api/v1/invoices/{$invoice->id}/e-invoice-readiness")
        ->assertOk()
        ->assertJson([
            'ready' => false,
            'fallback' => true,
            'missing_requirements' => ['buyer_country'],
        ]);
});

test('an invoice of a company that has not enabled e-invoicing never falls back', function () {
    CompanySetting::setSettings([EInvoiceSettings::ENABLED => 'NO'], $this->company->id);

    $customer = readinessCustomer($this->company->id);
    $customer->billingAddress->update(['country_id' => null]);

    $invoice = readinessInvoice($this->company, $customer->fresh());

    getJson("/api/v1/invoices/{$invoice->id}/e-invoice-readiness")
        ->assertOk()
        ->assertJson([
            'enabled' => false,
            'ready' => false,
            'fallback' => false,
            'missing_requirements' => ['buyer_country'],
        ]);
});

test('the invoice readiness of a company the user does not belong to is not readable', function () {
    $invoice = Invoice::factory()->create([
        'company_id' => Company::factory()->create()->id,
    ]);

    getJson("/api/v1/invoices/{$invoice->id}/e-invoice-readiness")
        ->assertForbidden();
});
