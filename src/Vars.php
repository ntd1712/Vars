<?php

/**
 * This file is part of the m1\vars library
 *
 * (c) m1 <hello@milescroxford.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @package     m1/vars
 * @version     0.3.0
 * @author      Miles Croxford <hello@milescroxford.com>
 * @copyright   Copyright (c) Miles Croxford <hello@milescroxford.com>
 * @license     http://github.com/m1/vars/blob/master/LICENSE
 * @link        http://github.com/m1/vars/blob/master/README.MD Documentation
 */

namespace M1\Vars;

use M1\Vars\Cache\CacheProvider;
use M1\Vars\Loader\LoaderProvider;
use M1\Vars\Resource\AbstractResource;
use M1\Vars\Resource\ResourceProvider;
use M1\Vars\Resource\VariableResource;
use M1\Vars\Traits\TransformerTrait;

/**
 * Vars core class
 *
 * @since 0.1.0
 */
class Vars extends AbstractResource
{
    /**
     * Used for to* functions
     */
    use TransformerTrait;

    /**
     * The base path for the Vars config and cache folders
     *
     * @var string $base_path
     */
    private $base_path;

    /**
     * The cache object if the cache is wanted, else false
     *
     * @var \M1\Vars\Cache\CacheProvider $cache
     */
    public $cache;

    /**
     * The default options for Vars
     *
     * @var array $default_options
     */
    private $default_options = array(
        'base_path' => null,
        'cache' => true,
        'cache_path' => null,
        'cache_expire' => 300, // 5 minutes
        'loaders' => array('env', 'ini', 'json', 'php', 'toml', 'yaml', 'xml',)
    );

    /**
     * The loaderProvider for Vars supplies the file loaders and the extensions that are supported
     *
     * @var \M1\Vars\Loader\LoaderProvider $loader
     */
    public $loader;

    /**
     * Have the base and cache paths been set
     *
     * @var bool $paths_loaded
     */
    private $paths_loaded = false;

    /**
     * The imported resources
     *
     * @var array $resources
     */
    private $resources = array();

    /**
     * The words to be replaced in the config files
     *
     * @var array $variables
     */
    private $variables = array();

    /**
     * Creates a new instance of Vars
     *
     * @param string|array $resource The main configuration resource
     * @param array        $options  The options being used for Vars
     */
    public function __construct($resource, $options = array())
    {
        $options = $this->parseOptions($options);
        $this->makeCache($options, $resource);
        $this->makePaths($options);

        if (!$this->cache->checkCache()) {
            $this->makeLoader($options);
            $this->makeVariables($options);

            $resource = new ResourceProvider($this, $resource);
        }

        if ($this->cache->isHit()) {
            $this->loadFromCache();
        } else {
            $resource->mergeParentContent();
            $this->content = $resource->getContent();

            $this->cache->setTime(time());
            $this->cache->makeCache($this);

        }
    }

    /**
     * Parses the options so Vars can use them
     *
     * @param array $options  The options being used for Vars
     *
     * @return array The parsed options
     */
    private function parseOptions(array $options)
    {
        $parsed_options = array_merge($this->default_options, $options);
        $parsed_options['loaders'] = (isset($options['loaders'])) ? $options['loaders'] : $this->default_options['loaders'];
        return $parsed_options;
    }

    /**
     * Makes the CacheProvider with the options
     *
     * @param array        $options  The options being used for Vars
     * @param array|string $resource The main configuration resource
     */
    private function makeCache($options, $resource)
    {
        $cache = new CacheProvider($resource, $options);
        $this->cache = $cache;
    }

    /**
     * Sets the base path if the options have been set and the cache path if the cache path has not been set but the
     * base path has
     *
     * @param array $options The options being used for Vars
     */
    private function makePaths($options)
    {
        $this->setBasePath($options['base_path']);

        if (is_null($options['cache_path']) && !is_null($options['base_path'])) {
            $this->cache->setPath($options['base_path']);
            $this->paths_loaded = true;
        }
    }

    /**
     * Makes the LoaderProvider with the options
     *
     * @param array $options  The options being used for Vars
     */
    private function makeLoader($options)
    {
        $loader = new LoaderProvider($options, $this->default_options['loaders']);
        $this->loader = $loader;
    }

