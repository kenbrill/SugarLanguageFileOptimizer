<?php
CONST Version = '0.95';

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
$lfr->mainLanguage = 'en_us';
$lfr->deleteOtherLanguages = true;
$lfr->deleteEmptyFiles = true;
$lfr->verbose=false;
$lfr->run();

class languageFileRepair
{
    private $rootDirectory;
    private $directories;
    public $mainLanguage;
    public $deleteEmptyFiles;
    public $deleteOtherLanguages;
    public $verbose;
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

        //First Pass -- remove all other languages
        foreach ($this->directories as $languageDir) {
            $languageFiles = scandir($languageDir);
            foreach ($languageFiles as $fileName) {
                if($fileName === '.' || $fileName === '..') continue;
                //Remove all languages except english
                if (substr($fileName, 0, 5) !== $this->mainLanguage) {
                    if (!unlink($languageDir . '/' . $fileName)) {
                        if($this->verbose) echo "Error deleting file: {$languageDir}/{$fileName}" . PHP_EOL;
                        exit(255);
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
                        if($this->verbose) echo "Removing Empty file: {$languageDir}/{$fileName}" . PHP_EOL;
                        unlink($languageDir . '/' . $fileName);
                    }
                } else {
                    if(!empty($mod_strings)) {
                        $ms = array_merge($ms, $this->scanLanguageFile('mod_strings', $mod_strings, $languageDir, $fileName, $ms));
                    }
                    if(!empty($app_strings)) {
                        $as = array_merge($as, $this->scanLanguageFile('app_strings', $app_strings, $languageDir, $fileName, $as));
                    }
                    if(!empty($app_list_strings)) {
                        $als = array_merge($als, $this->scanLanguageFile('app_list_strings', $app_list_strings, $languageDir, $fileName, $als));
                    }

                    if (count($this->newLanguageArray) === 0) {
                        //If all the strings in this file are already in another language file
                        // then just delete this file
                        if($this->verbose) echo "Removing emptied file: {$languageDir}/{$fileName}" . PHP_EOL;
                        unlink($languageDir . '/' . $fileName);
                    } else {
                        $this->newLanguageFile("{$languageDir}/{$fileName}", $this->newLanguageArray);
                    }
                }
            }
        }

        echo 'app_list_strings:' . PHP_EOL;
        echo print_r($als, true);
        echo 'mod_strings:' . PHP_EOL;
        echo print_r($ms, true);
        echo 'app_strings:' . PHP_EOL;
        echo print_r($as, true);

        echo "Done";
    }

    /**
     * move $languageToKeep.lang.php to the top
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
            if(stristr($dirListEntry, "{$this->mainLanguage}.sugar_")) {
                $index = array_search($dirListEntry, $languageFiles);
                if (!empty($index)) {
                    unset($languageFiles[$index]);
                    array_unshift($languageFiles, $dirListEntry);
                }
            }
        }

        //Now move the main language file to the top of the list.
        $index = array_search("{$this->mainLanguage}.lang.php", $languageFiles);
        if (!empty($index)) {
            unset($languageFiles[$index]);
            array_unshift($languageFiles, "{$this->mainLanguage}.lang.php");
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
            foreach ($list['app_strings'] as $index => $value) {
                fwrite($fp, "\$app_strings['{$index}'] = \"{$value}\";" . PHP_EOL);
            }
            foreach ($list['mod_strings'] as $index => $value) {
                fwrite($fp, "\$mod_strings['{$index}'] = \"{$value}\";" . PHP_EOL);
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
        if($this->verbose) echo "Removing all language files in: {$dir}" . PHP_EOL;
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
     * @param string $langName app_strings, mod_strings or app_list_strings
     * @param array $langArray $app_strings, $mod_strings or $app_list_strings
     * @param string $languageDir
     * @param string $fileName
     * @param array $s
     * @return array
     */
    private function scanLanguageFile(string $langName, array $langArray, string $languageDir, string $fileName, array $s): array
    {
        foreach ($langArray as $index => $value) {
            if ($this->keepThisIndex($index) || !in_array($index, $this->languageDictionary[$this->module][$langName])) {
                if ($langName === 'app_list_strings') {
                    $oldValue = $this->newLanguageArray[$langName][$index];
                    if ($oldValue === null) $oldValue = array();
                    $newValue = array_merge($oldValue, $value);
                    $this->newLanguageArray[$langName][$index] = $newValue;
                } else {
                    $this->newLanguageArray[$langName][$index] = addslashes($value);
                }
                //We add this index to the dictionary so that we can make it
                //so it only appears once in the language files
                $this->languageDictionary[$this->module][$langName][$index] = $index;
                $this->languageDictionarySrc[$this->module][$langName][$index] = $languageDir . '/' . $fileName;
            } else {
                if ($languageDir . '/' . $fileName !== $this->languageDictionarySrc[$this->module][$langName][$index]) {
                    if($this->verbose) echo "Removing \$mod_strings: '{$index}' from {$languageDir}/{$fileName}" . PHP_EOL;
                    if($this->verbose) echo "--> " . $this->languageDictionarySrc[$this->module][$langName][$index] . PHP_EOL;
                    if(isset($s[$this->module][$index])) {
                        $preValue = $s[$this->module][$index]['removed'];
                        if(is_array($preValue)) {
                            $s[$this->module][$index]['removed'][] = "{$languageDir}/{$fileName}";
                        } else {
                            $s[$this->module][$index]['removed'] = array($preValue, "{$languageDir}/{$fileName}");
                        }
                    } else {
                        $s[$this->module][$index]['kept'] = $this->languageDictionarySrc[$this->module][$langName][$index];
                        $s[$this->module][$index]['removed'] = "{$languageDir}/{$fileName}";
                    }
                } else {
                    if ($langName === 'app_list_strings') {
                        $oldValue = $this->newLanguageArray[$langName][$index];
                        if ($oldValue === null) $oldValue = array();
                        $newValue = array_merge($oldValue, $value);
                        $this->newLanguageArray[$langName][$index] = $newValue;
                    } else {
                        $this->newLanguageArray[$langName][$index] = addslashes($value);
                    }
                }
            }
        }
        return $s;
    }
}
