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
  /**
   * @var Ldap\Link
   */
  protected $ldap;

  public function __construct ()
  {
    parent::__construct();

    // List mandatory options and available actions
    /* set-fd_home=FD PATH' : path of fusiondirectory installtion */
    /* plugins-archive=SRC_PATH : path of directory ( or gz archive) of plugins to scan */
    /* plugin-name=plugin name : name of plugin ( contain in control.yaml file) */

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
        'command'     => 'removePlugins',
      ],
      'list-plugins'  => [
        'help'        => 'List installed FusionDirectory plugins',
        'command'     => 'listPlugins',
      ],
      'help'          => [
        'help'        => 'Show this help',
      ],
    ];

  }

  /**
   * @param array<string> $argv
   * @throws \FusionDirectory\Ldap\Exception
   * Make the ldap bind a connect securely.
   */
  public function run (array $argv): void
  {
    parent::run($argv);

    $this->ldap = new Ldap\Link($this->getopt['ldapuri'][0] ?? 'ldapi:///');
    if ($this->getopt['simplebind'] > 0) {
      $this->ldap->bind(($this->getopt['binddn'][0] ?? ''), ($this->getopt['bindpwd'][0] ?? ''));
    } else {
      $this->ldap->saslBind(
        ($this->getopt['binddn'][0] ?? ''),
        ($this->getopt['bindpwd'][0] ?? ''),
        ($this->getopt['saslmech'][0] ?? 'EXTERNAL'),
        ($this->getopt['saslrealm'][0] ?? ''),
        ($this->getopt['saslauthcid'][0] ?? ''),
        ($this->getopt['saslauthzid'][0] ?? '')
      );
    }

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
    $dn = "cn=".$pluginInfo['information']['name'].",".$configpluginrdn.",ou=fusiondirectory,".$base;

    // Verifying if the record of the plugin already exists and delete it.
    // Create the record for the plugin.
  }

}
