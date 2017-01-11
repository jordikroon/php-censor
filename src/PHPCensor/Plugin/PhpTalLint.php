<?php
/**
 * PHPCI - Continuous Integration for PHP
 *
 * @copyright    Copyright 2014, Block 8 Limited.
 * @license      https://github.com/Block8/PHPCI/blob/master/LICENSE.md
 * @link         https://www.phptesting.org/
 */

namespace PHPCensor\Plugin;

use PHPCensor;
use PHPCensor\Builder;
use PHPCensor\Model\Build;
use PHPCensor\Plugin;

/**
 * PHPTAL Lint Plugin - Provides access to PHPTAL lint functionality.
 * 
 * @author       Stephen Ball <phpci@stephen.rebelinblue.com>
 * @package      PHPCI
 * @subpackage   Plugins
 */
class PhpTalLint extends Plugin
{
    protected $directories;
    protected $recursive = true;
    protected $suffixes;
    protected $ignore;

    /**
     * @return string
     */
    public static function pluginName()
    {
        return 'php_tal_lint';
    }

    /**
     * @var string The path to a file contain custom phptal_tales_ functions
     */
    protected $tales;

    /**
     * @var int
     */
    protected $allowed_warnings;

    /**
     * @var int
     */
    protected $allowed_errors;

    /**
     * @var array The results of the lint scan
     */
    protected $failedPaths = [];

    /**
     * {@inheritdoc}
     */
    public function __construct(Builder $builder, Build $build, array $options = [])
    {
        parent::__construct($builder, $build, $options);

        $this->directories = [''];
        $this->suffixes = ['zpt'];
        $this->ignore = $this->builder->ignore;

        $this->allowed_warnings = 0;
        $this->allowed_errors = 0;

        if (!empty($options['directory'])) {
            $this->directories = [$options['directory']];
        }

        if (isset($options['suffixes'])) {
            $this->suffixes = (array)$options['suffixes'];
        }
    }

    /**
     * Executes phptal lint
     */
    public function execute()
    {
        $this->builder->quiet = true;
        $this->builder->logExecOutput(false);

        foreach ($this->directories as $dir) {
            $this->lintDirectory($dir);
        }

        $this->builder->quiet = false;
        $this->builder->logExecOutput(true);

        $errors = 0;
        $warnings = 0;

        foreach ($this->failedPaths as $path) {
            if ($path['type'] == 'error') {
                $errors++;
            } else {
                $warnings++;
            }
        }

        $this->build->storeMeta('phptallint-warnings', $warnings);
        $this->build->storeMeta('phptallint-errors', $errors);
        $this->build->storeMeta('phptallint-data', $this->failedPaths);

        $success = true;

        if ($this->allowed_warnings != -1 && $warnings > $this->allowed_warnings) {
            $success = false;
        }

        if ($this->allowed_errors != -1 && $errors > $this->allowed_errors) {
            $success = false;
        }

        return $success;
    }

    /**
     * Lint an item (file or directory) by calling the appropriate method.
     * @param $item
     * @param $itemPath
     * @return bool
     */
    protected function lintItem($item, $itemPath)
    {
        $success = true;

        if ($item->isFile() && in_array(strtolower($item->getExtension()), $this->suffixes)) {
            if (!$this->lintFile($itemPath)) {
                $success = false;
            }
        } elseif ($item->isDir() && $this->recursive && !$this->lintDirectory($itemPath . DIRECTORY_SEPARATOR)) {
            $success = false;
        }

        return $success;
    }

    /**
     * Run phptal lint against a directory of files.
     * @param $path
     * @return bool
     */
    protected function lintDirectory($path)
    {
        $success = true;
        $directory = new \DirectoryIterator($this->builder->buildPath . $path);

        foreach ($directory as $item) {
            if ($item->isDot()) {
                continue;
            }

            $itemPath = $path . $item->getFilename();

            if (in_array($itemPath, $this->ignore)) {
                continue;
            }

            if (!$this->lintItem($item, $itemPath)) {
                $success = false;
            }
        }

        return $success;
    }

    /**
     * Run phptal lint against a specific file.
     * @param $path
     * @return bool
     */
    protected function lintFile($path)
    {
        $success = true;

        list($suffixes, $tales) = $this->getFlags();

        $lint = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR;
        $lint .= 'vendor' . DIRECTORY_SEPARATOR . 'phptal' . DIRECTORY_SEPARATOR . 'phptal' . DIRECTORY_SEPARATOR;
        $lint .= 'tools' . DIRECTORY_SEPARATOR . 'phptal_lint.php';
        $cmd  = '/usr/bin/env php ' . $lint . ' %s %s "%s"';

        $this->builder->executeCommand($cmd, $suffixes, $tales, $this->builder->buildPath . $path);

        $output = $this->builder->getLastOutput();

        if (preg_match('/Found (.+?) (error|warning)/i', $output, $matches)) {
            $rows = explode(PHP_EOL, $output);

            unset($rows[0]);
            unset($rows[1]);
            unset($rows[2]);
            unset($rows[3]);

            foreach ($rows as $row) {
                $name = basename($path);

                $row = str_replace('(use -i to include your custom modifier functions)', '', $row);
                $message = str_replace($name . ': ', '', $row);

                $parts = explode(' (line ', $message);

                $message = trim($parts[0]);
                $line = str_replace(')', '', $parts[1]);

                $this->failedPaths[] = [
                    'file'    => $path,
                    'line'    => $line,
                    'type'    => $matches[2],
                    'message' => $message
                ];
            }

            $success = false;
        }

        return $success;
    }

    /**
     * Process options and produce an arguments string for PHPTAL Lint.
     * @return array
     */
    protected function getFlags()
    {
        $tales = '';
        if (!empty($this->tales)) {
            $tales = ' -i ' . $this->builder->buildPath . $this->tales;
        }

        $suffixes = '';
        if (count($this->suffixes)) {
            $suffixes = ' -e ' . implode(',', $this->suffixes);
        }

        return [$suffixes, $tales];
    }
}
