<?php

declare(strict_types=1);

namespace Tests\Unit\Inventory\Scanner;

use Adheart\Logging\Inventory\Extractor\LogUsageExtractor;
use Adheart\Logging\Inventory\Model\LogUsage;
use Adheart\Logging\Inventory\Model\ScanQuery;
use Adheart\Logging\Inventory\Scanner\LogUsageScanner;
use PHPUnit\Framework\TestCase;

final class LogUsageScannerTest extends TestCase
{
    public function testScanDiscoversPhpFilesAndReportsParseErrors(): void
    {
        $projectDir = $this->createProjectStructure();
        $extractor = new LogUsageExtractor();
        $scanner = new LogUsageScanner($projectDir, $extractor);
        $query = new ScanQuery(
            paths: ['src'],
            pathPrefixes: [],
            excludePathPrefixes: [],
            domainContexts: [],
            loggerNames: [],
            channels: [],
            contextKeys: [],
            severityMin: null,
            onlySeverities: [],
            strictSeverity: false,
            sort: 'message',
            order: 'asc',
            truncate: true,
            view: 'compact',
            contextMaxChars: 0,
            onlyDynamicMessages: false,
            summary: false,
            format: 'table'
        );

        $progressCalls = 0;
        try {
            $result = $scanner->scan(
                $query,
                ['billing'],
                'billing',
                function () use (&$progressCalls): void {
                    $progressCalls++;
                }
            );
        } finally {
            $this->removeDirectory($projectDir);
        }

        self::assertSame(2, $result->scannedFiles);
        self::assertCount(1, $result->usages);
        self::assertInstanceOf(LogUsage::class, $result->usages[0]);
        self::assertSame('ok', $result->usages[0]->message);
        self::assertSame('info', $result->usages[0]->severity);
        self::assertSame('billing', $result->usages[0]->channel);
        self::assertSame('Billing', $result->usages[0]->domain);
        self::assertArrayHasKey('src/Billing/Bad.php', $result->parseErrors);
        self::assertStringContainsString('unexpected', $result->parseErrors['src/Billing/Bad.php']);
        self::assertSame(2, $progressCalls);
    }

    public function testAppliesPathPrefixIncludeFilter(): void
    {
        $projectDir = $this->createProjectStructureWithFiles([
            'src/Billing/Included.php' => "<?php\nLog::info('billing');\n",
            'src/User/ExcludedByPrefix.php' => "<?php\nLog::info('user');\n",
        ]);

        $scanner = new LogUsageScanner($projectDir, new LogUsageExtractor());
        $query = new ScanQuery(
            paths: ['src'],
            pathPrefixes: ['src/Billing'],
            excludePathPrefixes: [],
            domainContexts: [],
            loggerNames: [],
            channels: [],
            contextKeys: [],
            severityMin: null,
            onlySeverities: [],
            strictSeverity: false,
            sort: 'message',
            order: 'asc',
            truncate: true,
            view: 'compact',
            contextMaxChars: 0,
            onlyDynamicMessages: false,
            summary: false,
            format: 'table'
        );

        try {
            $result = $scanner->scan($query, ['billing'], 'billing');
        } finally {
            $this->removeDirectory($projectDir);
        }

        self::assertSame(1, $result->scannedFiles);
        self::assertCount(1, $result->usages);
        self::assertSame('billing', $result->usages[0]->message);
    }

    public function testAppliesExcludePathPrefixFilter(): void
    {
        $projectDir = $this->createProjectStructureWithFiles([
            'src/Billing/Included.php' => "<?php\nLog::info('included');\n",
            'src/Billing/Legacy/Excluded.php' => "<?php\nLog::info('excluded');\n",
        ]);

        $scanner = new LogUsageScanner($projectDir, new LogUsageExtractor());
        $query = new ScanQuery(
            paths: ['src'],
            pathPrefixes: [],
            excludePathPrefixes: ['src/Billing/Legacy'],
            domainContexts: [],
            loggerNames: [],
            channels: [],
            contextKeys: [],
            severityMin: null,
            onlySeverities: [],
            strictSeverity: false,
            sort: 'message',
            order: 'asc',
            truncate: true,
            view: 'compact',
            contextMaxChars: 0,
            onlyDynamicMessages: false,
            summary: false,
            format: 'table'
        );

        try {
            $result = $scanner->scan($query, ['billing'], 'billing');
        } finally {
            $this->removeDirectory($projectDir);
        }

        self::assertSame(1, $result->scannedFiles);
        self::assertCount(1, $result->usages);
        self::assertSame('included', $result->usages[0]->message);
    }

