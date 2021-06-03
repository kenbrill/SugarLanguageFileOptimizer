# Sugar language file repair utility

With the default settings this script DOES delete files so please back up your files before running this even in a test
environment.

To run you just copy the script to your root directory and run

php -f languageFileUpdate.php > ~/lfa.log

Followed by a Quick Rebuild and Repair and then clear the Javascript language files (both on the repair menu)

When its done, the file lfa.log will give you a run down on everything it did. You will see entries like

            [state_dom] => Array
                (
                    [kept] => custom/Extension/application/Ext/Language/en_us.sugar_state_dom.php
                    [removed] => Array
                        (
                            [0] => custom/Extension/application/Ext/Language/en_us.Customers_Locations.php
                            [1] => custom/include/language/en_us.lang.php
                        )

                )

This tells you that the $app_list_strings['state_dom'] was left in en_us.sugar_state_dom.php but removed from the two
files listed under 'Removed'. If you want to see what files the script deletes then just turn on the 'verbose' mode near
the top of the script.

Sugar seems to update strings ($mod_strings and $app_list_strings) by either creating new files
(for $app_list_strings) or adding the string to the en_us.lang.php file, both methods leave multiple copies of the
string out there. TO compound this issue when you create relationships sugar will add all the language strings from
previous relationships in that module to the language file created for the new relatinship. Meaning the same language
string can be in dozens of different files.

This script goes through all your language files and processes them in a way that leaves you with a single copy of the
language string out there. It removes it from all other files and if that leaves a file without any strings then it
deletes the file (configurable).
