<?php
/**
 * Working on big files isn't always the most efficient way.
 * This plugin will allow to include files in the markdown
 * content "just like" if the content was in a single file.
 *
 * For instance :
 *
 *	# My big story
 *
 *	%INCLUDE .chapters/settings.md{"once":1}%
 *	%INCLUDE .chapters/chapter1.md%
 *	%INCLUDE .chapters/chapter2.md%
 *	%INCLUDE .chapters/chapter3.md%
 *
 *  or
 *	%INCLUDE .chapters/*.md{"recursive":0}%
 *
 * After the filename, settings can be given in a json
 * format like {"once":1} or {"recursive":0}
 *
 *	once:1	=> that file will be loaded only once even when
 *				 	multiples .md files are referencing the same
 *					include.
 *					Usefull for f.i. including a settings file.
 *					once=1 is good when you're including a
 *					settings file (i.e a markdown file where
 *					you're defining,
 *					once and for all, your abbreviations, URLs,
 *					 ...), once=0 can be good when you wish
 *					to include headers and footers f.i.
 *
 * recursive:0 => by default, include will also take subfolders when
 *					the included filename is with a wildcard
 *					(f.i. *.md)
 *					By specifying recursive:0, only the mentionned
 *					folder will be processed
 *					(f.i. %INCLUDE foldername/*.md%)
 *
 * Note : the Include plugin also support dynamic list files like
 *		%INCLUDE *.md%
 *		In that case, all .md files in the same folder will be
 *		included *except* the file that contains that sentence
 *		(otherwise we'll have an infinite loop)
 */
namespace MarkNotes\Plugins\Markdown;

defined('_MARKNOTES') or die('No direct access allowed');

class Include_File extends \MarkNotes\Plugins\Markdown\Plugin
{
	protected static $me = __CLASS__;
	protected static $json_settings = 'plugins.markdown.include';
	protected static $json_options = 'plugins.options.markdown.include';

	private static $include_regex = '/^([ \\t])*%INCLUDE ([^{\\n]*)({.*})?%/m';

	private static function getURL(string $filename) : string
	{
		$aeFunctions = \MarkNotes\Functions::getInstance();
		$aeSettings = \MarkNotes\Settings::getInstance();

		// Get the web root like http://localhost/notes/
		$sRoot = rtrim($aeFunctions->getCurrentURL(), '/').'/';

		// Add the /docs folder in the URL (so something like
		// http://localhost:8080/notes/docs/)
		$url = $sRoot.rtrim($aeSettings->getFolderDocs(false), DS).'/';

		// Get the relative folder of the notes (from /docs)
		// Something like (/marknotes/userguide)
		$path=str_replace($aeSettings->getFolderDocs(true), '', dirname($filename));

		$url.=rtrim(str_replace(DS, '/', $path), '/').'/';

		// And return the url
		// http://localhost:8080/notes/docs/marknotes/userguide)
		return str_replace(' ', '%20', $url);
	}

	/**
	 * When we're processing a file that contains a H1, the
	 * problem is that the master file probably already contains
	 * a H1 so we've two. This is not a good habit so, for each
	 * included files, if we've a H1 in the included content,
	 * scan every headings in that content and increment by one
	 */
	private static function IncrementHeadings(string $sContent) : string
	{
		$increment = boolval(self::getOptions('increment_headings', 0));

		if ($increment) {
			if (preg_match_all('/^(#{1,} )(.*)/m', $sContent, $matches)) {
				list($tag, $heading, $title) = $matches;

				for ($i = 0; $i < count($heading); $i++) {
					$sContent = str_replace($tag[$i], '#'.$heading[$i].$title[$i], $sContent);
				}
			}
		}

		return $sContent;
	}

	/**
	 * The include statement can be something like :
	 *
	 * %INCLUDE settings.md{"once":1}%
	 *
	 * i.e. the filename is followed by a JSON string with
	 * perhaps {"once":1} meaning that the file shoud be loaded
	 * only once even if called many times (in many included
	 * documents)
	*/
	private static function wasAlreadyProcessed(string $filename, string $json) : bool
	{
		static $arrLoaded=null;

		// Default : not yet processed
		$bReturn = false;

		if ($arrLoaded === null) {
			$arrLoaded = array();
		}

		if (!in_array($filename, $arrLoaded)) {
			// The file wasn't yet loaded, just add the file
			// to the list and continue
			$arrLoaded[]=$filename;
		} else {
			$tmp = json_decode($json, true);
			if (boolval($tmp['once']??false)) {
				// The file should be included only once
				// So if already processed previously,
				// don't process it again.
				$bReturn = true;
			}
		}

		return $bReturn;
	}

