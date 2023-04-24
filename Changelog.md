## %"FusionDirectory Tools 1.0" - 2023-04-24

### Added

#### fusiondirectory-tools
- fusiondirectory-tools#12 rewrite fusiondirectory-plugin-manager (it is in perl )
- - fusiondirectory-tools#9 [Tools] - Adds ImportSchema written in PHP and remove the old Perl.
-
- ### Changed
-
- #### fusiondirectory-tools
- - fusiondirectory-tools#2 Import of Setup.php
- - fusiondirectory-tools#4 Import of insert-schema
- - fusiondirectory-tools#10 [Tools] - Move the related bin and libraries to proper folders
- - fusiondirectory-tools#11 [Tools] - Extract Migration modules from setup as a different librairy and bin.
- - fusiondirectory-tools#17 we need remane fusiondirectory-setup and fusiondirectory-insert-schema
- - fusiondirectory-tools#23 Update the migration tool to be written the same way all others tools have been
- - fusiondirectory-tools#31 [Tools] Add a debug mode enabling the possibility to see the trace of the errors and add only simple error messages otherwise.
-
- ### Fixed
-
- #### fusiondirectory-tools
- - fusiondirectory-tools#13 [Setup] - Fix the non mandatory access to varialble file when installed from official repo
- - fusiondirectory-tools#18 remove the ldadp_conf variable that's not used anymore in fusiondirectory-configuration-manager
- - fusiondirectory-tools#24 fusiondirectory-migration-manager doensnt have the autoloader include in bin
- - fusiondirectory-tools#26 [Tools] - InsertSchema replaceSchema does not work properly when we are not precisely within the ldap schema directory
- - fusiondirectory-tools#27 [FD-Tools] - The install plugins propose the installation of multiple plugins but take all nevertheless of the options
- - fusiondirectory-tools#28 [Tools] Fix required within configuration-manager still showing wrong helpers.
- - fusiondirectory-tools#29 Ask the user to use the name plugin if a yaml file is used to unregister
- - fusiondirectory-tools#30 Change the message when we successfully unregister a plugin
