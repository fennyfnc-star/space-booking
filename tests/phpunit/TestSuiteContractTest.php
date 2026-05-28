<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class TestSuiteContractTest extends TestCase
{
    public function test_root_tests_directory_is_restricted_to_automated_files(): void
    {
        $testsRoot = dirname(__DIR__);
        $rootFiles = array_values(array_map(
            static fn(\SplFileInfo $file): string => $file->getFilename(),
            iterator_to_array(new \FilesystemIterator($testsRoot, \FilesystemIterator::SKIP_DOTS))
        ));

        sort($rootFiles);

        $expected = [
            'README.md',
            'api.test.ts',
            'bootstrap.php',
            'manual',
            'phpunit',
            'setup-vitest.ts',
        ];
        sort($expected);

        $this->assertSame(
            $expected,
            $rootFiles,
            'Unexpected files were added to tests/ root. Put diagnostics in tests/manual or automated PHPUnit tests in tests/phpunit.'
        );
    }

    public function test_manual_diagnostics_bucket_exists_and_is_non_empty(): void
    {
        $manualDir = dirname(__DIR__) . '/manual';
        $this->assertDirectoryExists($manualDir);

        $phpFiles = glob($manualDir . '/*.php');
        $this->assertIsArray($phpFiles);
        $this->assertNotEmpty($phpFiles, 'Expected manual PHP diagnostics to remain under tests/manual.');
    }
}

