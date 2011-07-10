<?php
/**
 * DocBlox
 *
 * PHP Version 5
 *
 * @category  DocBlox
 * @package   Transformer
 * @author    Mike van Riel <mike.vanriel@naenius.com>
 * @copyright 2010-2011 Mike van Riel / Naenius (http://www.naenius.com)
 * @license   http://www.opensource.org/licenses/mit-license.php MIT
 * @link      http://docblox-project.org
 */

/**
 * Core class responsible for transforming the structure.xml file to a set of
 * artifacts.
 *
 * @category DocBlox
 * @package  Transformer
 * @author   Mike van Riel <mike.vanriel@naenius.com>
 * @license  http://www.opensource.org/licenses/mit-license.php MIT
 * @link     http://docblox-project.org
 */
class DocBlox_Transformer extends DocBlox_Transformer_Abstract
{
    /** @var string|null Target location where to output the artifacts */
    protected $target = null;

    /** @var DOMDocument|null DOM of the structure as generated by the parser. */
    protected $source = null;

    /** @var DocBlox_Transformer_Template[] */
    protected $templates = array();

    /** @var string */
    protected $themes_path = '';

    /** @var DocBlox_Transformer_Behaviour_Collection */
    protected $behaviours = null;

    /** @var DocBlox_Transformer_Transformation[] */
    protected $transformations = array();

    /** @var boolean */
    protected $parsePrivate = false;

    /**
     * Sets the path for the themes to the DocBlox default.
     */
    public function __construct()
    {
        $this->themes_path = dirname(__FILE__) . '/../../data/themes';

        $this->behaviours = new DocBlox_Transformer_Behaviour_Collection(
            array(
                new DocBlox_Transformer_Behaviour_GeneratePaths(),
                new DocBlox_Transformer_Behaviour_AddLinkInformation(),
                new DocBlox_Transformer_Behaviour_Inherit(),
                new DocBlox_Transformer_Behaviour_Tag_Ignore(),
            )
        );
    }

    /**
     * Sets the target location where to output the artifacts.
     *
     * @param string $target The target location where to output the artifacts.
     *
     * @throws Exception if the target is not a valid writable directory.
     *
     * @return void
     */
    public function setTarget($target)
    {
        $path = realpath($target);
        if (!file_exists($path) && !is_dir($path) && !is_writable($path)) {
            throw new Exception(
                'Given target directory (' . $target . ') does not exist or '
                . 'is not writable'
            );
        }

        $this->target = $path;
    }

    /**
     * Returns the location where to store the artifacts.
     *
     * @return string
     */
    public function getTarget()
    {
        return $this->target;
    }

    /**
     * Sets the path where the themes are located.
     *
     * @param string $path Absolute path where the themes are.
     *
     * @return void
     */
    public function setThemesPath($path)
    {
        $this->themes_path = $path;
    }

    /**
     * Returns the path where the themes are located.
     *
     * @return string
     */
    public function getThemesPath()
    {
        return $this->themes_path;
    }

    /**
     * Sets the location of the structure file.
     *
     * @param string $source The location of the structure file as full path
     *  (may be relative).
     *
     * @throws Exception if the source is not a valid readable file.
     *
     * @return void
     */
    public function setSource($source)
    {
        $source = trim($source);

        $xml = new DOMDocument();

        if (substr($source, 0, 5) === '<?xml') {
            $xml->loadXML($source);
        } else {
            $path = realpath($source);
            if (!file_exists($path) || !is_readable($path) || !is_file($path)) {
                throw new Exception(
                    'Given source (' . $source . ') does not exist or is not '
                    . 'readable'
                );
            }

            // convert to dom document so that the writers do not need to
            $xml->load($path);
        }

        $this->source = $xml;
    }

    /**
     * Returns the source Structure.
     *
     * @return null|DOMDocument
     */
    public function getSource()
    {
        return $this->source;
    }

    /**
     * Sets flag indicating whether private members and/or elements tagged
     * as {@internal} need to be displayed.
     *
     * @param bool $val True if all needs to be shown, false otherwise.
     *
     * @return void
     */
    public function setParseprivate($val)
    {
        $this->parsePrivate = (boolean)$val;
    }

    /**
     * Returns flag indicating whether private members and/or elements tagged
     * as {@internal} need to be displayed.
     *
     * @return bool
     */
    public function getParseprivate()
    {
        return $this->parsePrivate;
    }

    /**
     * Sets one or more templates as basis for the transformations.
     *
     * @param string|string[] $template Name or names of the templates.
     *
     * @return void
     */
    public function setTemplates($template)
    {
        $this->templates = array();

        if (!is_array($template)) {
            $template = array($template);
        }

        foreach ($template as $item) {
            $this->addTemplate($item);
        }
    }

