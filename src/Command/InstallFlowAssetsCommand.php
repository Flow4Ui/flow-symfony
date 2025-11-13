<?php

namespace Flow\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;

#[AsCommand(
    name: 'flow:install-assets',
    description: 'Install or update Flow library assets and dependencies'
)]
class InstallFlowAssetsCommand extends Command
{
    private Filesystem $filesystem;
    private string $projectDir;

    public function __construct(string $projectDir)
    {
        parent::__construct();
        $this->filesystem = new Filesystem();
        $this->projectDir = $projectDir;
    }

    protected function configure(): void
    {
        $this
            ->addArgument(
                'target-path',
                InputArgument::OPTIONAL,
                'Target directory for Flow library installation',
                'assets'
            )
            ->addOption(
                'skip-dependencies',
                null,
                InputOption::VALUE_NONE,
                'Skip dependency installation check'
            )
            ->addOption(
                'force',
                'f',
                InputOption::VALUE_NONE,
                'Force overwrite existing files'
            )
            ->setHelp(<<<'HELP'
The <info>%command.name%</info> command installs or updates the Flow library assets.

Usage:
  <info>php %command.full_name%</info>
  <info>php %command.full_name% custom/path</info>
  <info>php %command.full_name% --skip-dependencies</info>
  <info>php %command.full_name% --force</info>

This command will:
  1. Check for required dependencies (Webpack Encore, Vue.js, Vue Router)
  2. Install missing dependencies automatically
  3. Copy Flow library files to the target directory
HELP
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        $io->title('Flow Library Assets Installation');

        $targetPath = $input->getArgument('target-path');
        $skipDependencies = $input->getOption('skip-dependencies');
        $force = $input->getOption('force');

        // Resolve target path
        $targetDir = $this->projectDir . '/' . $targetPath;
        $flowTargetDir = $targetDir . '/flow';

        $io->section('Configuration');
        $io->writeln(sprintf('Target directory: <info>%s</info>', $targetPath));
        $io->writeln(sprintf('Full path: <info>%s</info>', $flowTargetDir));
        $io->newLine();

        // Check if target directory exists
        if ($this->filesystem->exists($flowTargetDir) && !$force) {
            $io->warning(sprintf('Flow directory already exists at: %s', $flowTargetDir));
            if (!$io->confirm('Do you want to overwrite existing files?', false)) {
                $io->info('Installation cancelled.');
                return Command::SUCCESS;
            }
        }

        // Check and install dependencies
        if (!$skipDependencies) {
            $io->section('Checking Dependencies');
            
            if (!$this->checkDependencies($io)) {
                $io->error('Dependency check failed. Please ensure package.json exists.');
                return Command::FAILURE;
            }
        } else {
            $io->note('Skipping dependency checks as requested.');
        }

        // Copy Flow library files
        $io->section('Installing Flow Library');
        
        if (!$this->copyFlowLibrary($io, $flowTargetDir, $force)) {
            $io->error('Failed to copy Flow library files.');
            return Command::FAILURE;
        }

        $io->success([
            'Flow library assets have been successfully installed!',
            sprintf('Location: %s', $flowTargetDir),
        ]);

        $io->section('Next Steps');
        $io->listing([
            'Import Flow in your JavaScript: import { createFlow } from \'./' . $targetPath . '/flow\';',
            'Configure your webpack.config.js to include the Flow assets',
            'Run "npm run build" or "yarn build" to compile your assets',
        ]);

        return Command::SUCCESS;
    }

    private function checkDependencies(SymfonyStyle $io): bool
    {
        $packageJsonPath = $this->projectDir . '/package.json';

        if (!$this->filesystem->exists($packageJsonPath)) {
            $io->error('package.json not found in project root.');
            return false;
        }

        $packageJson = json_decode(file_get_contents($packageJsonPath), true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            $io->error('Failed to parse package.json: ' . json_last_error_msg());
            return false;
        }

        $dependencies = array_merge(
            $packageJson['dependencies'] ?? [],
            $packageJson['devDependencies'] ?? []
        );

        $requiredPackages = [
            '@symfony/webpack-encore' => 'Webpack Encore',
            'vue' => 'Vue.js',
            'vue-router' => 'Vue Router',
        ];

        $missingPackages = [];

        foreach ($requiredPackages as $package => $name) {
            if (isset($dependencies[$package])) {
                $io->writeln(sprintf('✓ %s is installed (version: %s)', $name, $dependencies[$package]));
            } else {
                $io->writeln(sprintf('✗ %s is <error>NOT</error> installed', $name));
                $missingPackages[] = $package;
            }
        }

        if (!empty($missingPackages)) {
            $io->newLine();
            $io->warning(sprintf('Missing %d required package(s)', count($missingPackages)));
            
            if ($io->confirm('Would you like to install missing dependencies now?', true)) {
                return $this->installMissingDependencies($io, $missingPackages);
            } else {
                $io->note('You can install them manually later using: npm install ' . implode(' ', $missingPackages));
                return true; // Continue anyway
            }
        }

        $io->success('All required dependencies are installed.');
        return true;
    }

    private function installMissingDependencies(SymfonyStyle $io, array $packages): bool
    {
        $io->section('Installing Missing Dependencies');

        // Detect package manager
        $packageManager = $this->detectPackageManager($io);

        if (!$packageManager) {
            $io->error('Could not detect package manager (npm or yarn).');
            return false;
        }

        $io->writeln(sprintf('Using package manager: <info>%s</info>', $packageManager));

        // Prepare install command
        $installCommand = $packageManager === 'yarn'
            ? 'yarn add --dev ' . implode(' ', array_map('escapeshellarg', $packages))
            : 'npm install --save-dev ' . implode(' ', array_map('escapeshellarg', $packages));

        $io->writeln(sprintf('Running: <comment>%s</comment>', $installCommand));
        $io->newLine();

        // Change to project directory and execute command
        $currentDir = getcwd();
        chdir($this->projectDir);

        $output = [];
        $returnCode = 0;
        exec($installCommand . ' 2>&1', $output, $returnCode);

        chdir($currentDir);

        // Display output
        foreach ($output as $line) {
            $io->writeln($line);
        }

        if ($returnCode === 0) {
            $io->success('Dependencies installed successfully!');
            return true;
        } else {
            $io->error('Failed to install dependencies. Exit code: ' . $returnCode);
            return false;
        }
    }

    private function detectPackageManager(SymfonyStyle $io): ?string
    {
        // Check for yarn.lock
        if ($this->filesystem->exists($this->projectDir . '/yarn.lock')) {
            return 'yarn';
        }

        // Check for package-lock.json
        if ($this->filesystem->exists($this->projectDir . '/package-lock.json')) {
            return 'npm';
        }

        // Try to detect which is available
        exec('yarn --version 2>&1', $yarnOutput, $yarnReturnCode);
        if ($yarnReturnCode === 0) {
            return 'yarn';
        }

        exec('npm --version 2>&1', $npmOutput, $npmReturnCode);
        if ($npmReturnCode === 0) {
            return 'npm';
        }

        return null;
    }

    private function copyFlowLibrary(SymfonyStyle $io, string $targetDir, bool $force): bool
    {
        $bundleFlowDir = dirname(__DIR__) . '/../assets/flow';

        if (!$this->filesystem->exists($bundleFlowDir)) {
            $io->error(sprintf('Flow library source not found at: %s', $bundleFlowDir));
            return false;
        }

        try {
            // Create target directory if it doesn't exist
            if (!$this->filesystem->exists($targetDir)) {
                $this->filesystem->mkdir($targetDir);
                $io->writeln(sprintf('Created directory: <info>%s</info>', $targetDir));
            }

            // Get all files in the flow directory
            $files = ['components.js', 'index.js', 'router.js', 'helpers.js'];
            
            foreach ($files as $file) {
                $sourceFile = $bundleFlowDir . '/' . $file;
                $targetFile = $targetDir . '/' . $file;

                if ($this->filesystem->exists($sourceFile)) {
                    $this->filesystem->copy($sourceFile, $targetFile, $force);
                    $io->writeln(sprintf('Copied: <info>%s</info>', $file));
                } else {
                    $io->warning(sprintf('Source file not found: %s', $file));
                }
            }

            $io->success(sprintf('Flow library files copied to: %s', $targetDir));
            return true;
        } catch (\Exception $e) {
            $io->error('Error copying files: ' . $e->getMessage());
            return false;
        }
    }
}

