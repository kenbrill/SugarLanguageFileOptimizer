<?php
/*
 * Version      Update
 * 0.95         Initial version - Only optimized a single "Main" language
 * 0.96         Fixed bug where app_list_strings from studio were erased
 *              Added 'Verbose' option
 *              Added ability to run from a web browser
 * 1.00         Added ability to handle multiple languages
 * 1.01         Fixed bug where the reported 'Kept' string was NULL
 */
const Version = '1.01';
const Created = 'June 3rd, 2021';
const lastUpdated = 'June 12, 2021';

use Sugarcrm\Sugarcrm\AccessControl\AdminWork;

if (!defined('sugarEntry')) {
    define('sugarEntry', true);
}

//change directories to where this file is located.
chdir(dirname(__FILE__));
const ENTRY_POINT_TYPE = 'api';
$sugar_config = array();
$current_language = '';
require_once('include/entryPoint.php');
if (empty($current_language)) {
    $current_language = $sugar_config['default_language'];
}

global $current_user;
$current_user = BeanFactory::newBean('Users');
$current_user->getSystemUser();

// allow admin to access everything
$adminWork = new AdminWork();
$adminWork->startAdminWork();

$lfr = new languageFileRepair('custom');

//For this example we will say I only need English and Japanese
$lfr->mainLanguage = array('en_us', 'ja_JP');

//For this example I want all other languages deleted
$lfr->deleteOtherLanguages = true;

//For this example I want all empty language files deleted
$lfr->deleteEmptyFiles = true;

$lfr->verbose = false;

//If this is run from the web then use <br> to end lines
$sapi_type = php_sapi_name();
if (substr($sapi_type, 0, 3) === 'cli') {
    $lfr->EOL=PHP_EOL;
} else {
    $lfr->EOL='<br>';
}

//Run the script
$lfr->run();

class languageFileRepair
{
    private $rootDirectory;
    private $directories;

    public $EOL = '<br>';
    public $web = true;
    //a list of all languages that you want retained in the file system.  
    public $mainLanguage = array('en_us');
    public $mainLanguageLower = array('en_us');

    //If this script removes all the strings from a file should that file then be
    // deleted or just left empty.  Not sure why anyone would want an empty file but
    // its possible
    public $deleteEmptyFiles = true;

    //This deletes all language files that are not tied to one of the main languages,
    // in my case I only process english but you can set this up to process any/all languages,
    // anyway after this script runs I am left with only the english files
    // in my custom directory since all the other languages files are filled with just
    // english anyway, as we dont translate anything yet, it just saves a lot of space
    // and reduces the files indexed in phpStorm.
    public $deleteOtherLanguages = true;

    //Verbose output, on or off
    public $verbose = false;
    private $languageDictionary = array();
    private $languageDictionarySrc = array();
    private $module;
    private $newLanguageArray = array();

    public function __construct($rootDirectory)
    {
        $this->rootDirectory = $rootDirectory;
    }

