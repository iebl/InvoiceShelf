<?php

use App\Domains\Accounts\Models\Company;
use App\Domains\Accounts\Models\User;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

beforeEach(function () {
    Artisan::call('db:seed', ['--class' => 'DatabaseSeeder', '--force' => true]);
    Artisan::call('db:seed', ['--class' => 'DemoSeeder', '--force' => true]);

    $user = User::findOrFail(1);
    $this->company = $user->companies()->firstOrFail();
    $this->withHeaders(['company' => $this->company->id]);
    Sanctum::actingAs($user, ['*']);
});

$eInvoiceSettings = [
    'einvoice_enabled' => 'YES',
    'einvoice_iban' => 'DE02120300000000202051',
    'einvoice_bic' => 'BYLADEM1001',
    'einvoice_bank_name' => 'Deutsche Kreditbank Berlin',
];

test('a company owner stores the e-invoice switch and bank details', function () use ($eInvoiceSettings) {
    postJson('/api/v1/company/settings', ['settings' => $eInvoiceSettings])
        ->assertOk()
        ->assertJson(['success' => true]);

    foreach ($eInvoiceSettings as $option => $value) {
        $this->assertDatabaseHas('company_settings', [
            'company_id' => $this->company->id,
            'option' => $option,
            'value' => $value,
        ]);
    }
});

test('the stored e-invoice settings are read back through the company settings endpoint', function () use ($eInvoiceSettings) {
    postJson('/api/v1/company/settings', ['settings' => $eInvoiceSettings])->assertOk();

    getJson('/api/v1/company/settings?'.http_build_query(['settings' => array_keys($eInvoiceSettings)]))
        ->assertOk()
        ->assertExactJson($eInvoiceSettings);
});

test('the e-invoice settings survive a reload through the bootstrap payload', function () use ($eInvoiceSettings) {
    postJson('/api/v1/company/settings', ['settings' => $eInvoiceSettings])->assertOk();

    getJson('/api/v1/bootstrap')
        ->assertOk()
        ->assertJson(['current_company_settings' => $eInvoiceSettings]);
});

test('turning the e-invoice switch back off is persisted', function () use ($eInvoiceSettings) {
    postJson('/api/v1/company/settings', ['settings' => $eInvoiceSettings])->assertOk();

    postJson('/api/v1/company/settings', ['settings' => ['einvoice_enabled' => 'NO']])
        ->assertOk()
        ->assertJson(['success' => true]);

    $this->assertDatabaseHas('company_settings', [
        'company_id' => $this->company->id,
        'option' => 'einvoice_enabled',
        'value' => 'NO',
    ]);

    $this->assertDatabaseHas('company_settings', [
        'company_id' => $this->company->id,
        'option' => 'einvoice_iban',
        'value' => $eInvoiceSettings['einvoice_iban'],
    ]);
});

test('e-invoice settings are scoped to the company that stored them', function () use ($eInvoiceSettings) {
    postJson('/api/v1/company/settings', ['settings' => $eInvoiceSettings])->assertOk();

    $otherCompany = Company::factory()->create();
    User::findOrFail(1)->companies()->attach($otherCompany->id);

    $this->withHeaders(['company' => $otherCompany->id]);

    getJson('/api/v1/company/settings?'.http_build_query(['settings' => array_keys($eInvoiceSettings)]))
        ->assertOk()
        ->assertExactJson([]);
});

test('bootstrap reports e-invoicing as unavailable under a non gotenberg pdf driver', function () {
    config()->set('pdf.driver', 'dompdf');

    getJson('/api/v1/bootstrap')
        ->assertOk()
        ->assertJson([
            'e_invoice' => [
                'available' => false,
                'required_pdf_driver' => 'gotenberg',
            ],
        ]);
});

test('bootstrap reports e-invoicing as available under the gotenberg pdf driver', function () {
    config()->set('pdf.driver', 'gotenberg');

    getJson('/api/v1/bootstrap')
        ->assertOk()
        ->assertJson([
            'e_invoice' => [
                'available' => true,
                'required_pdf_driver' => 'gotenberg',
            ],
        ]);
});

test('the e-invoice tab is an owner-only entry of the company setting menu', function () {
    $entry = collect(config('invoiceshelf.setting_menu'))
        ->firstWhere('link', '/admin/settings/e-invoice');

    expect($entry)->not->toBeNull()
        ->and($entry['title'])->toBe('settings.menu_title.e_invoice')
        ->and($entry['owner_only'])->toBeTrue();
});
