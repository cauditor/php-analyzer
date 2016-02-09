<?php

namespace Cauditor;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Parser;

/**
 * @author Matthias Mullie <cauditor@mullie.eu>
 * @copyright Copyright (c) 2016, Matthias Mullie. All rights reserved.
 * @license LICENSE MIT
 */
class Config implements \ArrayAccess
{
    /**
     * @var array
     */
    protected $config = array();

    /**
     * @var array
     */
    protected $defaults = array(
        'build_path' => 'build/cauditor',
        'exclude_folders' => array('tests', 'vendor'),
    );

    /**
     * @param string $project Project path.
     * @param string $config  Project path.
     */
    public function __construct($project, $config = null)
    {
        if ($config !== null) {
            $this->config = $this->readConfig($config);
        }
        $this->config += $this->defaults;

        $this->config['path'] = rtrim($project, DIRECTORY_SEPARATOR);

        // *always* exclude some folders - they're not project-specific code and
        // could easily be overlooked when overriding excludes
        $this->config['exclude_folders'][] = 'vendor';
        $this->config['exclude_folders'][] = '.git';
        $this->config['exclude_folders'][] = '.svn';
        $this->config['exclude_folders'] = array_unique($this->config['exclude_folders']);

        $this->config['build_path'] = $this->normalizePath($this->config['build_path']);
        $this->config['exclude_folders'] = $this->normalizePath($this->config['exclude_folders']);
    }

    /**
     * Normalize all relative paths by prefixing them with the project path.
     *
     * @param string|string[] $value
     *
     * @return string|string[]
     */
    protected function normalizePath($value)
    {
        // array of paths = recursive
        if (is_array($value)) {
            foreach ($value as $i => $val) {
                $value[$i] = $this->normalizePath($val);
            }

            return $value;
        }

        // not even a directory in that path, can't be absolute
        $seperator = strpos($value, DIRECTORY_SEPARATOR);
        if ($seperator === false) {
            return $this->config['path'].DIRECTORY_SEPARATOR.$value;
        }

        // Linux-style paths: `/path`
        if ($seperator === 0) {
            return $value;
        }

        // Windows-style paths: `C:/path`
        $proto = strpos($value, ':');
        if ($proto !== false && $proto < $seperator) {
            return $value;
        }

        // probably relative path
        return $this->config['path'].DIRECTORY_SEPARATOR.$value;
    }

    /**
     * @param string $path Path to config file.
     *
     * @return array
     */
    protected function readConfig($path)
    {
        if (!file_exists($path) || !is_file($path) || !is_readable($path)) {
            return array();
        }

        $yaml = new Parser();

        try {
            return (array) $yaml->parse(file_get_contents($path));
        } catch (ParseException $e) {
            return array();
        }
    }

    /**
     * {@inheritdoc.
     */
    public function offsetExists($offset)
    {
        return array_key_exists($offset, $this->config);
    }

    /**
     * {@inheritdoc.
     */
    public function offsetGet($offset)
    {
        return $this->config[$offset];
    }

    /**
     * {@inheritdoc.
     */
    public function offsetSet($offset, $value)
    {
        $this->config[$offset] = $value;
    }

    /**
     * {@inheritdoc.
     */
    public function offsetUnset($offset)
    {
        unset($this->config[$offset]);
    }
}