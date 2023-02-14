<?php
/*
  This code is part of ldap-config-manager (https://www.fusiondirectory.org/)

  Copyright (C) 2020  FusionDirectory

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License as published by
  the Free Software Foundation; either version 2 of the License, or
  (at your option) any later version.

  This program is distributed in the hope that it will be useful,
  but WITHOUT ANY WARRANTY; without even the implied warranty of
  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
  GNU General Public License for more details.

  You should have received a copy of the GNU General Public License
  along with this program; if not, write to the Free Software
  Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA 02110-1301, USA.
 */

namespace FusionDirectory\Tools;

use \FusionDirectory\Cli;
use \FusionDirectory\Ldap;

class PluginsManager extends Cli\Application
{

  // Actually calling the VarHandling Trait from CLI libraries.
  use Cli\VarHandling;

  public function __construct ()
  {
    parent::__construct();

    // Variables to be set during script calling.
    $this->vars = [
      'fd_home'         => '/usr/share/fusiondirectory',
      'plugin_archive'    => NULL,
      'plugin_name'     => NULL
    ];

    // Options available during script calling.
    $this->options  = array_merge(
      // Coming from Trait varHandling, adds set and list vars.
      $this->getVarOptions(),
      $this->options  = [
        'register-plugin'  => [
          'help'        => 'Checking if plugins exists',
          'command'     => 'addPluginRecord',
        ],
        'unregister-plugin'  => [
          'help'        => 'Installing FusionDirectory Plugins',
          'command'     => 'deletePluginRecord',
        ],
        'install-plugin'  => [
          'help'        => 'Only register inside LDAP',
          'command'     => 'installPlugin',
        ],
        'remove-plugin'  => [
          'help'        => 'List installed FusionDirectory plugins',
          'command'     => 'removePlugin',
        ],
        'list-plugins'  => [
          'help'        => 'List installed FusionDirectory plugins',
          'command'     => 'listPlugins',
        ],
        'help'          => [
          'help'        => 'Show this help',
        ],
      ],
      $this->options
    );

  }

  /**
   * @param array<string> $argv
   * @throws \FusionDirectory\Ldap\Exception
   * Make the ldap bind a connect securely.
   */
  public function run (array $argv): void
  {
    parent::run($argv);

    // Call from CLI run method
    $this->runCommands();
  }

  // function that add plugin record
  public function addPluginRecord ()
  {
    // Load the information from the yaml file

    // Verify if the branch for plugins already exist and create it if not.

    // Collect and arrange the info received by the yaml file.

    // Create the proper CN

    // Verifying if the record of the plugin already exists and delete it.
    // Create the record for the plugin.
  }

  public function deletePluginRecord ()
  {
  }

  public function installPlugin ()
  {
  }

  public function removePlugin ()
  {
  }

  public function listPlugins ()
  {
  }

}
