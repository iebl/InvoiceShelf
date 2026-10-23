<?php

use Symfony\Component\Yaml\Yaml;

/**
 * The conformance workflow's wiring: it provisions a Factur-X capable Gotenberg,
 * both validators and the PDF artifact, and every other test run excludes the
 * `conformance` group. `--exclude-group` on the command line replaces the
 * exclusions in phpunit.xml instead of adding to them, so a job that names any
 * group has to spell the exclusion out again.
 *
 * @see .github/workflows/einvoice-conformance.yaml
 * @see tests/Conformance/HybridPdfConformanceTest.php
 */

/**
 * The workflow definitions, keyed by file name.
 *
 * @return array<string, array<string, mixed>>
 */
function ciWorkflows(): array
{
    $workflows = [];

    foreach (glob(base_path('.github/workflows/*.y*ml')) ?: [] as $file) {
        $workflows[basename($file)] = Yaml::parseFile($file);
    }

    return $workflows;
}

/**
 * Every shell line a workflow runs, flattened.
 *
 * @param  array<string, mixed>  $workflow
 * @return list<string>
 */
function ciCommands(array $workflow): array
{
    $lines = [];

    foreach ($workflow['jobs'] ?? [] as $job) {
        foreach ($job['steps'] ?? [] as $step) {
            foreach (explode("\n", (string) ($step['run'] ?? '')) as $line) {
                if (trim($line) !== '') {
                    $lines[] = trim($line);
                }
            }
        }
    }

    return $lines;
}

test('the conformance job builds against a factur-x capable gotenberg', function () {
    $job = ciWorkflows()['einvoice-conformance.yaml']['jobs']['conformance'];

    $image = (string) ($job['services']['gotenberg']['image'] ?? '');

    expect($image)->toStartWith('gotenberg/gotenberg:');

    $tag = explode(':', $image)[1];

    expect(version_compare($tag, '8.34', '>='))
        ->toBeTrue("The conformance job pins {$image}, which predates Factur-X support (>= 8.34)");
});

test('the conformance job provides everything the conformance suite requires', function () {
    $job = ciWorkflows()['einvoice-conformance.yaml']['jobs']['conformance'];

    expect($job['env'])->toHaveKeys([
        'CONFORMANCE_GOTENBERG_HOST',
        'CONFORMANCE_MUSTANG_JAR',
        'CONFORMANCE_VERAPDF_BIN',
        'CONFORMANCE_ARTIFACT_DIR',
    ]);

    // The validators are pinned so a version bump cannot change the verdict.
    expect($job['env']['MUSTANG_VERSION'])->toMatch('/^\d+\.\d+\.\d+$/')
        ->and($job['env']['VERAPDF_VERSION'])->toMatch('/^\d+\.\d+\.\d+$/');
});

test('the conformance job runs the conformance suite and keeps the document', function () {
    $workflow = ciWorkflows()['einvoice-conformance.yaml'];
    $commands = ciCommands($workflow);

    expect($commands)->toContain('php artisan test --group=conformance');

    // A run that generated nothing must fail. The file name is the one
    // HybridPdfConformanceTest writes.
    expect($commands)->toContain('test -s "$CONFORMANCE_ARTIFACT_DIR/hybrid-invoice.pdf"');

    $uses = array_column($workflow['jobs']['conformance']['steps'], 'uses');

    expect(implode(' ', array_filter($uses)))->toContain('actions/upload-artifact');
});

test('phpunit keeps the conformance group out of every ordinary run', function () {
    $phpunit = simplexml_load_file(base_path('phpunit.xml'));

    $excluded = array_map(
        static fn ($group): string => (string) $group,
        $phpunit->xpath('//groups/exclude/group') ?: [],
    );

    expect($excluded)->toContain('conformance');
});

test('no other job runs the suite in a way that pulls the conformance group in', function () {
    foreach (ciWorkflows() as $file => $workflow) {
        if ($file === 'einvoice-conformance.yaml') {
            continue;
        }

        foreach (ciCommands($workflow) as $command) {
            if (! str_contains($command, 'artisan test') && ! str_contains($command, 'bin/pest')) {
                continue;
            }

            // Naming a group selects only it; otherwise the exclusion has to be
            // spelled out, see the class comment.
            $safe = str_contains($command, '--group=')
                || ! str_contains($command, '--exclude-group=')
                || str_contains($command, '--exclude-group=conformance');

            expect($safe)->toBeTrue("{$file} runs `{$command}`, which would run the conformance tests without a Gotenberg service");

            // A --parallel run ignores the group exclusion for the separate
            // Conformance testsuite, so parallel invocations select their
            // testsuites explicitly.
            if (str_contains($command, '--parallel')) {
                expect(str_contains($command, '--testsuite='))
                    ->toBeTrue("{$file} runs `{$command}` in parallel without pinning --testsuite, which lets the conformance tests in")
                    ->and($command)->not->toContain('Conformance');
            }
        }

        foreach ($workflow['jobs'] ?? [] as $name => $job) {
            expect($job)->not->toHaveKey('services', "{$file} job `{$name}` declares a service container; only the conformance job needs one");
        }
    }
});
