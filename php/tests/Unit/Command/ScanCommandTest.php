<?php

declare(strict_types=1);

namespace Tests\Unit\Command;

use Adheart\Logging\Command\ScanCommand;
use Adheart\Logging\Inventory\Extractor\LogUsageExtractor;
use Adheart\Logging\Inventory\Filter\LogUsageFilterSorter;
use Adheart\Logging\Inventory\LoggerCatalogProvider;
use Adheart\Logging\Inventory\Renderer\LogUsageRenderer;
use Adheart\Logging\Inventory\Scanner\LogUsageScanner;
use Adheart\Logging\Inventory\ScanQueryFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class ScanCommandTest extends TestCase
{
    public function testExecuteReturnsFailureOnInvalidSort(): void
    {
        $command = $this->createCommand(sys_get_temp_dir());
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([
            '--sort' => 'invalid',
        ]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('Invalid --sort value', $tester->getDisplay());
    }

    public function testExecuteReturnsFailureOnInvalidOrder(): void
    {
        $command = $this->createCommand(sys_get_temp_dir());
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([
            '--order' => 'invalid',
        ]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('Invalid --order value', $tester->getDisplay());
    }

    public function testExecuteReturnsFailureOnInvalidFormat(): void
    {
        $command = $this->createCommand(sys_get_temp_dir());
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([
            '--format' => 'invalid',
        ]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('Invalid --format value', $tester->getDisplay());
    }

    public function testExecuteReturnsFailureOnInvalidView(): void
    {
        $command = $this->createCommand(sys_get_temp_dir());
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([
            '--view' => 'invalid',
        ]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('Invalid --view value', $tester->getDisplay());
    }

    public function testListLoggersOptionPrintsTableAndSkipsScan(): void
    {
        $command = $this->createCommand(sys_get_temp_dir(), ['custom' => new \stdClass()]);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([
            '--list-loggers' => true,
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        $output = $tester->getDisplay();
        self::assertStringContainsString('logger.name', $output);
        self::assertStringContainsString('service_id', $output);
        self::assertStringContainsString('custom', $output);
    }

    public function testExecuteRunsScanTableOutputAndDisplaysWarnings(): void
    {
        $projectDir = $this->createProjectStructure([
            'src/Billing/Good.php' => "<?php\nLog::info('ok');\n",
            'src/Billing/Bad.php' => '<?php invalid',
        ]);

        $command = $this->createCommand($projectDir);
        $tester = new CommandTester($command);

        try {
            $exitCode = $tester->execute([
                '--paths' => 'src',
                '--summary' => true,
            ]);
        } finally {
            $this->removeDirectory($projectDir);
        }

        self::assertSame(Command::SUCCESS, $exitCode);
        $display = $tester->getDisplay();
        self::assertStringContainsString('Scanning files...', $display);
        self::assertStringContainsString('Summary', $display);
        self::assertStringContainsString('Total usages:', $display);
        self::assertStringContainsString('Skipped 1 file(s) with parse errors.', $display);
        self::assertStringContainsString('src/Billing/Bad.php', $display);
    }

    public function testExecuteJsonFormatEmitsJsonPayload(): void
    {
        $projectDir = $this->createProjectStructure([
            'src/Billing/Json.php' => "<?php\nLog::error('boom');\n",
        ]);

        $command = $this->createCommand($projectDir);
        $tester = new CommandTester($command);

        try {
            $exitCode = $tester->execute([
                '--paths' => 'src',
                '--format' => 'json',
            ]);
        } finally {
            $this->removeDirectory($projectDir);
        }

        self::assertSame(Command::SUCCESS, $exitCode);
        $display = $tester->getDisplay();
        self::assertStringContainsString('"usages"', $display);
        self::assertStringContainsString('"message": "boom"', $display);
    }

    public function testUsesAppAsDefaultChannelWhenNoSpecificLoggerCanBeResolved(): void
    {
        $projectDir = $this->createProjectStructure([
            'src/Billing/Json.php' => "<?php\nLog::error('boom');\n",
        ]);

        $command = $this->createCommand($projectDir);
        $tester = new CommandTester($command);

        try {
            $exitCode = $tester->execute([
                '--paths' => 'src',
                '--format' => 'json',
            ]);
        } finally {
            $this->removeDirectory($projectDir);
        }

        self::assertSame(Command::SUCCESS, $exitCode);

        $display = $tester->getDisplay();
        self::assertStringContainsString('"channel": "app"', $display);
    }

    private function createCommand(string $projectDir, array $services = []): ScanCommand
    {
        return new ScanCommand(
            new LoggerCatalogProvider($services),
            new ScanQueryFactory(),
            new LogUsageScanner($projectDir, new LogUsageExtractor()),
            new LogUsageFilterSorter(),
            new LogUsageRenderer()
        );
    }

    /**
     * @param array<string, string> $files
     */
    private function createProjectStructure(array $files): string
    {
        $base = sys_get_temp_dir() . '/scan-command-' . uniqid('', true);
        foreach ($files as $path => $contents) {
            $absolute = $base . '/' . $path;
            $directory = dirname($absolute);
            if (!is_dir($directory)) {
                mkdir($directory, 0777, true);
            }
            file_put_contents($absolute, $contents);
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