    /**
     * Sets the replacement variables if the option has been set
     *
     * @param array|null $options The options being used for Vars
     */
    private function makeVariables($options)
    {
        if (isset($options['variables'])) {
            $variables = new VariableResource($this, $options['variables']);

            $v = array();

            foreach ($variables->getVariables() as $variable_key => $variable_value) {
                $v["%".$variable_key.'%'] = $variable_value;
            }

            $this->variables = $v;
        }
    }

    /**
     * Loads the cached file into the current class
     */
    private function loadFromCache()
    {
        $this->cache->load();

        $passed_keys = array(
            'base_path',
            'content',
            'extensions',
            'loaders',
            'resources',
            'variables',
        );

        $loaded_vars = get_object_vars($this->cache->getLoadedVars());

        foreach ($loaded_vars as $key => $value) {
            if (in_array($key, $passed_keys)) {
                $this->$key = $value;
            }
        }

        $this->cache->setTime($loaded_vars['cache']->getTime());
    }

    /**
     * Checks if the base and cache paths have been set, if not set then will use the $resource as the base path
     *
     * @param string $resource The resource to use to set the paths if they haven't been set
     */
    public function pathsLoadedCheck($resource)
    {
        if (!$this->paths_loaded) {
            $base_path = $this->getBasePath();

            if (!$base_path) {
                $file = pathinfo(realpath($resource));
                $base_path = $file['dirname'];
                $this->setBasePath($base_path);
            }

            if ($this->cache->getProvide() && !$this->cache->getPath()) {
                $this->cache->setPath($base_path);
            }

            $this->paths_loaded = true;
        }
    }

    /**
     * Get the Vars base path
     *
     * @return string The Vars base path
     */
    public function getBasePath()
    {
        return $this->base_path;
    }

    /**
     * Set the Vars base path
     *
     * @param string $base_path The base path to set
     *
     * @throws \InvalidArgumentException If the base path does not exist or is not writable
     *
     * @return \M1\Vars\Vars
     */
    public function setBasePath($base_path)
    {
        if (is_null($base_path)) {
            return;
        }

        if (!is_dir($base_path)) {
            throw new \InvalidArgumentException(sprintf(
                "'%s' base path does not exist or is not writable",
                $base_path
            ));
        }

        $this->base_path = realpath($base_path);
        return $this;
    }
    
    /**
     * Adds a resource to $this->resources
     *
     * @param string $resource Resource to add to the stack
     *
     * @return int The position of the added resource
     */
    public function addResource($resource)
    {
        $r = realpath($resource);
        $pos = count($this->resources);
        $this->resources[$pos] = $r;
        return $pos;
    }

    /**
     * Updates the string resource with the FileResource
     *
     * @param \M1\Vars\Resource\FileResource $resource The FileResource to add
     * @param int                            $pos      The position of the string resource
     *
     * @return \M1\Vars\Vars
     */
    public function updateResource($resource, $pos)
    {
        $this->resources[$pos] = $resource;
        return $this;
    }

    /**
     * Tests to see if the resource has been imported already -- this is to avoid getting into a infinite loop
     *
     * @param \M1\Vars\Resource\FileResource|string $resource Resource to check
     *
     * @return bool Has resource already been imported
     */
    public function resourceImported($resource)
    {
        $resource = realpath($resource);
        foreach ($this->getResources() as $r) {
            if ((is_a($r, 'M1\Vars\Resource\FileResource') && $resource === $r->getFile()) ||
                (is_string($r) && $resource === $r)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Searches the resource stack for a certain resource
     *
     * @param string $resource The resource to search for
     *
     * @return bool Returns the resource if found
     */
    public function getResource($resource)
    {
        foreach ($this->getResources() as $r) {
            if ($resource === $r->getFilename()) {
                return $r;
            }
        }

        return false;
    }

    /**
     * Returns the imported resources
     *
     * @return array The Vars imported resources
     */
    public function getResources()
    {
        return $this->resources;
    }

    /**
     * Returns the Vars replacement variables
     *
     * @return array The Vars replacement variables
     */
    public function getVariables()
    {
        return $this->variables;
    }

    /**
     * Returns the CacheProvider if set, if not return false
     *
     * @return \M1\Vars\Cache\CacheProvider|false The CacheProvider or false
     */
    public function getCache()
    {
        return $this->cache;
    }
}