    public function run()
    {
        if($this->EOL===PHP_EOL) {
            $this->web=false;
        }
        $this->mainLanguageLower = array_map('strtolower', $this->mainLanguage);
        $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->rootDirectory));
        //This loop gets all the 'source' language file directory names
        foreach ($rii as $file) {
            if ($file->isDir()) {
                $pathName = $file->getPathname();
                if (stristr($pathName, '/language/') !== false) {
                    //Every directory path returned will have a /.  or a /.. on the end.
                    //We need to remove the /. or the /.. from the end of the string
                    $pathName = str_replace(array('/..', '/.'), '', $pathName);

                    //We are going to skip a few things here.  Any language file that
                    //is 'compiled' from others is skipped as it will be rebuilt during
                    //the next QRR.  We are going to delete them as the QRR seems to leave some behind
                    //First, delete everything in the application/Ext directory
                    if (stristr($pathName, '/application/Ext') !== false &&
                        stristr($pathName, '/Extension/') === false) {
                        if (!$this->is_dir_empty($pathName) && $this->deleteOtherLanguages) {
                            $this->recursiveDelete($pathName);
                        }
                        continue;
                    }
                    //Second, if it's in an Ext directory OUTSIDE the Extension directory
                    // then just empty it
                    if (stristr($pathName, '/Ext/') !== false &&
                        stristr($pathName, '/Extension/') === false) {
                        if (!$this->is_dir_empty($pathName) && $this->deleteOtherLanguages) {
                            $this->recursiveDelete($pathName);
                        }
                        continue;
                    }
                    //We store the path in the index so only one copy of each
                    // path is stored because of the /. and /..
                    $this->directories[$pathName] = $pathName;
                } else {
                    continue;
                }
            }
        }

        asort($this->directories);

        //In $languageDictionary array, we are going to collect $mods_string and $app_list_string indexes so we can
        // make sure there is only one copy of them left when we are done
        $this->languageDictionary = array();
        $this->languageDictionarySrc = array();

        //First Pass -- remove all other languages if asked to
        if ($this->deleteOtherLanguages) {
            foreach ($this->directories as $languageDir) {
                $languageFiles = scandir($languageDir);
                foreach ($languageFiles as $fileName) {
                    if ($fileName === '.' || $fileName === '..') continue;
                    //Remove all languages except english
                    if (!in_array(strtolower(substr($fileName, 0, 5)),$this->mainLanguageLower)) {
                        if (!unlink($languageDir . '/' . $fileName)) {
                            if ($this->verbose) echo "Error deleting file: {$languageDir}/{$fileName}" . $this->EOL;
                            exit(255);
                        }
                    }
                }
            }
        }

        //Second Pass
        $als = array();
        $ms = array();
        $as = array();
        foreach ($this->directories as $languageDir) {
            $languageFiles = $this->getFileNames($languageDir);
            foreach ($languageFiles as $fileName) {
                //if its not a PHP file then ignore it
                if (substr($fileName, -4) !== '.php') {
                    continue;
                }

                //register all the indexes
                $this->module = $this->findModule($languageDir);
                $mod_strings = array();
                $app_list_strings = array();
                $app_strings = array();
                $this->newLanguageArray = array();
                require($languageDir . '/' . $fileName);
                //If the file contains no strings then its considered empty
                if (count($mod_strings) === 0 && count($app_list_strings) === 0 && count($app_strings) === 0) {
                    if ($this->deleteEmptyFiles) {
                        if ($this->verbose) echo "Removing Empty file: {$languageDir}/{$fileName}" . $this->EOL;
                        unlink($languageDir . '/' . $fileName);
                    }
                } else {
                    if (!empty($mod_strings)) {
                        $ms = array_merge($ms, $this->scanLanguageFile('mod_strings', $mod_strings, $languageDir, $fileName, $ms));
                    }
                    if (!empty($app_strings)) {
                        $as = array_merge($as, $this->scanLanguageFile('app_strings', $app_strings, $languageDir, $fileName, $as));
                    }
                    if (!empty($app_list_strings)) {
                        $als = array_merge($als, $this->scanLanguageFile('app_list_strings', $app_list_strings, $languageDir, $fileName, $als));
                    }

                    if (count($this->newLanguageArray) === 0) {
                        //If all the strings in this file are already in another language file
                        // then just delete this file
                        if ($this->verbose) echo "Removing emptied file: {$languageDir}/{$fileName}" . $this->EOL;
                        unlink($languageDir . '/' . $fileName);
                    } else {
                        $this->newLanguageFile("{$languageDir}/{$fileName}", $this->newLanguageArray);
                    }
                }
            }
        }
        if($this->web) echo '<pre>';
        echo 'app_list_strings:' . $this->EOL;
        echo print_r($als, true);
        echo 'mod_strings:' . $this->EOL;
        echo print_r($ms, true);
        echo 'app_strings:' . $this->EOL;
        echo print_r($as, true);
        if($this->web) echo '<\pre>';
        echo "Done";
    }

    /**
     * move priority language files to the top of the array
     * @param string $languageDir
     * @return array|false
     */
    private function getFileNames(string $languageDir)
    {
        $languageFiles = scandir($languageDir);
        //remove . and .. which are always at the top of the list
        $trash = array_shift($languageFiles);
        $trash = array_shift($languageFiles);
        asort($languageFiles);

        //Move any file that was created as a result of a studio update to a drop down list
        // to the top of the list
        $tempList = $languageFiles;
        foreach ($tempList as $dirListEntry) {
            foreach($this->mainLanguage as $languageName) {
                $languageNameLower = strtolower($languageName.'.sugar_');
                //We need to check in a case insensitive way
                if (stristr(strtolower($dirListEntry), $languageNameLower)) {
                    $index = array_search($dirListEntry, $languageFiles);
                    if (!empty($index)) {
                        //If its found to be a studio related file (begins with LANG.sugar_)
                        // then remove it from the list and add it to the top.
                        //TODO: Array Union Operator
                        //  I could do this in one step with the array union operator but I
                        //  would have to add indexes.
                        unset($languageFiles[$index]);
                        array_unshift($languageFiles, $dirListEntry);
                    }
                }
            }
        }

        //Now move the main language file to the top of the list.
        foreach($this->mainLanguage as $languageName) {
            $languageNameLower = strtolower($languageName.'.lang.php');
            $index = array_search($languageNameLower, array_map('strtolower', $languageFiles));
            if (!empty($index)) {
                //If its found to be a studio related file (begins with LANG.lang.php)
                // then remove it from the list and add it to the top.
                //TODO: Array Union Operator
                //  I could do this in one step with the array union operator but I
                //  would have to add indexes.
                unset($languageFiles[$index]);
                array_unshift($languageFiles, "{$languageName}.lang.php");
            }
        }
        return $languageFiles;
    }

    /**
     * @param string $path
     * @return string
     */
    private function findModule(string $path): string
    {
        $parts = explode('/', $path);
        foreach ($parts as $index => $part) {
            if (strtolower($part) === 'modules') {
                return $parts[$index + 1];
            }
        }
        return 'BASE_LANG';
    }

    /**
     * @param string $fileName
     * @param array $list
     */
    private function newLanguageFile(string $fileName, array $list)
    {
        if (!unlink($fileName)) {
            echo "Error deleting file: {$fileName}" . PHP_EOL;
            exit(255);
        } else {
            $fp = sugar_fopen($fileName, 'w');
            $theDate = date('m/d/Y');
            fwrite($fp, '<?php' . PHP_EOL);
            fwrite($fp, "//Updated by " . __FILE__ . " on {$theDate}" . PHP_EOL);
            if(array_key_exists('app_list_strings',$list)) {
                foreach ($list['app_list_strings'] as $index => $value) {
                    if ($this->keepThisIndex($index)) {
                        foreach ($value as $vIndex => $vValue) {
                            $vValue = addslashes($vValue);
                            fwrite($fp, "\$app_list_strings['{$index}']['{$vIndex}'] = \"{$vValue}\";" . PHP_EOL);
                        }
                    } else {
                        fwrite($fp, "\$app_list_strings['{$index}'] = " . var_export($value, true) . ";" . PHP_EOL);
                    }
                }
            }
            if(array_key_exists('app_strings',$list)) {
                foreach ($list['app_strings'] as $index => $value) {
                    fwrite($fp, "\$app_strings['{$index}'] = \"{$value}\";" . PHP_EOL);
                }
            }
            if(array_key_exists('mod_strings',$list)) {
                foreach ($list['mod_strings'] as $index => $value) {
                    fwrite($fp, "\$mod_strings['{$index}'] = \"{$value}\";" . PHP_EOL);
                }
            }
            fclose($fp);
        }
    }

    /**
     * @param string $dir
     * @param false $deleteParent
     */
    private function recursiveDelete(string $dir, $deleteParent = false)
    {
        if ($this->verbose) echo "Removing all language files in: {$dir}" . $this->EOL;
        $it = new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS);
        $files = new RecursiveIteratorIterator($it,
            RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            if ($file->isDir()) {
                rmdir($file->getRealPath());
            } else {
                unlink($file->getRealPath());
            }
        }
        if ($deleteParent) {
            rmdir($dir);
        }
    }

    /**
     * @param string $index
     * @return bool
     */
    private function keepThisIndex(string $index): bool
    {
        $skipList = array('moduleList', 'moduleListSingular');
        if (in_array($index, $skipList)) {
            return true;
        }
        return false;
    }

    /**
     * @param string $dir
     * @return bool|null
     */
    private function is_dir_empty(string $dir): ?bool
    {
        if (!is_readable($dir)) return null;
        return (count(scandir($dir)) == 2);
    }

    /**
     * @param string $stringName app_strings, mod_strings or app_list_strings
     * @param array $langArray $app_strings, $mod_strings or $app_list_strings
     * @param string $languageDir
     * @param string $fileName
     * @param array $s
     * @return array
     */
    private function scanLanguageFile(string $stringName, array $langArray, string $languageDir, string $fileName, array $s): array
    {
        $languageName = substr($fileName, 0, 5);
        foreach ($langArray as $index => $value) {
            if ($this->keepThisIndex($index) ||
                !isset($this->languageDictionary[$languageName][$this->module][$stringName]) ||
                !in_array($index, $this->languageDictionary[$languageName][$this->module][$stringName])) {
                if ($stringName === 'app_list_strings') {
                    $oldValue = $this->newLanguageArray[$stringName][$index];
                    if ($oldValue === null) $oldValue = array();
                    $newValue = array_merge($oldValue, $value);
                    $this->newLanguageArray[$stringName][$index] = $newValue;
                } else {
                    $this->newLanguageArray[$stringName][$index] = addslashes($value);
                }
                //We add this index to the dictionary so that we can make it
                //so it only appears once in the language files
                $this->languageDictionary[$languageName][$this->module][$stringName][$index] = $index;
                $this->languageDictionarySrc[$languageName][$this->module][$stringName][$index] = $languageDir . '/' . $fileName;
            } else {
                if ($languageDir . '/' . $fileName !== $this->languageDictionarySrc[$languageName][$this->module][$stringName][$index]) {
                    if ($this->verbose) echo "Removing \$mod_strings: '{$index}' from {$languageDir}/{$fileName}" . $this->EOL;
                    if ($this->verbose) echo " Keeping this index in --> " . $this->languageDictionarySrc[$languageName][$this->module][$stringName][$index] . $this->EOL;
                    if (isset($s[$languageName][$this->module][$index])) {
                        $preValue = $s[$languageName][$this->module][$index]['removed'];
                        if (is_array($preValue)) {
                            $s[$languageName][$this->module][$index]['removed'][] = "{$languageDir}/{$fileName}";
                        } else {
                            $s[$languageName][$this->module][$index]['removed'] = array($preValue, "{$languageDir}/{$fileName}");
                        }
                    } else {
                        $s[$languageName][$this->module][$index]['kept'] = $this->languageDictionarySrc[$languageName][$this->module][$stringName][$index];
                        $s[$languageName][$this->module][$index]['removed'] = "{$languageDir}/{$fileName}";
                    }
                } else {
                    if ($stringName === 'app_list_strings') {
                        $oldValue = $this->newLanguageArray[$stringName][$index];
                        if ($oldValue === null) $oldValue = array();
                        $newValue = array_merge($oldValue, $value);
                        $this->newLanguageArray[$stringName][$index] = $newValue;
                    } else {
                        $this->newLanguageArray[$stringName][$index] = addslashes($value);
                    }
                }
            }
        }
        return $s;
    }
}
