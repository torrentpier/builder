<?php

namespace TPBuilder\Command;

use Alchemy\Zippy\Zippy;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Exception\RuntimeException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;
use Symfony\Component\Process\Process;

class BuildCommand extends Command
{
    /**
     * @inheritdoc
     * @throws InvalidArgumentException
     */
    protected function configure()
    {
        $this
            ->setName('build')
            ->setDescription('Build TorrentPier project base composer')
            ->addArgument('tag', InputArgument::OPTIONAL, 'Set branch or tag', 'master')
        ;
    }

    /**
     * @inheritdoc
     * @throws RuntimeException
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        try {
            $cPath = $this->getComposerPharPath();
            $wDir = $this->createDirectoryForProject($input);

            $this->createProject($output, $input, $cPath, $wDir);
            $this->optimizationAutoloader($output, $cPath, $wDir);

            $this->clearVendorDirectory($output, $input, $wDir);
            $this->clearProjectDirectory($output, $input, $wDir);

            chdir(ROOT_PATH . '/build');

            $tag = $input->getArgument('tag');

            $zip = Zippy::load();
            $zip->create(realpath('./') . '/build-' . $tag . '.zip', $wDir, true, 'zip');

            $this->getFs()->remove($wDir);

            $output->writeln('Done!');
        } catch (\Exception $e) {
            throw new RuntimeException($e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * Creates a working directory in which the project will be created and returned path to it.
     *
     * @param InputInterface $input
     * @return string
     */
    protected function createDirectoryForProject(InputInterface $input)
    {
        try {
            $tag = $input->getArgument('tag');

            if ($tag === 'master') {
                $directory = ROOT_PATH . '/build/master-' . date('Ymd-His');
            } else {
                $directory = ROOT_PATH . '/build/' . $tag;
            }


            $this->getFs()->mkdir($directory);
        } catch (\Exception $e) {
            throw new RuntimeException($e->getMessage(), $e->getCode(), $e);
        }

        return $directory;
    }

    /**
     * Finds and returns the path to composer.
     *
     * @return string
     * @throws RuntimeException
     */
    protected function getComposerPharPath()
    {
        return ROOT_PATH . '/composer.phar';
    }

    /**
     * Create project.
     *
     * @param OutputInterface $output
     * @param InputInterface  $input
     * @param string $composerPath
     * @param string $workDirectory
     */
    protected function createProject(OutputInterface $output, InputInterface $input, $composerPath, $workDirectory)
    {
        try {
            $tag = $input->getArgument('tag');

            $command = [
                'php',
                $composerPath,
                'create-project',
                '--stability="dev"',
                '--prefer-source',
                '--prefer-dist',
                '--no-dev',
                '--no-progress',
                '--no-interaction',
                '--profile',
                '--keep-vcs',
                'torrentpier/torrentpier',
                $workDirectory,
                $tag === 'master' ? 'dev-master' : $tag,
            ];

            $process = new Process(implode(' ', $command), $workDirectory);
            $process->setTimeout(600);
            $this->getHelper('process')->mustRun($output, $process);
        } catch (\Exception $e) {
            throw new RuntimeException($e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * @param OutputInterface $output
     * @param string          $composerPath
     * @param string          $workDirectory
     * @throws RuntimeException
     */
    protected function optimizationAutoloader(OutputInterface $output, $composerPath, $workDirectory)
    {
        $command = [
            'php',
            $composerPath,
            'dump-autoload',
            '-a',
            '-o',
            '--no-dev',
            '--no-scripts',
            '--no-interaction',
            '--profile',
        ];

        try {
            $process = new Process(implode(' ', $command), $workDirectory);
            $process->setTimeout(600);
            $this->getHelper('process')->mustRun($output, $process);
        } catch (\Exception $e) {
            throw new RuntimeException($e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * Clean up the project directory.
     *
     * @param OutputInterface $output
     * @param InputInterface  $input
     * @param string          $workDirectory
     */
    protected function clearProjectDirectory(OutputInterface $output, InputInterface $input, $workDirectory)
    {
        try {
            $output->writeln('Beginning clean up the project directory.');

            foreach ((array)$this->getRuleData($input)['project'] as $rules) {
                $finder = $files = Finder::create()
                    ->in($workDirectory)
                    ->ignoreDotFiles(false);

                if (isset($rules['include'])) {
                    $finder->notPath($this->createRegExpRuleFileName($rules['include']));
                }

                if (isset($rules['execute'])) {
                    $finder->path($this->createRegExpRuleFileName($rules['execute']));
                }

                foreach ($finder->getIterator() as $file) {
                    if ($file->isFile()) {
                        $this->getFs()->remove($file);
                    }
                }
            }

            $output->writeln('Cleanup of project directory is completed.');
        } catch (\Exception $e) {
            throw new RuntimeException($e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * Clean up the vendor directory.
     *
     * @param OutputInterface $output
     * @param string          $workDirectory
     * @throws RuntimeException
     */
    protected function clearVendorDirectory(OutputInterface $output, InputInterface $input, $workDirectory)
    {
        try {
            $output->writeln('Beginning clean up the vendor directory.');

            foreach ((array)$this->getRuleData($input)['vendor'] as $package => $rules) {
                $finder = $files = Finder::create()
                    ->in($workDirectory . '/vendor/' . $package)
                    ->ignoreDotFiles(false);

                if (isset($rules['include'])) {
                    $finder->notPath($this->createRegExpRuleFileName($rules['include']));
                }

                if (isset($rules['execute'])) {
                    $finder->path($this->createRegExpRuleFileName($rules['execute']));
                }

                foreach ($finder->getIterator() as $file) {
                    if ($file->isFile()) {
                        $this->getFs()->remove($file);
                    }
                }

                $finder->sort(function (SplFileInfo $a, SplFileInfo $b) {
                    $aNestingCount = substr_count($a->getRelativePathname(), '/');
                    $bNestingCount = substr_count($b->getRelativePathname(), '/');

                    if ($aNestingCount < $bNestingCount) {
                        return 1;
                    }

                    if ($aNestingCount > $bNestingCount) {
                        return -1;
                    }

                    return 0;
                });

                foreach ($finder->getIterator() as $file) {
                    if ($file->isDir() && count(glob($file->getRealPath() . '/*')) === 0) {
                        $this->getFs()->remove($file);
                    }
                }
            }

            $output->writeln('Cleanup of vendor directory is completed.');
        } catch (\Exception $e) {
            throw new RuntimeException($e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * @param array $files
     * @return string
     */
    protected function createRegExpRuleFileName(array $files)
    {
        return '#^(' . str_replace(['.', '_', '-'], ['\.', '\_', '\-'], implode('|', $files)) . ')#';
    }

    /**
     * @param InputInterface $input
     * @return array
     */
    protected function getRuleData(InputInterface $input)
    {
        static $rules;

        if (!$rules) {

            $tag = $input->getArgument('tag');
            $fileRule = '';
            if ($tag !== 'master') {
                $fileRule = ROOT_PATH . '/resources/rule.' . $tag . '.json';
                if (!file_exists($fileRule)) {
                    $fileRule = '';
                }
            }

            if (!$fileRule) {
                $fileRule = ROOT_PATH . '/resources/rule.master.json';
            }

            $rules = json_decode(file_get_contents($fileRule), true);
        }

        return $rules;
    }

    /**
     * @return Filesystem
     */
    protected function getFs()
    {
        static $fs;

        if (!$fs) {
            $fs = new Filesystem();
        }

        return $fs;
    }
}
