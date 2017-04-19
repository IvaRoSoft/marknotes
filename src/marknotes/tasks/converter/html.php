<?php

namespace MarkNotes\Tasks\Converter;

defined('_MARKNOTES') or die('No direct access allowed');

class HTML
{
    protected static $_Instance = null;

    public function __construct()
    {
        return true;
    }

    public static function getInstance()
    {
        if (self::$_Instance === null) {
            self::$_Instance = new HTML();
        }

        return self::$_Instance;
    }

    /**
     * Display the HTML rendering of the note in a nice HTML layout. Called when the URL is something like
     * http://localhost/notes/docs/Development/atom/Plugins.html i.e. accessing the .html file
     *
     * @param  string  $html [description]   html rendering of the .md file
     * @return {[type]       Nothing
     */
    public function run(string $html, array $params = null)
    {
        $aeFiles = \MarkNotes\Files::getInstance();
        $aeHTML = \MarkNotes\FileType\HTML::getInstance();
        $aeSettings = \MarkNotes\Settings::getInstance();

        // Add h2 and h3 id and don't add the "go to top" icon
        $html = $aeHTML->addHeadingsID($html, false);

        // Add css to bullets
        $html = $aeHTML->setBulletsStyle($html);

        // Check if a template has been specified in the parameters
        // and if so, check that this file exists

        // Default is html
        $template = $aeSettings->getTemplateFile('html');

        if (isset($params['template'])) {
            $template = $aeSettings->getTemplateFile($params['template']);
            if (!$aeFiles->fileExists($template)) {
                $template = $aeSettings->getTemplateFile('html');
            }
        }

        // Don't keep the § (tags prefix) for slideshow
        $html = str_replace($aeSettings->getTagPrefix(), '', $html);
        $html = str_replace(htmlentities($aeSettings->getTagPrefix()), '', $html);  // &sect; = §

        if ($aeFiles->fileExists($template)) {
            $html = $aeHTML->replaceVariables(file_get_contents($template), $html, $params);
        }

        $javascript =
        "\nvar marknotes = {};\n".
        "marknotes.message={};\n".
        "marknotes.message.on_this_page='".$aeSettings->getText('on_this_page', 'On this page', true)."';\n";

        $html = str_replace('<!--%MARKDOWN_GLOBAL_VARIABLES%-->', '<script type="text/javascript">'.$javascript.'</script>', $html);

        return $html;
    }
}
