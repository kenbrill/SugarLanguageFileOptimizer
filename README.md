# SugarCRM_LangFileRepair

Sugar seems to update strings ($mod_strings and $app_list_strings) by either creating new files (for $app_list_strings) or adding the string to the en_us.lang.php file, both methods leave multiple copies of the string out there.  TO compound this issue when you create relationships sugar will add all the language strings from previous relationships in that module to the language file created for the new relatinship.  Meaning the same language string can be in dozens of different files.

This script goes though all your language files and processes them in a way that leaves you with a single copy of the language string out there.  It removes it from all other files and if that leaves a file without any strings then it deletes the file (configurable)
