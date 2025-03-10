## %"FusionDirectory Tools 1.2" - 2025-01-31

### Added

#### fusiondirectory-tools
- fusiondirectory-tools#67 [Tools] - Adapt applications to use the new integrator logic and libraries
- fusiondirectory-tools#68 [PluginManager] - add missing copy path
- fusiondirectory-tools#69 [Tools] - Update plugin manager with new directory structure
- fusiondirectory-tools#70 [FD-Tools] - Fixes insert-schema when using bindn and ldapui in a non root privileges
- fusiondirectory-tools#73 [Tools] - Audit option within orchestrator client - allowing deletion of historical logs based on retention days
- fusiondirectory-tools#76 [Reminder] - New endpoint reminder created in orchestrator require its binary to be updated
- fusiondirectory-tools#77 [Tools] - Orchestrator Client - remove short options to only keep long ones
- fusiondirectory-tools#78 [Tools] - Add doxyfile
- fusiondirectory-tools#83 [Tools] - options to remove subtaks completed is unclear and not working - only the short option does the work

### Changed

#### fusiondirectory-tools
- fusiondirectory-tools#60 [Client-Orchestrator] - Update the binary to allow call to notifications endpoint

### Fixed

#### fusiondirectory-tools
- fusiondirectory-tools#66 [PluginManager] - re-installing a plugin should be efficient - current bug does not allow ldap deletetion when registering and existing plugin, not allowing updates
- fusiondirectory-tools#88 [Tools] - Migration - issue during migration of interfaces
- fusiondirectory-tools#90 [tools] - setup - error when string received when expecting array

## %"FusionDirectory Tools 1.1" - 2024-04-05

### Fixed

#### fusiondirectory-tools
- fusiondirectory-tools#37 [Tools] Fusiondirectory-plugin-manager - issues when targeting plugin folder
- fusiondirectory-tools#38 [Tools] Fusiondirectory-plugin-manager - error while removing plugin if folder name differ from plugin name in YAML
- fusiondirectory-tools#39 [Tools] Fusiondirectory-plugin-manager - removal logic - verification and errors messages
- fusiondirectory-tools#43 [Tools] Plugin manager - when directory is not "default" it crashes

## %"FusionDirectory Tools 1.0" - 2023-04-24

### Added

#### fusiondirectory-tools
- fusiondirectory-tools#12 rewrite fusiondirectory-plugin-manager (it is in perl )
- fusiondirectory-tools#9 [Tools] - Adds ImportSchema written in PHP and remove the old Perl.

### Changed

#### fusiondirectory-tools
- fusiondirectory-tools#2 Import of Setup.php
- fusiondirectory-tools#4 Import of insert-schema
- fusiondirectory-tools#10 [Tools] - Move the related bin and libraries to proper folders
- fusiondirectory-tools#11 [Tools] - Extract Migration modules from setup as a different librairy and bin.
- fusiondirectory-tools#17 we need remane fusiondirectory-setup and fusiondirectory-insert-schema
- fusiondirectory-tools#23 Update the migration tool to be written the same way all others tools have been
- fusiondirectory-tools#31 [Tools] Add a debug mode enabling the possibility to see the trace of the errors and add only simple error messages otherwise.

### Fixed

#### fusiondirectory-tools
- fusiondirectory-tools#13 [Setup] - Fix the non mandatory access to varialble file when installed from official repo
- fusiondirectory-tools#18 remove the ldadp_conf variable that's not used anymore in fusiondirectory-configuration-manager
- fusiondirectory-tools#24 fusiondirectory-migration-manager doensnt have the autoloader include in bin
- fusiondirectory-tools#26 [Tools] - InsertSchema replaceSchema does not work properly when we are not precisely within the ldap schema directory
- fusiondirectory-tools#27 [FD-Tools] - The install plugins propose the installation of multiple plugins but take all nevertheless of the options
- fusiondirectory-tools#28 [Tools] Fix required within configuration-manager still showing wrong helpers.
- fusiondirectory-tools#29 Ask the user to use the name plugin if a yaml file is used to unregister
- fusiondirectory-tools#30 Change the message when we successfully unregister a plugin
