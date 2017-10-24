<?php

namespace Roundcube\Composer;

use Composer\Installer\LibraryInstaller;
use Composer\Package\PackageInterface;
use Composer\Package\Version\VersionParser;
use Composer\Repository\InstalledRepositoryInterface;
use Composer\Semver\Constraint\Constraint as VersionConstraint;
use Composer\Util\ProcessExecutor;

/**
 * @category Plugins
 * @package  PluginInstaller
 * @author   Till Klampaeckel <till@php.net>
 * @author   Thomas Bruederli <thomas@roundcube.net>
 * @author   Laurent Declercq <l.declercq@nuxwin.com>
 * @license  GPL-3.0+
 * @version  GIT: <git_id>
 * @link     http://github.com/roundcube/plugin-installer
 */
class PluginInstaller extends LibraryInstaller
{
    const INSTALLER_TYPE = 'roundcube-plugin';

    /**
     * {@inheritDoc}
     */
    public function getInstallPath(PackageInterface $package)
    {
        static $vendorDir;

        if ($vendorDir === NULL) {
            $vendorDir = $this->getVendorDir();
        }

        return sprintf('%s/%s', $vendorDir, $this->getPluginName($package));
    }

    /**
     * {@inheritDoc}
     */
    public function install(InstalledRepositoryInterface $repo, PackageInterface $package)
    {
        $this->rcubeVersionCheck($package);
        parent::install($repo, $package);

        // post-install: activate plugin in Roundcube config
        $configFile = $this->rcubeConfigFile();
        $pluginName = $this->getPluginName($package);
        $pluginDir = $this->getVendorDir() . DIRECTORY_SEPARATOR . $pluginName;
        $extra = $package->getExtra();
        $pluginName = $this->getPluginName($package);

        if (is_writeable($configFile) && php_sapi_name() == 'cli') {
            $answer = $this->io->askConfirmation("Do you want to activate the $pluginName plugin for the i-MSCP Roundcube Webmail Suite? [n|Y] ", true);
            if (true === $answer) {
                $this->rcubeAlterConfig($pluginName, true);
            }
        }

        // copy config.inc.php.dist -> config.inc.php
        if (is_file($pluginDir . DIRECTORY_SEPARATOR . 'config.inc.php.dist')
            && !is_file($pluginDir . DIRECTORY_SEPARATOR . 'config.inc.php')
            && is_writeable($pluginDir)
        ) {
            $this->io->write("<info>Creating plugin config file</info>");
            copy($pluginDir . DIRECTORY_SEPARATOR . 'config.inc.php.dist', $pluginDir . DIRECTORY_SEPARATOR . 'config.inc.php');
        }

        // initialize database schema
        if (!empty($extra['roundcube']['sql-dir'])
            && ($sqlDir = realpath($pluginDir . DIRECTORY_SEPARATOR . $extra['roundcube']['sql-dir']))
        ) {
            $this->io->write("<info>Running database initialization script for $pluginName</info>");
            system(getcwd() . "/vendor/bin/rcubeinitdb.sh --package=$pluginName --dir=$sqlDir");
        }

        // run post-install script
        if (!empty($extra['roundcube']['post-install-script'])) {
            $this->rcubeRunScript($extra['roundcube']['post-install-script'], $package);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function update(InstalledRepositoryInterface $repo, PackageInterface $initial, PackageInterface $target)
    {
        $this->rcubeVersionCheck($target);
        parent::update($repo, $initial, $target);
        $extra = $target->getExtra();

        // trigger updatedb.sh
        if (!empty($extra['roundcube']['sql-dir'])) {
            $pluginName = $this->getPluginName($target);
            $pluginDir = $this->getVendorDir() . DIRECTORY_SEPARATOR . $pluginName;

            if ($sqlDir = realpath($pluginDir . DIRECTORY_SEPARATOR . $extra['roundcube']['sql-dir'])) {
                $this->io->write("<info>Updating database schema for $pluginName</info>");
                system(getcwd() . "/bin/updatedb.sh --package=$pluginName --dir=$sqlDir", $res);
            }
        }

        // run post-update script
        if (!empty($extra['roundcube']['post-update-script'])) {
            $this->rcubeRunScript($extra['roundcube']['post-update-script'], $target);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function uninstall(InstalledRepositoryInterface $repo, PackageInterface $package)
    {
        parent::uninstall($repo, $package);

        // post-uninstall: deactivate plugin
        $pluginName = $this->getPluginName($package);
        $this->rcubeAlterConfig($pluginName, false);

        // run post-uninstall script
        $extra = $package->getExtra();
        if (!empty($extra['roundcube']['post-uninstall-script'])) {
            $this->rcubeRunScript($extra['roundcube']['post-uninstall-script'], $package);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function supports($packageType)
    {
        return $packageType === self::INSTALLER_TYPE;
    }

    /**
     * Return vendor directory
     *
     * @return string
     */
    public function getVendorDir()
    {
        return getcwd() . '/plugins';
    }

    /**
     * Extract the (valid) plugin name from the package object
     *
     * @param PackageInterface $package
     * @return string
     */
    private function getPluginName(PackageInterface $package)
    {
        @list(, $pluginName) = explode('/', $package->getPrettyName());
        return strtr($pluginName, '-', '_');
    }

    /**
     * Check version requirements from the "extra" block of a package
     * against the local Roundcube version
     *
     * @param PackageInterface $package
     * @throws \Exception
     */
    private function rcubeVersionCheck(PackageInterface $package)
    {
        $parser = new VersionParser;

        // read rcube version from iniset
        $rootDir = getcwd();
        $iniSet = @file_get_contents($rootDir . '/program/include/iniset.php');

        if (preg_match('/define\(.RCMAIL_VERSION.,\s*.([0-9.]+[a-z-]*)?/', $iniSet, $m)) {
            $rcubeVersion = $parser->normalize(str_replace('-git', '.999', $m[1]));
        } else {
            throw new \Exception("Unable to find a Roundcube installation in $rootDir");
        }

        $extra = $package->getExtra();

        if (!empty($extra['roundcube'])) {
            foreach (array('min-version' => '>=', 'max-version' => '<=') as $key => $operator) {
                if (!empty($extra['roundcube'][$key])) {
                    $version = $parser->normalize(str_replace('-git', '.999', $extra['roundcube'][$key]));
                    $constraint = new VersionConstraint($operator, $version);
                    if (!$constraint->versionCompare($rcubeVersion, $version, $operator)) {
                        throw new \Exception(
                            "Version check failed! " . $package->getName()
                            . " requires Roundcube version $operator $version, $rcubeVersion was detected."
                        );
                    }
                }
            }
        }
    }

    /**
     * Add or remove the given plugin to the list of active plugins in the Roundcube config.
     *
     * @param string $pluginName
     * @param bool $add
     * @return bool|int
     */
    private function rcubeAlterConfig($pluginName, $add)
    {
        $configFile = $this->rcubeConfigFile();
        @include($configFile);
        $success = false;
        $varname = '$config';

        if (empty($config) && !empty($rcmail_config)) {
            $config = $rcmail_config;
            $varname = '$rcmail_config';
        }

        if (is_array($config) && is_writeable($configFile)) {
            $configTemplate = @file_get_contents($configFile) ?: '';
            $configPlugins = !empty($config['plugins']) ? ((array)$config['plugins']) : array();
            $activePlugins = $configPlugins;

            if ($add && !in_array($pluginName, $activePlugins)) {
                $activePlugins[] = $pluginName;
            } elseif (!$add && ($i = array_search($pluginName, $activePlugins)) !== false) {
                unset($activePlugins[$i]);
            }

            if ($activePlugins != $configPlugins) {
                $count = 0;
                $varExport = "array(\n\t'" . join("',\n\t'", $activePlugins) . "',\n);";
                $newConfig = preg_replace(
                    "/(\\$varname\['plugins'\])\s+=\s+(.+);/Uims", "\\1 = " . $varExport, $configTemplate, -1, $count
                );

                // 'plugins' option does not exist yet, add it...
                if (!$count) {
                    $varTxt = "\n{$varname}['plugins'] = $varExport;\n";
                    $newConfig = str_replace('?>', $varTxt . '?>', $configTemplate, $count);

                    if (!$count) {
                        $newConfig = $configTemplate . $varTxt;
                    }
                }

                $success = file_put_contents($configFile, $newConfig);
            }
        }

        if ($success && php_sapi_name() == 'cli') {
            $this->io->write("<info>Updated local config at $configFile</info>");
        }

        return $success;
    }

    /**
     * Helper method to get an absolute path to the local Roundcube config file
     *
     * @return bool|string
     */
    private function rcubeConfigFile()
    {
        return realpath(getcwd() . '/config/config.inc.php');
    }

    /**
     * Run the given script file
     *
     * @param $script
     * @param PackageInterface $package
     */
    private function rcubeRunScript($script, PackageInterface $package)
    {
        $pluginName = $this->getPluginName($package);
        $pluginDir = $this->getVendorDir() . DIRECTORY_SEPARATOR . $pluginName;

        // check for executable shell script
        if (($scriptFile = realpath($pluginDir . DIRECTORY_SEPARATOR . $script)) && is_executable($scriptFile)) {
            $script = $scriptFile;
        }

        if ($scriptFile && preg_match('/\.php$/', $scriptFile)) {
            // run PHP script in Roundcube context
            $incdir = realpath(getcwd() . '/program/include');
            include_once($incdir . '/iniset.php');
            include($scriptFile);
        } else {
            // attempt to execute the given string as shell commands
            $process = new ProcessExecutor($this->io);
            $exitCode = $process->execute($script, $output, $pluginDir);
            if ($exitCode !== 0) {
                throw new \RuntimeException('Error executing script: ' . $process->getErrorOutput(), $exitCode);
            }
        }
    }
}
