<?php

namespace Prakashdckap\AwsS3Bundle\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

class SetupAwsS3Command extends Command
{
    protected static $defaultName = 'klizer:setup:aws-s3';

    protected function configure()
    {
        $this->setDescription('Configure Akeneo to use AWS S3 storage automatically');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $projectRoot = dirname(__DIR__, 4);
        $envPath = $projectRoot . '/.env.local';
        
            // Check required composer packages
	    if (!$this->ensureComposerPackageInstalled('league/flysystem-aws-s3-v3', $projectRoot, $output)) {
		return Command::FAILURE;
	    }
	    if (!$this->ensureComposerPackageInstalled('aws/aws-sdk-php', $projectRoot, $output)) {
		return Command::FAILURE;
	    }
        $output->writeln('<info>Configure AWS Connection:</info>');
        $questions = [
            'AWS Access Key ID' => 'AWS_ACCESS_KEY_ID',
            'AWS Secret Access Key' => 'AWS_SECRET_ACCESS_KEY',
            'AWS Region' => 'AWS_REGION',
            'AWS Bucket Name' => 'AWS_BUCKET_NAME',
            'S3 Prefix (e.g. akeneo/)' => 'AWS_PREFIX',
        ];

        $helper = $this->getHelper('question');
        $envContent = file_exists($envPath) ? file_get_contents($envPath) : "";

        foreach ($questions as $label => $key) {
            $question = new Question("$label: ");
            $answer = $helper->ask($input, $output, $question);
            $envContent = preg_replace("/^$key=.*$/m", '', $envContent); // Remove old
            $envContent .= "\n$key=$answer";
        }

        file_put_contents($envPath, $envContent);
        $output->writeln('<info>.env.local updated with AWS credentials</info>');

        $this->writeOneupConfig($projectRoot, $output);
        $this->writeStorageAliases($projectRoot, $output);
        $this->writeServiceAliases($projectRoot, $output);

        // Clear Akeneo cache
        exec('php bin/console cache:clear --env=prod');
        //exec('php bin/console pim:installer:assets --symlink --clean');
        exec('php bin/console cache:warmup --env=prod');
        $output->writeln('<info>Akeneo cache cleared and warmed up</info>');

        return Command::SUCCESS;
    }

    private function writeOneupConfig($projectRoot, OutputInterface $output)
    {
        $output->writeln('oneup config triggered');

        $dir = $projectRoot . '/vendor/akeneo/pim-community-dev/config/packages/prod';
        $filePath = "$dir/oneup_flysystem.yml";

        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
            $output->writeln("Created directory: $dir");
        }

        $yaml = <<<YAML
oneup_flysystem:
    adapters:
        catalog_storage_adapter:
            awss3v3:
                client: aws_s3_client
                bucket: '%env(AWS_BUCKET_NAME)%'
                prefix: '%env(AWS_PREFIX)%/catalog'
                options:
                    visibility: 'public'

        jobs_storage_adapter:
            awss3v3:
                client: aws_s3_client
                bucket: '%env(AWS_BUCKET_NAME)%'
                prefix: '%env(AWS_PREFIX)%/jobs'
                options:
                    visibility: 'public'

        archivist_adapter:
            awss3v3:
                client: aws_s3_client
                bucket: '%env(AWS_BUCKET_NAME)%'
                prefix: '%env(AWS_PREFIX)%/archive'
                options:
                    visibility: 'public'

        category_storage_adapter:
            awss3v3:
                client: aws_s3_client
                bucket: '%env(AWS_BUCKET_NAME)%'
                prefix: '%env(AWS_PREFIX)%/category'
                options:
                    visibility: 'public'

        catalogs_mapping_adapter:
            awss3v3:
                client: aws_s3_client
                bucket: '%env(AWS_BUCKET_NAME)%'
                prefix: '%env(AWS_PREFIX)%/catalogs_mapping'
                options:
                    visibility: 'public'

        local_storage_adapter:
            local:
                location: '%kernel.project_dir%/var/storage'

    filesystems:
        catalog_storage:
            adapter: 'catalog_storage_adapter'
            mount: 'catalogStorage'

        jobs_storage:
            adapter: 'jobs_storage_adapter'
            mount: 'jobsStorage'

