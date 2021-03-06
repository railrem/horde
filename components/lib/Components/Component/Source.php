<?php
/**
 * Copyright 2011-2017 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file COPYING for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category Horde
 * @package  Components
 * @author   Gunnar Wrobel <wrobel@pardus.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 * @link     http://pear.horde.org/index.php?package=Components
 */

/**
 * Represents a source component.
 *
 * @category Horde
 * @package  Components
 * @author   Gunnar Wrobel <wrobel@pardus.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 * @link     http://pear.horde.org/index.php?package=Components
 */
class Components_Component_Source extends Components_Component_Base
{
    /**
     * Path to the source directory.
     *
     * @var string
     */
    private $_directory;

    /**
     * The package file representing the component.
     *
     * @var Horde_Pear_Package_Xml
     */
    private $_package;

    /**
     * The PEAR package file representing the component.
     *
     * @var PEAR_PackageFile
     */
    private $_package_file;

    /**
     * Constructor.
     *
     * @param string                  $directory Path to the source directory.
     * @param boolean                 $shift     Did identification of the
     *                                           component consume an argument?
     * @param Components_Config       $config    The configuration for the
     *                                           current job.
     * @param Components_Component_Factory $factory Generator for additional
     *                                              helpers.
     */
    public function __construct(
        $directory,
        Components_Config $config,
        Components_Component_Factory $factory
    )
    {
        $this->_directory = realpath($directory);
        parent::__construct($config, $factory);
    }

    /**
     * Return a data array with the most relevant information about this
     * component.
     *
     * @return array Information about this component.
     */
    public function getData()
    {
        $data = new stdClass;
        $package = $this->getPackageXml();
        $data->name = $package->getName();
        $data->summary = $package->getSummary();
        $data->description = $package->getDescription();
        $data->version = $package->getVersion();
        $data->releaseDate = $package->getDate()
            . ' ' . $package->getNodeText('/p:package/p:time');
        $data->download = sprintf('http://pear.horde.org/get/%s-%s.tgz',
                                  $data->name, $data->version);
        $data->hasCi = $this->_hasCi();
        return $data;
    }

    /**
     * Indicate if the component has a local package.xml.
     *
     * @return boolean True if a package.xml exists.
     */
    public function hasLocalPackageXml()
    {
        return file_exists($this->getPackageXmlPath());
    }

    /**
     * Returns the link to the change log.
     *
     * @param Components_Helper_ChangeLog $helper  The change log helper.
     *
     * @return string|null The link to the change log.
     */
    public function getChangelog($helper)
    {
        $base = $this->getFactory()->getGitRoot()->getRoot();
        return $helper->getChangelog(
            preg_replace(
                '#^' . $base . '#', '', realpath($this->_directory)
            ),
            $this->_directory
        );
    }

    /**
     * Return the path to the release notes.
     *
     * @return string|boolean The path to the release notes or false.
     */
    public function getReleaseNotesPath()
    {
        foreach (array('release.yml', 'RELEASE_NOTES') as $file) {
            foreach (array('docs', 'doc') as $directory) {
                $path = $this->_directory . '/' . $directory . '/' . $file;
                if (file_exists($path)) {
                    return $path;
                }
            }
        }
        return false;
    }

