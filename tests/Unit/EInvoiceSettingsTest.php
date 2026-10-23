<?php

use App\Domains\Accounts\Models\Company;
use App\Domains\Accounts\Models\CompanySetting;
use App\Domains\Sales\Application\EInvoiceSettings;
use Illuminate\Support\Facades\Artisan;

beforeEach(function () {
    Artisan::call('db:seed', ['--class' => 'DatabaseSeeder', '--force' => true]);
    Artisan::call('db:seed', ['--class' => 'DemoSeeder', '--force' => true]);

    $this->settings = app(EInvoiceSettings::class);
    $this->company = Company::factory()->create();
});

test('e-invoicing is unavailable while the instance renders pdfs with dompdf', function () {
    config()->set('pdf.driver', 'dompdf');

    expect($this->settings->isAvailable())->toBeFalse()
        ->and($this->settings->context())->toBe([
            'available' => false,
            'required_pdf_driver' => 'gotenberg',
        ]);
});

test('e-invoicing is available once the instance renders pdfs with gotenberg', function () {
    config()->set('pdf.driver', 'gotenberg');

    expect($this->settings->isAvailable())->toBeTrue()
        ->and($this->settings->context())->toBe([
            'available' => true,
            'required_pdf_driver' => 'gotenberg',
        ]);
});

test('a company issues e-invoices when the switch is on and gotenberg renders the pdfs', function () {
    config()->set('pdf.driver', 'gotenberg');
    CompanySetting::setSettings([EInvoiceSettings::ENABLED => 'YES'], $this->company->id);

    expect($this->settings->isEnabledFor($this->company->id))->toBeTrue();
});

test('a company does not issue e-invoices while its switch is off', function () {
    config()->set('pdf.driver', 'gotenberg');
    CompanySetting::setSettings([EInvoiceSettings::ENABLED => 'NO'], $this->company->id);

    expect($this->settings->isEnabledFor($this->company->id))->toBeFalse();
});

test('a company does not issue e-invoices when the switch was never touched', function () {
    config()->set('pdf.driver', 'gotenberg');

    expect($this->settings->isEnabledFor($this->company->id))->toBeFalse();
});

test('an enabled company does not issue e-invoices without the required pdf driver', function () {
    config()->set('pdf.driver', 'dompdf');
    CompanySetting::setSettings([EInvoiceSettings::ENABLED => 'YES'], $this->company->id);

    expect($this->settings->isEnabledFor($this->company->id))->toBeFalse();
});

test('the e-invoice setting keys are namespaced and complete', function () {
    expect(EInvoiceSettings::KEYS)->toBe([
        'einvoice_enabled',
        'einvoice_iban',
        'einvoice_bic',
        'einvoice_bank_name',
    ]);
});
