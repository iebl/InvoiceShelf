<?php

use Gotenberg\FacturX;
use Gotenberg\Modules\ChromiumPdf;
use horstoeko\zugferd\ZugferdDocumentBuilder;
use horstoeko\zugferd\ZugferdProfiles;

/**
 * The e-invoice feature is built on two dependencies that must stay aligned:
 * horstoeko/zugferd writes the EN 16931 CII XML, and Gotenberg builds the
 * PDF/A-3 container around it. Both need a version that knows about Factur-X,
 * and the dev stack needs a Gotenberg image that accepts the `facturxXml`
 * field. These assert the capabilities, not just the version strings.
 *
 * @see docs/architecture/0005-einvoice-gotenberg-container-horstoeko-xml.md
 */

/**
 * Reads the version a package is pinned to in composer.lock.
 */
function lockedPackageVersion(string $package): string
{
    $lock = json_decode((string) file_get_contents(base_path('composer.lock')), true);

    $packages = array_merge($lock['packages'] ?? [], $lock['packages-dev'] ?? []);

    foreach ($packages as $entry) {
        if (($entry['name'] ?? null) === $package) {
            return ltrim((string) $entry['version'], 'v');
        }
    }

    throw new RuntimeException("Package {$package} is not present in composer.lock.");
}

/**
 * Returns the Gotenberg image tags used by the development compose files.
 *
 * @return array<string, string>
 */
function devGotenbergImageTags(): array
{
    $tags = [];

    foreach (glob(base_path('docker/development/docker-compose.*.gotenberg.yml')) ?: [] as $file) {
        preg_match('/image:\s*gotenberg\/gotenberg:(\S+)/', (string) file_get_contents($file), $matches);

        $tags[basename($file)] = $matches[1] ?? '';
    }

    return $tags;
}

test('horstoeko/zugferd is installed and autoloads', function () {
    expect(class_exists(ZugferdDocumentBuilder::class))->toBeTrue();
    expect(class_exists(ZugferdProfiles::class))->toBeTrue();
});

test('horstoeko/zugferd can build an EN 16931 document', function () {
    $document = ZugferdDocumentBuilder::createNew(ZugferdProfiles::PROFILE_EN16931);

    expect($document->getContent())->toBeString()->toContain('CrossIndustryInvoice');
});

test('horstoeko/zugferd is a declared composer dependency', function () {
    $composer = json_decode((string) file_get_contents(base_path('composer.json')), true);

    expect($composer['require'])->toHaveKey('horstoeko/zugferd');
});

test('the locked gotenberg php client supports factur-x embedding', function () {
    expect(version_compare(lockedPackageVersion('gotenberg/gotenberg-php'), '2.23.0', '>='))
        ->toBeTrue('gotenberg/gotenberg-php must be locked to >= 2.23 for Factur-X support');
});

test('the gotenberg php client exposes the factur-x api', function () {
    expect(class_exists(FacturX::class))->toBeTrue();
    expect(method_exists(ChromiumPdf::class, 'facturX'))->toBeTrue();
});

test('the installer checks for the extensions the e-invoice xml layer needs', function () {
    expect(config('installer.requirements.php'))
        ->toContain('simplexml')
        ->toContain('fileinfo');
});

test('the development gotenberg image is pinned to a factur-x capable version', function () {
    $tags = devGotenbergImageTags();

    expect($tags)->not->toBeEmpty();

    foreach ($tags as $file => $tag) {
        expect($tag)->not->toBe('', "{$file} does not pin a Gotenberg image");
        expect(version_compare($tag, '8.34', '>='))
            ->toBeTrue("{$file} pins gotenberg/gotenberg:{$tag}, which predates Factur-X support (>= 8.34)");
    }
});
