<?php
/**
 * Volcano - PackageInstaller
 *
 * @author  Nicolas Devoy
 * @email   nicolas@volcano-frramework.fr 
 * @version 1.0.0
 * @date    15 Fevrier 2023
 */

namespace Volcano\Composer\Installer;

use Composer\Composer;
use Composer\Installer\LibraryInstaller;
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use Composer\Repository\InstalledRepositoryInterface;
use Composer\Script\Event;
use Composer\Util\Filesystem;

use RuntimeException;


class PackageInstaller extends LibraryInstaller
{
    /**
     * Un drapeau pour vérifier l'utilisation - une fois
     *
     * @var bool
     */
    protected static $checkUsage = true;


    /**
     * Vérifier l'utilisation lors de la construction
     *
     * @param IOInterface $io composer object
     * @param Composer    $composer composer object
     * @param string      $type what are we loading
     * @param Filesystem  $filesystem composer object
     */
    public function __construct(IOInterface $io, Composer $composer, $type = 'library', Filesystem $filesystem = null)
    {
        parent::__construct($io, $composer, $type, $filesystem);

        $this->checkUsage($composer);
    }

    /**
     * Vérifiez que le fichier root composer.json utilise le hook post-autoload-dump
     *
     * Si ce n'est pas le cas, avertissez l'utilisateur qu'il doit mettre à jour le fichier de 
     * composition de son application.
     * Ne rien faire si le projet principal n'est pas un projet (si c'est un plugin en développement).
     *
     * @param Composer $composer object
     * @return void
     */
    public function checkUsage(Composer $composer)
    {
        if (static::$checkUsage === false) {
            return;
        }

        static::$checkUsage = false;

        $package = $composer->getPackage();

        if (! $package || ($package->getType() !== 'project')) {
            return;
        }

        $scripts = $composer->getPackage()->getScripts();

        $postAutoloadDump = 'Volcano\Composer\Installer\PackageInstaller::postAutoloadDump';

        if (! isset($scripts['post-autoload-dump']) || ! in_array($postAutoloadDump, $scripts['post-autoload-dump'])) {
            $this->warnUser(
                'Action required!',
                'Please update your application composer.json file to add the post-autoload-dump hook.'
            );
        }
    }

    /**
     * Avertir le développeur des mesures qu'il doit prendre
     *
     * @param string $title Warning title
     * @param string $text warning text
     *
     * @return void
     */
    public function warnUser($title, $text)
    {
        $wrap = function ($text, $width = 75) {
            return '<error>     ' .str_pad($text, $width) .'</error>';
        };

        $messages = array(
            '',
            '',
            $wrap(''),
            $wrap($title),
            $wrap(''),
        );

        $lines = explode("\n", wordwrap($text, 68));

        foreach ($lines as $line) {
            $messages[] = $wrap($line);
        }

        $messages = array_merge($messages, array($wrap(''), '', ''));

        $this->io->write($messages);
    }

    /**
     * Appelé chaque fois que composer (re) génère l'autoloader
     *
     * Recrée la carte du chemin du package de Volcano, basée sur les informations du compositeur et 
     * les packages d'applications disponibles.
     *
     * @param Event $event the composer event object
     * @return void
     */
    public static function postAutoloadDump(Event $event)
    {
        $composer = $event->getComposer();

        $config = $composer->getConfig();

        $vendorDir = realpath($config->get('vendor-dir'));

        //
        $packages = $composer->getRepositoryManager()->getLocalRepository()->getPackages();

        //
        $packagesDir = dirname($vendorDir) .DIRECTORY_SEPARATOR .'packages';

        $packages = static::determinePlugins($packages, $packagesDir, $vendorDir);

        //
        $configFile = static::getConfigFile($vendorDir);

        static::writeConfigFile($configFile, $packages);
    }

    /**
     * Trouver tous les plugins disponibles
     *
     * Ajouter tous les packages composer de type volcano-package, et tous les plugins situés
     * dans le répertoire des packages vers un tableau de chemins indexé par le nom du package
     *
     * @param array $packages an array of \Composer\Package\PackageInterface objects
     * @param string $pluginsDir the path to the plugins dir
     * @param string $vendorDir the path to the vendor dir
     * @return array plugin-name indexed paths to plugins
     */
    public static function determinePlugins($packages, $packagesDir = 'packages', $vendorDir = 'vendor')
    {
        $results = array();

        foreach ($packages as $package) {
            if ($package->getType() !== 'volcano-package') {
                continue;
            }

            $namespace = static::primaryNamespace($package);

            $path = $vendorDir . DIRECTORY_SEPARATOR . $package->getPrettyName();

            $results[$namespace] = $path;
        }

        if (is_dir($packagesDir)) {
            $iterator = new \DirectoryIterator($packagesDir);

            foreach ($iterator as $info) {
                if (! $info->isDir() || $info->isDot()) {
                    continue;
                }

                $path = $packagesDir . DIRECTORY_SEPARATOR . $info->getFilename();

                // Collecte les informations du composer.json du plugin
                $composerJson = $path . DIRECTORY_SEPARATOR . 'composer.json';

                if (! is_readable($composerJson)) {
                    continue;
                }

                $config = json_decode(file_get_contents($composerJson), true);

                if (is_array($config) && ($config['type'] === 'volcano-package')) {
                    $namespace = static::primaryNamespace($config);

                    $results[$namespace] = $path;
                }
            }
        }

        ksort($results);

        return $results;
    }