    /**
     * Returns the list of templates which are going to be adopted.
     *
     * @return string[]
     */
    public function getTemplates()
    {
        return $this->templates;
    }

    /**
     * Loads a template by name, if an additional array with details is
     * provided it will try to load parameters from it.
     *
     * @param string $name Name of the template to add.
     *
     * @return void
     */
    public function addTemplate($name)
    {
        // if the template is already loaded we do not reload it.
        if (isset($this->templates[$name])) {
            return;
        }

        $path = null;

        // if this is an absolute path; load the template into the configuration
        // Please note that this _could_ override an existing template when
        // you have a template in a subfolder with the same name as a default
        // template; we have left this in on purpose to allow people to override
        // templates should they choose to.
        $config_path = rtrim($name, DIRECTORY_SEPARATOR) . '/template.xml';
        if (file_exists($config_path) && is_readable($config_path)) {
            $path = rtrim($name, DIRECTORY_SEPARATOR);
            $template_name_part = basename($path);
            $cache_path = rtrim($this->getThemesPath(), '/\\')
            . DIRECTORY_SEPARATOR . 'cache'
            . DIRECTORY_SEPARATOR . $template_name_part;

            // move the files to a cache location and then change the path
            // variable to match the new location
            $this->copyRecursive($path, $cache_path);
            $path = $cache_path;

            // transform all directory separators to underscores and lowercase
            $name = strtolower(
                str_replace(
                    DIRECTORY_SEPARATOR,
                    '_',
                    rtrim($name, DIRECTORY_SEPARATOR)
                )
            );
        }

        // if we load a default theme
        if ($path === null) {
            $path = rtrim($this->getThemesPath(), '/\\')
                . DIRECTORY_SEPARATOR . $name;
        }

        // track templates to be able to refer to them later
        $this->templates[$name] = new DocBlox_Transformer_Template($name, $path);
        $this->templates[$name]->populate(
            $this,
            file_get_contents($path  . DIRECTORY_SEPARATOR . 'template.xml')
        );
    }

    /**
     * Returns the transformation which this transformer will process.
     *
     * @return DocBlox_Transformer_Transformation[]
     */
    public function getTransformations()
    {
        $result = array();
        foreach ($this->templates as $template) {
            foreach ($template as $transformation) {
                $result[] = $transformation;
            }
        }

        return $result;
    }

    /**
     * Executes each transformation.
     *
     * @return void
     */
    public function execute()
    {
        $xml = $this->getSource();

        if ($xml) {
            $this->dispatch(
                'transformer.pre-transform',
                array(
                    self::$event_dispatcher,
                    $xml
                )
            );

            if (!$this->getParseprivate()) {
                $this->behaviours->addBehaviour(
                    new DocBlox_Transformer_Behaviour_Tag_Internal()
                );
            }

            $xml = $this->behaviours->process($xml);
        }

        foreach ($this->getTransformations() as $transformation) {
            $this->log(
                'Applying transformation query ' . $transformation->getQuery()
                . ' using writer ' . get_class($transformation->getWriter())
            );

            $transformation->execute($xml);
        }
    }

    /**
     * Converts a source file name to the name used for generating the end result.
     *
     * @param string $file Path of the file starting from the project root.
     *
     * @return string
     */
    public function generateFilename($file)
    {
        $info = pathinfo(
            str_replace(
                DIRECTORY_SEPARATOR,
                '_',
                trim($file, DIRECTORY_SEPARATOR . '.')
            )
        );

        return '_' . $info['filename'] . '.html';
    }

    /**
     * Copies a file or folder recursively to another location.
     *
     * @param string $src The source location to copy
     * @param string $dst The destination location to copy to
     *
     * @throws Exception if $src does not exist or $dst is not writable
     *
     * @return void
     */
    public function copyRecursive($src, $dst)
    {
        // if $src is a normal file we can do a regular copy action
        if (is_file($src)) {
            copy($src, $dst);
            return;
        }

        $dir = opendir($src);
        if (!$dir) {
            throw new Exception('Unable to locate path "' . $src . '"');
        }

        // check if the folder exists, otherwise create it
        if ((!file_exists($dst)) && (false === mkdir($dst))) {
            throw new Exception('Unable to create folder "' . $dst . '"');
        }

        while (false !== ($file = readdir($dir))) {
            if (($file != '.') && ($file != '..')) {
                if (is_dir($src . '/' . $file)) {
                    $this->copyRecursive($src . '/' . $file, $dst . '/' . $file);
                } else {
                    copy($src . '/' . $file, $dst . '/' . $file);
                }
            }
        }
        closedir($dir);
    }

}