<?php

namespace portalium\composer;

use Composer\Package\PackageInterface;
use Composer\Installer\LibraryInstaller;
use Composer\Repository\InstalledRepositoryInterface;
use Composer\Util\Filesystem;
use React\Promise\PromiseInterface;


class Installer extends LibraryInstaller
{
    const EXTENSION_FILE = 'yiisoft/extensions.php';


    /**
     * @inheritdoc
     */
    public function supports($packageType)
    {
        return $packageType === 'portalium-core';
    }

    /**
     * @inheritdoc
     */
    public function install(InstalledRepositoryInterface $repo, PackageInterface $package)
    {
        $afterInstall = function () use ($package) {
            $this->addPackage($package);
        };

        // install the package the normal composer way
        $promise = parent::install($repo, $package);

        // Composer v2 might return a promise here
        if ($promise instanceof PromiseInterface) {
            return $promise->then($afterInstall);
        }

        // If not, execute the code right away as parent::install executed synchronously (composer v1, or v2 without async)
        $afterInstall();
    }

    /**
     * @inheritdoc
     */
    public function update(InstalledRepositoryInterface $repo, PackageInterface $initial, PackageInterface $target)
    {
        $afterUpdate = function () use ($initial, $target) {
            print_r($initial);
            $this->removePackage($initial);
            $this->addPackage($target);
        };

        // update the package the normal composer way
        $promise = parent::update($repo, $initial, $target);

        // Composer v2 might return a promise here
        if ($promise instanceof PromiseInterface) {
            return $promise->then($afterUpdate);
        }

        // If not, execute the code right away as parent::update executed synchronously (composer v1, or v2 without async)
        $afterUpdate();
    }

    /**
     * @inheritdoc
     */
    public function uninstall(InstalledRepositoryInterface $repo, PackageInterface $package)
    {
        $afterUninstall = function () use ($package) {
            // remove the package from yiisoft/extensions.php
            $this->removePackage($package);

        };

        // uninstall the package the normal composer way
        $promise = parent::uninstall($repo, $package);

        // Composer v2 might return a promise here
        if ($promise instanceof PromiseInterface) {
            return $promise->then($afterUninstall);
        }

        // If not, execute the code right away as parent::uninstall executed synchronously (composer v1, or v2 without async)
        $afterUninstall();
    }

    protected function addPackage(PackageInterface $package)
    {
        $extension = [
            'name' => $package->getName(),
            'version' => $package->getVersion(),
        ];

        $alias = $this->generateDefaultAlias($package);
        if (!empty($alias)) {
            $extension['alias'] = $alias;
        }

        $extensions = $this->loadExtensions();
        $extensions[$package->getName()] = $extension;
        $this->saveExtensions($extensions);
    }

    protected function generateDefaultAlias(PackageInterface $package)
    {
        $fs = new Filesystem;
        $vendorDir = $fs->normalizePath($this->vendorDir);
        $autoload = $package->getAutoload();

        $aliases = [];

        if (!empty($autoload['psr-0'])) {
            foreach ($autoload['psr-0'] as $name => $path) {
                $name = str_replace('\\', '/', trim($name, '\\'));
                if (!$fs->isAbsolutePath($path)) {
                    $path = $this->vendorDir . '/' . $package->getPrettyName() . '/' . $path;
                }
                $path = $fs->normalizePath($path);
                if (strpos($path . '/', $vendorDir . '/') === 0) {
                    $aliases["@$name"] = '<vendor-dir>' . substr($path, strlen($vendorDir)) . '/' . $name;
                } else {
                    $aliases["@$name"] = $path . '/' . $name;
                }
            }
        }

        if (!empty($autoload['psr-4'])) {
            foreach ($autoload['psr-4'] as $name => $path) {
                if (is_array($path)) {
                    // ignore psr-4 autoload specifications with multiple search paths
                    // we can not convert them into aliases as they are ambiguous
                    continue;
                }
                $name = str_replace('\\', '/', trim($name, '\\'));
                if (!$fs->isAbsolutePath($path)) {
                    $path = $this->vendorDir . '/' . $package->getPrettyName() . '/' . $path;
                }
                $path = $fs->normalizePath($path);
                if (strpos($path . '/', $vendorDir . '/') === 0) {
                    $aliases["@$name"] = '<vendor-dir>' . substr($path, strlen($vendorDir));
                } else {
                    $aliases["@$name"] = $path;
                }
            }
        }

        return $aliases;
    }

    protected function removePackage(PackageInterface $package)
    {
        $packages = $this->loadExtensions();
        unset($packages[$package->getName()]);
        $this->saveExtensions($packages);
    }

    protected function loadExtensions()
    {
        $file = $this->vendorDir . '/' . static::EXTENSION_FILE;
        if (!is_file($file)) {
            return [];
        }
        // invalidate opcache of extensions.php if exists
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($file, true);
        }
        $extensions = require($file);

        $vendorDir = str_replace('\\', '/', $this->vendorDir);
        $n = strlen($vendorDir);

        foreach ($extensions as &$extension) {
            if (isset($extension['alias'])) {
                foreach ($extension['alias'] as $alias => $path) {
                    $path = str_replace('\\', '/', $path);
                    if (strpos($path . '/', $vendorDir . '/') === 0) {
                        $extension['alias'][$alias] = '<vendor-dir>' . substr($path, $n);
                    }
                }
            }
        }

        return $extensions;
    }

    protected function saveExtensions(array $extensions)
    {
        $file = $this->vendorDir . '/' . static::EXTENSION_FILE;
        if (!file_exists(dirname($file))) {
            mkdir(dirname($file), 0777, true);
        }
        $array = str_replace("'<vendor-dir>", '$vendorDir . \'', var_export($extensions, true));
        file_put_contents($file, "<?php\n\n\$vendorDir = dirname(__DIR__);\n\nreturn $array;\n");
        // invalidate opcache of extensions.php if exists
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($file, true);
        }
    }

}