	/**
	 * Somes tags like the TOC one can't be keept in an
	 * included note. This function will remove these tags.
	 */
	private static function cleanMarkdown(string $markdown) : string
	{
		/**
		 * When including a file, remove any %TOC% calls
		 * (TOC plugin) since a table of content should never
		 * be "inside" a note but always at the beginning
		 * i.e. in the master note (the one with the first
		 * inclusions)
		*/
		$regex = '/%TOC_(\\d)%/m';
		$markdown = preg_replace($regex, '', $markdown);

		/**
		 * Replace non breaking space to spaces
		 * since, otherwise, rendering will fail and
		 * no output will be done for the
		 * sentence/paragraph with that character.
		 * A non breaking space is U+00A0 (Unicode)
		 * but encoded as C2A0 in UTF-8
		 * Replace by a space character
		*/
		$regex = '/\x{00a0}/siu';
		$markdown=preg_replace($regex, ' ', $markdown);

		return $markdown;
	}

	/**
	 * Call markdown::read plugins to, f.i.,
	 * correctly manage encryptions tags that can be
	 * present in included notes
	*/
	private static function runMarkdownPlugins(string $filename, string $markdown) : string
	{
		$aeEvents = \MarkNotes\Events::getInstance();
		$aeEvents->loadPlugins('markdown');
		$params['markdown'] = $markdown;
		$params['filename'] = $filename;
		$params['dont_include'] = true;
		$args = array(&$params);

		$aeEvents->trigger('markdown::markdown.read', $args);

		// Return the markdown content once plugins fired
		return $args[0]['markdown'];
	}

	/**
	 * Replace variables like %URL% present in the markdown
	 * content by its value
	 */
	private static function replaceVariables(string $filename, string $markdown) : string
	{
		// Retrieve the folder of the note
		$folder=dirname($filename).DS;
		$markdown = str_replace('%NOTE_FOLDER%', $folder, $markdown);

		if (strpos($markdown, '%URL%') !== false) {
			// The %URL% variable should be relative
			// to this note ($filename)
			// and not from the master note i.e.
			// where the %INCLUDE% tag has been put
			$aeSession = \MarkNotes\Session::getInstance();
			$task = $aeSession->get('task');
			if (!in_array($task, array('task.export.epub','task.export.docx','task.export.pdf'))) {
				// This is an hyperlink
				$markdown = str_replace('%URL%', str_replace(' ', '%20', self::getURL($filename)), $markdown);

			} else {
				// When exporting the note, should be
				// a local file. Escape the directory
				// separator (since under Windows,
				// it's a backslash \ which has special
				// meaning in a regex)
				$markdown = str_replace('%URL%', str_replace(DS, '\\'.DS, rtrim(dirname($filename)).DS), $markdown);
			}
		}

		return $markdown;
	}

	/**
	 * The %INCLUDE% tag should start the line; as soon as there
	 * is one character before, like a space, the tag will be
	 * considered as "commented"
	 *
	 * So a line with, for instance, "#%INCLUDE file.md%"
	 * won't be processed.
	 *
	 * When this is the case, this function will be called
	 */
	private static function tagDisabled(string $indent, string $markdown, string $tag) : string
	{
		$aeSettings = \MarkNotes\Settings::getInstance();

		/*<!-- build:debug -->*/
		if ($aeSettings->getDebugMode()) {
			$aeDebug = \MarkNotes\Debug::getInstance();
			$aeDebug->log($indent.' - Not processed since the tag isn\'t at the begining of the sentence. If there are characters before the %INCLUDE% tag, the tag is ignored', 'debug');
			$aeDebug->log($indent.' ***'.$tag.'***', 'debug');

			/**
			 * Since we're in debug mode; the INCLUDE tag will
			 * be displayed but with a "disabled" suffix to make
			 * things clear enough.
			 *
			 * Note that the tag "%INCLUDE " (with a space after)
			 * CAN'T stay unchanged due to the recursive call
			 * of this function.
			 * The tag should be changed to something else
			 * otherwise we'll have an infinite loop
			*/
			$tmp = str_replace('%INCLUDE', '%INCLUDE_disabled', $tag);
			$markdown = str_replace($tag, $tmp, $markdown);
		} else {
		/*<!-- endbuild -->*/
			// Since the %INCLUDE% tag has been disabled,
			// remove it from the output
			$markdown = str_replace($tag, '', $markdown);
		/*<!-- build:debug -->*/
		}
		/*<!-- endbuild -->*/

		return $markdown;
	}