    /**
     * Réécrivez le fichier de configuration avec une liste complète des packages
     *
     * @param string $configFile the path to the config file
     * @param array $packages of packages
     * @return void
     */
    public static function writeConfigFile($configFile, $packages)
    {
        $root = dirname(dirname($configFile));

        $data = array();

        foreach ($packages as $name => $packagePath) {
            $packagePath = str_replace(
                DIRECTORY_SEPARATOR . DIRECTORY_SEPARATOR,
                DIRECTORY_SEPARATOR,
                $packagePath
            );

            // Normaliser à *nix chemins.
            $packagePath = str_replace('\\', '/', $packagePath);

            $packagePath .= '/';

            // Les plugins avec espace de noms doivent utiliser /
            $name = str_replace('\\', '/', $name);

            $data[] = sprintf("        '%s' => '%s'", $name, $packagePath);
        }

        $data = implode(",\n", $data);

        if (! empty($data)) {
            $contents = <<<PHP
<?php

\$baseDir = dirname(dirname(__FILE__));

return array(
    'packages' => array(
$data,
    ),
);

PHP;
        } else {
            $contents = <<<'PHP'
<?php

$baseDir = dirname(dirname(__FILE__));

return array(
    'packages' => array(),
);
PHP;
        }

        $root = str_replace(
            DIRECTORY_SEPARATOR . DIRECTORY_SEPARATOR,
            DIRECTORY_SEPARATOR,
            $root
        );

        // Normaliser à *nix chemins.
        $root = str_replace('\\', '/', $root);

        $contents = str_replace('\'' .$root, '$baseDir .\'', $contents);

        file_put_contents($configFile, $contents);
    }

    /**
     * Chemin d'accès au fichier de configuration du package
     *
     * @param string $vendorDir path to composer-vendor dir
     * @return string absolute file path
     */
    protected static function getConfigFile($vendorDir)
    {
        return $vendorDir . DIRECTORY_SEPARATOR . 'volcano-packages.php';
    }

    /**
     * Obtenez l'espace de noms principal pour un package de plug-in.
     *
     * @param \Composer\Package\PackageInterface $package composer object
     * @return string The package's primary namespace.
     * @throws \RuntimeException When the package's primary namespace cannot be determined.
     */
    public static function primaryNamespace($package)
    {
        $namespace = null;

        $autoLoad = ! is_array($package)
            ? $package->getAutoload()
            : (isset($package['autoload']) ? $package['autoload'] : array());

        foreach ($autoLoad as $type => $pathMap) {
            if ($type !== 'psr-4') {
                continue;
            }

            $count = count($pathMap);

            if ($count === 1) {
                $namespace = key($pathMap);

                break;
            }

            $matches = preg_grep('#^(\./)?src/?$#', $pathMap);

            if ($matches) {
                $namespace = key($matches);

                break;
            }

            foreach (array('', '.') as $path) {
                $key = array_search($path, $pathMap, true);

                if ($key !== false) {
                    $namespace = $key;
                }
            }

            break;
        }

        if (is_null($namespace)) {
            throw new RuntimeException(
                sprintf(
                    "Unable to get primary namespace for package %s." .
                    "\nEnsure you have added proper 'autoload' section to your Packages config" .
                    " as stated in README on https://github.com/nico2dev/volcano-installer",
                    ! is_array($package) ? $package->getName() : $package['name']
                )
            );
        }

        return trim($namespace, '\\');
    }

    /**
     * Décide si le programme d'installation prend en charge le type donné.
     *
     * Ce programme d'installation ne prend en charge que les packages de type 'volcano-package'.
     *
     * @return bool
     */
    public function supports($packageType)
    {
        return ('volcano-package' === $packageType);
    }