        archivist:
            adapter: 'archivist_adapter'
            mount: 'archivist'

        category_storage:
            adapter: 'category_storage_adapter'
            mount: 'categoryStorage'

        catalogs_mapping:
            adapter: 'catalogs_mapping_adapter'
            mount: 'catalogsMapping'

        local_storage:
            adapter: 'local_storage_adapter'
            mount: 'localStorage'
YAML;

        file_put_contents($filePath, $yaml);
        $output->writeln("oneup_flysystem.yml written to: $filePath");
    }

    private function writeStorageAliases($projectRoot, OutputInterface $output)
    {
        $output->writeln('storage file triggered');

        $dir = $projectRoot . '/vendor/akeneo/pim-community-dev/config/services/prod';
        $filePath = "$dir/storage.yml";

        $yaml = <<<YAML
services:
    Aws\S3\S3Client:
        arguments:
            -
                version: 'latest'
                region: "%env(AWS_REGION)%"
                credentials:
                    key: "%env(AWS_ACCESS_KEY_ID)%"
                    secret: "%env(AWS_SECRET_ACCESS_KEY)%"
YAML;

        file_put_contents($filePath, $yaml);
        $output->writeln("storage.yml written to: $filePath");
    }

private function writeServiceAliases($projectRoot, OutputInterface $output)
{
    $output->writeln('service file triggered');

    $dir = $projectRoot . '/vendor/akeneo/pim-community-dev/config/services';
    $filePath = "$dir/services.yml";

    $aliasBlock = <<<YAML
    aws_s3_client:
        alias: Aws\\S3\\S3Client
YAML;

    $existingContent = file_exists($filePath) ? file_get_contents($filePath) : '';

    if (str_contains($existingContent, 'aws_s3_client:')) {
        $output->writeln("services.yml already contains aws_s3_client alias, skipped writing.");
        return;
    }

    if (preg_match('/^services:\s*$/m', $existingContent) || preg_match('/^services:\s*\n/m', $existingContent)) {
        // Add under existing services block
        $lines = explode("\n", $existingContent);
        $newLines = [];
        $servicesFound = false;

        foreach ($lines as $line) {
            $newLines[] = $line;
            if (preg_match('/^services:\s*$/', $line)) {
                $servicesFound = true;
            } elseif ($servicesFound && trim($line) !== '' && !str_starts_with($line, ' ')) {
                // Found a top-level next block, insert before it
                $newLines[] = rtrim($aliasBlock);
                $servicesFound = false; // reset
            }
        }

        // If still inside services block at end of file
        if ($servicesFound) {
            $newLines[] = rtrim($aliasBlock);
        }

        $updatedContent = implode("\n", $newLines);
        file_put_contents($filePath, $updatedContent);
        $output->writeln("services.yml updated by inserting aws_s3_client alias.");
    } else {
        // No services block found, create one
        $block = <<<YAML
services:
$aliasBlock
YAML;
        file_put_contents($filePath, $existingContent . "\n\n" . $block);
        $output->writeln("services.yml created with services block and aws_s3_client alias.");
    }
}

private function ensureComposerPackageInstalled(string $packageName, string $projectRoot, OutputInterface $output): bool
{
    $composerLockPath = $projectRoot . '/composer.lock';
    $isInstalled = false;

    if (file_exists($composerLockPath)) {
        $composerLock = json_decode(file_get_contents($composerLockPath), true);
        foreach ($composerLock['packages'] ?? [] as $package) {
            if ($package['name'] === $packageName) {
                $isInstalled = true;
                break;
            }
        }
    }

    if (!$isInstalled) {
        $output->writeln("<comment>Package \"$packageName\" not found. Installing...</comment>");
        $cmd = "composer require $packageName";
        $process = proc_open($cmd, [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, $projectRoot);

        if (is_resource($process)) {
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);

            $returnCode = proc_close($process);
            if ($returnCode !== 0) {
                $output->writeln("<error>Failed to install $packageName:</error> $stderr");
                return false;
            } else {
                $output->writeln("<info>Successfully installed $packageName</info>");
                return true;
            }
        }
    } else {
        $output->writeln("<info>$packageName is already installed.</info>");
    }

    return true;
}

}