	/**
	 * When the include statement is something like
	 *
	 *		%INCLUDE subfolder/*.md%
	 *
	 * then this function will replace dynamically that statement
	 * by, for instance,
	 *
	 *		%INCLUDE subfolder/note1.md%
	 *		%INCLUDE subfolder/note2.md%
	 *		%INCLUDE subfolder/note3.md%
	 *		%INCLUDE subfolder/note4.md%
	 *
	 * i.e. will retrieve the list of .md files in the mentionned
	 * folder, order them alphabetically.
	 *
	 * @param $caller = the filename of the master note.
	 *		Indeed if index.md use %INCLUDE *.md% we SHOULD NOT
	 *		included index.md back otherwise it'll be an
	 *		infinite loop
	 *
	 * @param $filename = the "filename" used like f.i.
	 *		c:\notes\docs\*.md (i.e. the parent folder and the
	 *		*.md pattern)
	 */
	private static function getListNotes(string $caller, string $filename, bool $recursive) : array
	{
		$aeFiles = \MarkNotes\Files::getInstance();
		$aeFolders = \MarkNotes\Folders::getInstance();

		// $filename is an absolute path, extract the folder name
		$folder = '';
		if (strlen($filename)>4) {
			$folder = substr($filename, 0, strlen($filename)-4);
			if ($folder !== '') {
				$folder = ltrim($folder, DS);
			}
		}

		// Retrieve files in all subdirectories
		$arr = $aeFolders->getContent($folder, $recursive);

		$sContent = '';

		foreach ($arr as $tmp) {
			if ($tmp['type'] == 'file') {
				if ($tmp['extension'] == 'md') {
					//	Include the note only if it's an .md one
					$fullname = $aeFiles->makeFileNameAbsolute($tmp['path']);

					// $filename is the note being processed.
					// The one with the %INCLUDE *.md% statement
					// and, thus, make sure to not import that
					// file otherwise we'll have an infinite loop
					if ($fullname != $caller) {
						$arrFiles[] = $fullname;
						$arrTags[] = '%INCLUDE '.$fullname.'%';
						$sContent .= '%INCLUDE '.$fullname.'%'.PHP_EOL.PHP_EOL;
					}
				}
			}
		}

		// Just to be sure, sort the list of files
		// in an ascending way
		sort($arrFiles);

		return array($sContent, $arrFiles, $arrTags);
	}