    /**
     * Return the path to a DOCS_ORIGIN file within the component.
     *
     * @return array|NULL An array containing the path name and the component
     *                    base directory or NULL if there is no DOCS_ORIGIN
     *                    file.
     */
    public function getDocumentOrigin()
    {
        foreach (array('doc', 'docs') as $doc_dir) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->_directory . '/' . $doc_dir)) as $file) {
                if ($file->isFile() &&
                    $file->getFilename() == 'DOCS_ORIGIN') {
                    return array($file->getPathname(), $this->_directory);
                }
            }
        }
    }

    /**
     * Updates the package.xml file for this component.
     *
     * @param string $action   The action to perform. Either "update", "diff",
     *                         or "print".
     * @param array  $options  Options for this operation.
     */
    public function updatePackageXml($action, $options)
    {
        if (!file_exists($this->getPackageXmlPath())) {
            if (!empty($options['theme'])) {
                $this->getFactory()->createThemePackageFile($this->_directory);
            } else {
                $this->getFactory()->createPackageFile($this->_directory);
            }
        }

        $package_xml = $this->getPackageXml();

        /* Skip updating if this is a PECL package. */
        if (!$package_xml->findNode('/p:package/p:providesextension')) {
            $package_xml->updateContents(
                !empty($options['theme'])
                    ? $this->getFactory()->createThemeContentList($this->_directory)
                    : $this->getFactory()->createContentList($this->_directory),
                $options
            );
        }

        switch($action) {
        case 'print':
            return (string) $package_xml;
        case 'diff':
            $new = (string) $package_xml;
            $old = file_get_contents($this->getPackageXmlPath());
            $renderer = new Horde_Text_Diff_Renderer_Unified();
            return $renderer->render(
                new Horde_Text_Diff(
                    'auto', array(explode("\n", $old), explode("\n", $new))
                )
            );
        default:
            file_put_contents($this->getPackageXmlPath(), (string) $package_xml);
            if (!empty($options['commit'])) {
                $options['commit']->add(
                    $this->getPackageXmlPath(), $this->_directory
                );
            }
            return true;
        }
    }

    /**
     * Update the component changelog.
     *
     * @param string                      $log     The log entry.
     * @param Components_Helper_ChangeLog $helper  The change log helper.
     * @param array                       $options Options for the operation.
     *
     * @return NULL
     */
    public function changed(
        $log, Components_Helper_ChangeLog $helper, $options
    )
    {
        if (empty($options['nopackage'])) {
            $file = $helper->packageXml(
                $log, $this->getPackageXml(), $this->getPackageXmlPath(), $options
            );
            if ($file && !empty($options['commit'])) {
                $options['commit']->add($file, $this->_directory);
            }
        }

        if (empty($options['nochanges'])) {
            $file = $helper->changes($log, $this->_directory, $options);
            if ($file && !empty($options['commit'])) {
                $options['commit']->add($file, $this->_directory);
            }
        }
    }

    /**
     * Timestamp the package.xml file with the current time.
     *
     * @param array $options Options for the operation.
     *
     * @return string The success message.
     */
    public function timestampAndSync($options)
    {
        if (empty($options['pretend'])) {
            $package = $this->getPackageXml();
            $package->timestamp();
            $package->syncCurrentVersion();
            file_put_contents($this->getPackageXmlPath(), (string) $package);
            $result = sprintf(
                'Marked package.xml "%s" with current timestamp and synchronized the change log.',
                $this->getPackageXmlPath()
            );
        } else {
            $result = sprintf(
                'Would timestamp "%s" now and synchronize its change log.',
                $this->getPackageXmlPath()
            );
        }
        if (!empty($options['commit'])) {
            $options['commit']->add(
                $this->getPackageXmlPath(), $this->_directory
            );
        }
        return $result;
    }

    /**
     * Updates the composer.json file.
     *
     * @param array $options Options for the operation.
     *
     * @return string The success message.
     */
    public function updateComposer($options)
    {
        if (empty($options['pretend'])) {
            $composer = new Components_Helper_Composer();
            $composer->generateComposeJson(
                $this->getPackageXmlPath()
            );
            $result = 'Updated composer.json.';
        } else {
            $result = 'Would update composer.json now.';
        }
        if (!empty($options['commit'])) {
            $options['commit']->add(
                $this->_directory . '/composer.json', $this->_directory
            );
        }
        return $result;
    }

    /**
     * Set the version in the package.xml
     *
     * @param string $rel_version The new release version number.
     * @param string $api_version The new api version number.
     * @param array  $options     Options for the operation.
     *
     * @return NULL
     */
    public function setVersion(
        $rel_version = null, $api_version = null, $options = array()
    )
    {
        if (empty($options['pretend'])) {
            $package = $this->getPackageXml();
            $package->setVersion($rel_version, $api_version);
            file_put_contents($this->getPackageXmlPath(), (string) $package);
            if (!empty($options['commit'])) {
                $options['commit']->add(
                    $this->getPackageXmlPath(), $this->_directory
                );
            }
            $result = sprintf(
                'Set release version "%s" and api version "%s" in %s.',
                $rel_version,
                $api_version,
                $this->getPackageXmlPath()
            );
        } else {
            $result = sprintf(
                'Would set release version "%s" and api version "%s" in %s now.',
                $rel_version,
                $api_version,
                $this->getPackageXmlPath()
            );
        }
        return $result;
    }

    /**
     * Sets the state in the package.xml
     *
     * @param string $rel_state  The new release state.
     * @param string $api_state  The new api state.
     */
    public function setState(
        $rel_state = null, $api_state = null, $options = array()
    )
    {
        if (empty($options['pretend'])) {
            $package = $this->getPackageXml();
            $package->setState($rel_state, $api_state);
            file_put_contents($this->getPackageXmlPath(), (string) $package);
            if (!empty($options['commit'])) {
                $options['commit']->add(
                    $this->getPackageXmlPath(), $this->_directory
                );
            }
            $result = sprintf(
                'Set release state "%s" and api state "%s" in %s.',
                $rel_state,
                $api_state,
                $this->getPackageXmlPath()
            );
        } else {
            $result = sprintf(
                'Would set release state "%s" and api state "%s" in %s now.',
                $rel_state,
                $api_state,
                $this->getPackageXmlPath()
            );
        }
        return $result;
    }

    /**
     * Add the next version to the package.xml.
     *
     * @param string $version           The new version number.
     * @param string $initial_note      The text for the initial note.
     * @param string $stability_api     The API stability for the next release.
     * @param string $stability_release The stability for the next release.
     * @param array $options Options for the operation.
     *
     * @return NULL
     */
    public function nextVersion(
        $version,
        $initial_note,
        $stability_api = null,
        $stability_release = null,
        $options = array()
    ) {
        if (empty($options['pretend'])) {
            $package = $this->getPackageXml();
            $package->addNextVersion(
                $version, $initial_note, $stability_api, $stability_release
            );
            file_put_contents($this->getPackageXmlPath(), (string) $package);
            $result = sprintf(
                'Added next version "%s" with the initial note "%s" to %s.',
                $version,
                $initial_note,
                $this->getPackageXmlPath()
            );
        } else {
            $result = sprintf(
                'Would add next version "%s" with the initial note "%s" to %s now.',
                $version,
                $initial_note,
                $this->getPackageXmlPath()
            );
        }
        if ($stability_release !== null) {
            $result .= ' Release stability: "' . $stability_release . '".';
        }
        if ($stability_api !== null) {
            $result .= ' API stability: "' . $stability_api . '".';
        }

        if (!empty($options['commit'])) {
            $options['commit']->add(
                $this->getPackageXmlPath(), $this->_directory
            );
        }
        return $result;
    }

    /**
     * Replace the current sentinel.
     *
     * @param string $changes New version for the CHANGES file.
     * @param string $app     New version for the Application.php file.
     * @param array  $options Options for the operation.
     *
     * @return string The success message.
     */
    public function currentSentinel($changes, $app, $options)
    {
        $sentinel = $this->getFactory()->createSentinel($this->_directory);
        if (empty($options['pretend'])) {
            $sentinel->replaceChanges($changes);
            $sentinel->updateApplication($app);
            $action = 'Did';
        } else {
            $action = 'Would';
        }
        $files = array(
            'changes' => $sentinel->changesFileExists(),
            'app'     => $sentinel->applicationFileExists(),
            'bundle'  => $sentinel->bundleFileExists()
        );
        $result = array();
        foreach ($files as $key => $file) {
            if (empty($file)) {
                continue;
            }
            if (!empty($options['commit'])) {
                $options['commit']->add($file, $this->_directory);
            }
            $version = ($key == 'changes') ? $changes : $app;
            $result[] = sprintf(
                '%s replace sentinel in %s with "%s" now.',
                $action,
                $file,
                $version
            );
        }
        return $result;
    }

    /**
     * Set the next sentinel.
     *
     * @param string $changes New version for the CHANGES file.
     * @param string $app     New version for the Application.php file.
     * @param array  $options Options for the operation.
     *
     * @return string The success message.
     */
    public function nextSentinel($changes, $app, $options)
    {
        $sentinel = $this->getFactory()->createSentinel($this->_directory);
        if (empty($options['pretend'])) {
            $sentinel->updateChanges($changes);
            $sentinel->updateApplication($app);
            $action = 'Did';
        } else {
            $action = 'Would';
        }
        $files = array(
            'changes' => $sentinel->changesFileExists(),
            'app'     => $sentinel->applicationFileExists(),
            'bundle'  => $sentinel->bundleFileExists()
        );
        $result = array();
        foreach ($files as $key => $file) {
            if (empty($file)) {
                continue;
            }
            if (!empty($options['commit'])) {
                $options['commit']->add($file, $this->_directory);
            }
            $version = ($key == 'changes') ? $changes : $app;
            $task = ($key == 'changes') ? 'extend' : 'replace';
            $result[] = sprintf(
                '%s %s sentinel in %s with "%s" now.',
                $action,
                $task,
                $file,
                $version
            );
        }
        return $result;
    }

    /**
     * Tag the component.
     *
     * @param string                   $tag     Tag name.
     * @param string                   $message Tag message.
     * @param Components_Helper_Commit $commit  The commit helper.
     *
     * @return NULL
     */
    public function tag($tag, $message, $commit)
    {
        $commit->tag($tag, $message, $this->_directory);
    }

    /**
     * Place the component source archive at the specified location.
     *
     * @param string $destination The path to write the archive to.
     * @param array  $options     Options for the operation.
     *
     * @return array An array with at least [0] the path to the resulting
     *               archive, optionally [1] an array of error strings, and [2]
     *               PEAR output.
     */
    public function placeArchive($destination, $options = array())
    {
        if (!file_exists($this->getPackageXmlPath())) {
            throw new Components_Exception(
                sprintf(
                    'The component "%s" still lacks a package.xml file at "%s"!',
                    $this->getName(),
                    $this->getPackageXmlPath()
                )
            );
        }

        if (empty($options['keep_version'])) {
            $version = preg_replace(
                '/([.0-9]+).*/',
                '\1dev' . strftime('%Y%m%d%H%M'),
                $this->getVersion()
            );
        } else {
            $version = $this->getVersion();
        }

        $this->createDestination($destination);

        $package = $this->_getPackageFile();
        $pkg = $this->getFactory()->pear()->getPackageFile(
            $this->getPackageXmlPath(),
            $package->getEnvironment()
        );
        $pkg->_packageInfo['version']['release'] = $version;
        $pkg->setDate(date('Y-m-d'));
        $pkg->setTime(date('H:i:s'));
        if (isset($options['logger'])) {
            $pkg->setLogger($options['logger']);
        }
        $errors = array();
        ob_start();
        $old_dir = getcwd();
        chdir($destination);
        try {
            $pear_common = new PEAR_Common();
            $result = Components_Exception_Pear::catchError(
                $pkg->getDefaultGenerator()->toTgz($pear_common)
            );
        } catch (Components_Exception_Pear $e) {
            $errors[] = $e->getMessage();
            $errors[] = '';
            $result = false;
            foreach ($pkg->getValidationWarnings() as $error) {
                $errors[] = isset($error['message']) ? $error['message'] : 'Unknown Error';
            }
        }
        chdir($old_dir);
        $output = array($result, $errors);
        $output[] = ob_get_clean();
        return $output;
    }

    /**
     * Identify the repository root.
     *
     * @param Components_Helper_Root $helper The root helper.
     *
     * @return NULL
     */
    public function repositoryRoot(Components_Helper_Root $helper)
    {
        if (($result = $helper->traverseHierarchy($this->_directory)) === false) {
            $this->_errors[] = sprintf(
                'Unable to determine Horde repository root from component path "%s"!',
                $this->_directory
            );
        }
        return $result;
    }

    /**
     * Install a component.
     *
     * @param Components_Pear_Environment $env The environment to install
     *                                         into.
     * @param array                 $options   Install options.
     * @param string                $reason    Optional reason for adding the
     *                                         package.
     *
     * @return NULL
     */
    public function install(
        Components_Pear_Environment $env, $options = array(), $reason = ''
    )
    {
        $this->installChannel($env, $options);
        if (!empty($options['symlink'])) {
            $env->linkPackageFromSource(
                $this->getPackageXmlPath(), $reason
            );
        } else {
            $env->addComponent(
                $this->getName(),
                array($this->getPackageXmlPath()),
                $this->getBaseInstallationOptions($options),
                ' from source in ' . dirname($this->getPackageXmlPath()),
                $reason
            );
        }
    }

    /**
     * Return a PEAR package representation for the component.
     *
     * @return Horde_Pear_Package_Xml The package representation.
     */
    protected function getPackageXml()
    {
        if (!isset($this->_package)) {
            if (!file_exists($this->getPackageXmlPath())) {
                throw new Components_Exception(
                    sprintf(
                        'The package.xml of the component at "%s" is missing.',
                        $this->getPackageXmlPath()
                    )
                );
            }
            $this->_package = $this->getFactory()->createPackageXml(
                $this->getPackageXmlPath()
            );
        }
        return $this->_package;
    }

    /**
     * Return a PEAR PackageFile representation for the component.
     *
     * @return Components_Pear_Package The package representation.
     */
    private function _getPackageFile()
    {
        if (!isset($this->_package_file)) {
            $options = $this->getOptions();
            if (isset($options['pearrc'])) {
                $this->_package_file = $this->getFactory()->pear()
                    ->createPackageForPearConfig(
                        $this->getPackageXmlPath(), $options['pearrc']
                    );
            } else {
                $this->_package_file = $this->getFactory()->pear()
                    ->createPackageForDefaultLocation(
                        $this->getPackageXmlPath()
                    );
            }
        }
        return $this->_package_file;
    }

    /**
     * Return the path to the package.xml file of the component.
     *
     * @return string The path to the package.xml file.
     */
    public function getPackageXmlPath()
    {
        return $this->_directory . '/package.xml';
    }
}