    /**
     * Installe un package spécifique.
     *
     * Une fois le package installé, le fichier de configuration `volcano-packages.php` de l'application
     * est mis à jour avec espace de noms de package au mappage de chemin.
     *
     * @param \Composer\Repository\InstalledRepositoryInterface $repo Repository in which to check.
     * @param \Composer\Package\PackageInterface $package Package instance.
     * @deprecated superceeded by the post-autoload-dump hook
     */
    public function install(InstalledRepositoryInterface $repo, PackageInterface $package)
    {
        parent::install($repo, $package);

        $path = $this->getInstallPath($package);

        $namespace = static::primaryNamespace($package);

        $version = $package->getVersion();

        $this->updateConfig($namespace, $path, $version);
    }

    /**
     * Mise à jour du package spécifique.
     *
     * Une fois le package installé, le fichier de configuration `nova-packages.php` de l'application est
     * mis à jour avec espace de noms de package au mappage de chemin.
     *
     * @param \Composer\Repository\InstalledRepositoryInterface $repo Repository in which to check.
     * @param \Composer\Package\PackageInterface $initial Already installed package version.
     * @param \Composer\Package\PackageInterface $target Updated version.
     * @deprecated superceeded by the post-autoload-dump hook
     *
     * @throws \InvalidArgumentException if $initial package is not installed
     */
    public function update(InstalledRepositoryInterface $repo, PackageInterface $initial, PackageInterface $target)
    {
        parent::update($repo, $initial, $target);

        $namespace = static::primaryNamespace($initial);

        $this->updateConfig($namespace, null);

        $path = $this->getInstallPath($target);

        $namespace = static::primaryNamespace($target);

        $version = $target->getVersion();

        $this->updateConfig($namespace, $path, $version);
    }

    /**
     * Désinstalle un package spécifique.
     *
     * @param \Composer\Repository\InstalledRepositoryInterface $repo Repository in which to check.
     * @param \Composer\Package\PackageInterface $package Package instance.
     * @deprecated superceeded by the post-autoload-dump hook
     */
    public function uninstall(InstalledRepositoryInterface $repo, PackageInterface $package)
    {
        parent::uninstall($repo, $package);

        $path = $this->getInstallPath($package);

        $namespace = static::primaryNamespace($package);

        $this->updateConfig($namespace, null);
    }

    /**
     * Mettre à jour le chemin du package pour un package donné.
     *
     * @param string $name The plugin name being installed.
     * @param string $path The path, the plugin is being installed into.
     */
    public function updateConfig($name, $path)
    {
        $name = str_replace('\\', '/', $name);

        $configFile = static::getConfigFile($this->vendorDir);

        $this->ensureConfigFile($configFile);

        //
        $return = include $configFile;

        if (is_array($return) && empty($config)) {
            $config = $return;
        }

        if (! isset($config)) {
            $this->io->write(
                'ERROR - `vendor/volcano-packages.php` file is invalid. Packages path configuration not updated.'
            );

            return;
        }

        if (! isset($config['packages'])) {
            $config['packages'] = array();
        }

        if (is_null($path)) {
            unset($config['packages'][$name]);
        } else {
            $config['packages'][$name] = $path;
        }

        $this->writeConfig($configFile, $config);
    }

    /**
     * Assurez-vous que le fichier vendor/volcano-packages.php existe.
     *
     * @param string $path the config file path.
     * @return void
     */
    protected function ensureConfigFile($path)
    {
        if (file_exists($path)) {
            if ($this->io->isVerbose()) {
                $this->io->write('vendor/volcano-packages.php exists.');
            }

            return;
        }

        $contents = <<<'PHP'
<?php

$baseDir = dirname(dirname(__FILE__));

return array(
    'packages' => array(),
);
PHP;
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path));
        }

        file_put_contents($path, $contents);

        if ($this->io->isVerbose()) {
            $this->io->write('Created vendor/volcano-packages.php');
        }
    }

    /**
     * Videz la configuration générée dans un fichier.
     *
     * @param string $path The path to write.
     * @param array $config The config data to write.
     * @param string|null $root The root directory. Defaults to a value generated from $configFile
     * @return void
     */
    protected function writeConfig($path, $config, $root = null)
    {
        $root = $root ?: dirname($this->vendorDir);

        $data = '';

        foreach ($config['packages'] as $name => $packagePath) {
            $packagePath = $properties['path'];

            //
            $data .= sprintf("        '%s' => '%s'", $name, $packagePath);
        }

        if (! empty($data)) {
            $contents = <<<PHP
<?php

\$baseDir = dirname(dirname(__FILE__));

return array(
    'packages' => array(
$data
    )
);

PHP;
        } else {
            $contents = <<<'PHP'
<?php

$baseDir = dirname(dirname(__FILE__));

return array(
    'packages' => array(),
);
PHP;
        }

        $root = str_replace('\\', '/', $root);

        $contents = str_replace('\'' .$root, '$baseDir .\'', $contents);

        file_put_contents($path, $contents);
    }
}