    public function testSkipsHardExcludedDirectoriesEvenWhenScanningProjectRoot(): void
    {
        $projectDir = $this->createProjectStructureWithFiles([
            'src/Billing/Included.php' => "<?php\nLog::info('included');\n",
            'vendor/Package/Skipped.php' => "<?php\nLog::info('vendor');\n",
            'var/cache/test/Skipped.php' => "<?php\nLog::info('cache');\n",
            'node_modules/pkg/Skipped.php' => "<?php\nLog::info('node');\n",
            'var/log/Skipped.php' => "<?php\nLog::info('log');\n",
        ]);

        $scanner = new LogUsageScanner($projectDir, new LogUsageExtractor());
        $query = new ScanQuery(
            paths: ['.'],
            pathPrefixes: [],
            excludePathPrefixes: [],
            domainContexts: [],
            loggerNames: [],
            channels: [],
            contextKeys: [],
            severityMin: null,
            onlySeverities: [],
            strictSeverity: false,
            sort: 'message',
            order: 'asc',
            truncate: true,
            view: 'compact',
            contextMaxChars: 0,
            onlyDynamicMessages: false,
            summary: false,
            format: 'table'
        );

        try {
            $result = $scanner->scan($query, ['billing'], 'billing');
        } finally {
            $this->removeDirectory($projectDir);
        }

        self::assertSame(1, $result->scannedFiles);
        self::assertCount(1, $result->usages);
        self::assertSame('included', $result->usages[0]->message);
        self::assertSame('src/Billing/Included.php', $result->usages[0]->path);
    }

    public function testNormalizesInputPathsAndIgnoresUnknownDirectories(): void
    {
        $projectDir = $this->createProjectStructureWithFiles([
            'src/Billing/Included.php' => "<?php\nLog::info('included');\n",
            'src/User/AlsoIncluded.php' => "<?php\nLog::info('user');\n",
        ]);

        $scanner = new LogUsageScanner($projectDir, new LogUsageExtractor());
        $query = new ScanQuery(
            paths: [
                $projectDir . '/src/Billing',
                '\\src\\User\\',
                'missing/path',
                '   ',
            ],
            pathPrefixes: [],
            excludePathPrefixes: [],
            domainContexts: [],
            loggerNames: [],
            channels: [],
            contextKeys: [],
            severityMin: null,
            onlySeverities: [],
            strictSeverity: false,
            sort: 'message',
            order: 'asc',
            truncate: true,
            view: 'compact',
            contextMaxChars: 0,
            onlyDynamicMessages: false,
            summary: false,
            format: 'table'
        );

        try {
            $result = $scanner->scan($query, ['billing'], 'billing');
        } finally {
            $this->removeDirectory($projectDir);
        }

        self::assertSame(2, $result->scannedFiles);
        self::assertCount(2, $result->usages);
        self::assertSame(
            ['included', 'user'],
            array_map(static fn (LogUsage $usage): string => $usage->message, $result->usages)
        );
    }

    private function createProjectStructure(): string
    {
        $base = sys_get_temp_dir() . '/log-usage-scanner-' . uniqid('', true);
        mkdir($base . '/src/Billing', 0777, true);
        file_put_contents($base . '/src/Billing/Good.php', "<?php\nLog::info('ok');\n");
        file_put_contents($base . '/src/Billing/Bad.php', '<?php invalid');

        return $base;
    }

    /**
     * @param array<string, string> $files
     */
    private function createProjectStructureWithFiles(array $files): string
    {
        $base = sys_get_temp_dir() . '/log-usage-scanner-' . uniqid('', true);
        foreach ($files as $relativePath => $content) {
            $absolutePath = $base . '/' . $relativePath;
            $directory = dirname($absolutePath);
            if (!is_dir($directory)) {
                mkdir($directory, 0777, true);
            }

            file_put_contents($absolutePath, $content);
        }

        return $base;
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $iterator = new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS);
        $files = new \RecursiveIteratorIterator($iterator, \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $fileInfo) {
            if ($fileInfo->isDir()) {
                rmdir($fileInfo->getPathname());
            } else {
                unlink($fileInfo->getPathname());
            }
        }

        rmdir($directory);
    }
}