	/**
	 * Main function : read the markdown file and process every
	 * %INCLUDE% tag and this recursively
	 */
	private static function processIncludes(string $markdown, string $filename, string $indent = '') : string
	{
		$aeFiles = \MarkNotes\Files::getInstance();
		$aeFunctions = \MarkNotes\Functions::getInstance();
		$aeSession = \MarkNotes\Session::getInstance();
		$aeSettings = \MarkNotes\Settings::getInstance();

		/*<!-- build:debug -->*/
		if ($aeSettings->getDebugMode()) {
			$aeDebug = \MarkNotes\Debug::getInstance();
			$aeDebug->log($indent.'Including '.$filename, 'debug');
		}
		/*<!-- endbuild -->*/

		/**
		 * Replace the variable by the location of the current
		 * note
		 * Get the folder of the processed note
		 * If we're processing f.i.
		 * 		C:\notes\docs\include\test.md
		 * return then
		 * 		C:\notes\docs\include\
		*/
		$folder=dirname($filename).DS;

		// Retrieve every occurences of %INCLUDE filename%
		// Don't make an IF since we know there are INCLUDE tags
		preg_match_all(static::$include_regex, $markdown, $matches);

		// Loop and process every %INCLUDE ..% tags
		for ($i=0; $i<count($matches[0]); $i++) {
			/**
			 * $tag	=> $matches[0][0] will be f.i.
			 * 		%INCLUDE .chapters/chapter1.md%"
			 * $before => $matches[1][0] will be f.i.
			 *		"  " i.e. what's before %INCLUDE
			 * $file	=> $matches[2][0] will be f.i.
			 *		".chapters/chapter1.md"
			 * $json	=> $matches[3][0] will be f.i.
			 *		"{"once":1}"
			*/
			list($tag, $before, $file, $json) = $matches;

			$arrFiles = array();
			$arrTags = array();

			if ($before[$i] !== '') {
				$markdown = self::tagDisabled($indent, $markdown, $tag[$i]);
			} else {
				if (substr($file[$i], -4) == '*.md') {
					// Special case : the "filename" ends with
					// "*.md" so get the list of files

					$recursive = true;

					if ($json!=='') {
						$tmp = json_decode($json[$i], true);
						$recursive = boolval($tmp['recursive'])??true;
					}

					list ($files, $arrFiles, $arrTags) = self::getListNotes($filename, $folder.$file[$i], $recursive);

					/**
					 * Replace the tag
					 *		 %INCLUDE subfolder/*.md%
					 * by
					 *		 %INCLUDE subfolder/note1.md%
					 *		 %INCLUDE subfolder/note2.md%
					 *		 %INCLUDE subfolder/note3.md%
					 * i.e. the list of .md files found
					*/
					$markdown = str_replace($tag[$i], $files, $markdown);

				} else {
					// $file[$i] is a single file (f.i. note.md)
					// Load only that file
					$arrFiles[] = $folder.$file[$i];
					$arrTags[] = $tag[$i];
				}

				for ($j=0; $j<count($arrFiles); $j++) {
					$filename = $arrFiles[$j];
					$tag2 = $arrTags[$j];

					$sContent='';

					// If required ("once":1), process the
					// same file only once
					if (!self::wasAlreadyProcessed($filename, trim($json[$i]))) {
						// In case of ... be sure the file
						// exists
						if ($aeFiles->exists($filename)) {
							$aeFiles = \MarkNotes\Files::getInstance();
							$aeSettings = \MarkNotes\Settings::getInstance();

							/*<!-- build:debug -->*/
							if ($aeSettings->getDebugMode()) {
								$aeDebug = \MarkNotes\Debug::getInstance();
								$aeDebug->log($indent.'Including '.$filename, 'debug');
							}
							/*<!-- endbuild -->*/

							// Read the file
							$sContent = trim($aeFiles->getContent($filename));

							// Be sure to have two empty lines so
							// headings will be correctly understand
							// when appending multiples files.
							$sContent .= PHP_EOL.PHP_EOL;

							// Check if there are another
							// INCLUDE tags (take care to use
							// a regex and not a strpos !)
							// The tag should comply the regex
							while (preg_match(static::$include_regex, $sContent)) {
								$sContent = self::processIncludes($sContent, $filename, $indent.'	');

							} // while

							// Remove unneeded plugins tags
							$sContent = self::cleanMarkdown($sContent);

							// Replace variables
							$sContent = self::replaceVariables($filename, $sContent);

							// Fire markdown plugins (like the
							// encryption or lastupdate plugins)
							$sContent = self::runMarkdownPlugins($filename, $sContent);

							// We're including a file inside a
							// file => if the included file has
							// a heading 1, it should become
							// a heading 2 => headings should be
							// incremented by one
							$sContent = self::IncrementHeadings($sContent);

						/*<!-- build:debug -->*/
						} else {
							if ($aeSettings->getDebugMode()) {
								$aeDebug->log('	Failure : file ['.$filename.'] not found ! If the path is relative, think to add %NOTE_FOLDER% in your call so the file will be correctly retrieved (f.i. %INCLUDE %NOTE_FOLDER%file-to-include.md%)', 'error');
							}
						/*<!-- endbuild -->*/
						}
					/*<!-- build:debug -->*/
					} else {
						if ($aeSettings->getDebugMode()) {
							$aeDebug->log('The file ['.$filename.'] was already included and the include tag was something like %INCLUDE note.md{"once":1}%', 'debug');
						}
					/*<!-- endbuild -->*/
					} // if (!self::wasAlreadyProcessed

					$markdown = str_replace($tag2, $sContent, $markdown);
				} // for ($j=0; $j<count($arrFiles)
			} // if ($before[$i] !== '')
		} // for ($i=0; $i<count($matches[0]

		return $markdown;
	}

	public static function readMD(array &$params = array()) : bool
	{
		if (trim($params['markdown']) === '') {
			return true;
		}

		/**
		 * The process_includes function here above will
		 * process any %INCLUDE% tags by itself but need to
		 * be able to run the others markdown plugins (but
		 * not, thus, the include plugin). So, process_includes
		 * will set a dont_include parameters to true to avoid
		 * to plugin to run.
		 */
		if (isset($params['dont_include'])) {
			return true;
		}

		// If the markdown content has not INCLUDE tag, return
		if (!(preg_match(static::$include_regex, $params['markdown'], $match))) {
			return true;
		}

		// ----------------------------------------------
		// Start the job

		$aeFiles = \MarkNotes\Files::getInstance();
		$aeSettings = \MarkNotes\Settings::getInstance();
		$aeSession = \MarkNotes\Session::getInstance();

		// Remember this filename i.e. the "master" file.
		$fullname = $aeSession->get('filename');
		$fullname = $aeFiles->makeFileNameAbsolute($fullname);

		// Important for comparaisons : the processed filename
		// should be with the .md extension
		$fullname = $aeFiles->removeExtension($fullname).'.md';

		$params['markdown'] = self::processIncludes($params['markdown'], $fullname, '');

		return true;
	}
}
