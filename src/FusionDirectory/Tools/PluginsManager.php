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
      'fd_config_dir'     => '/etc/fusiondirectory',
      'plugin_archive'    => '/path/to/archive',
      'plugin_name'     => 'plugin_name'
    ];

    // Options available during script calling.
    $this->options  = array_merge(
      // Coming from Trait varHandling, adds set and list vars.
      $this->getVarOptions(),
      // Careful, an option ending by : will receive args passed by user.
      $this->options  = [
        'register-plugin'  => [
          'help'        => 'Register plugin within LDAP',
          'command'     => 'addPluginRecord',
        ],
        'unregister-plugin'  => [
          'help'        => 'Delete entry within LDAP',
          'command'     => 'deletePluginRecord',
        ],
        'install-plugin:'  => [
          'help'        => 'Install plugin within FD and register it within LDAP',
          'command'     => 'installPlugin',
        ],
        'remove-plugin'  => [
          'help'        => 'Remove plugin from FD and LDAP',
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
  // $params Path to the yaml file to be read.
  public function addPluginRecord (string $path) : bool
  {
    // Load the information from the yaml file

    // Verify if the branch for plugins already exist and create it if not.

    // Collect and arrange the info received by the yaml file.

    // Create the proper CN

    // Verifying if the record of the plugin already exists and delete it.
    // Create the record for the plugin.

    return TRUE;
  }

  public function deletePluginRecord ()
  {

  }

  public function installPlugin (array $paths)
  {
    if (count($paths) != 1) {
      throw new \Exception('Please provide one and only one path to fetch plugins from');
    }

    $path = $paths[0];
    if (!file_exists($path)) {
      throw new \Exception($path.' does not exist');
    }

    if (is_dir($path)) {
      $dir = $path;
    } else {

      /* Check the archive format */
      if (preg_match('/^.*\/(.+).tar.gz$/', $path, $m)) {
        $name = $m[1];
      } else {
        throw new \Exception('Unkwnow archive '.$path);
      }

      /* Where the extract files will go */
      $tmpPluginsDir = '/tmp';

      printf('Extracting plugins into "%s", please wait…'."\n", $tmpPluginsDir.'/'.$name);

      /* Decompress from gz */
      $p = new \PharData($path);
      $p->extractTo($tmpPluginsDir);

      $dir = $tmpPluginsDir.'/'.$name;
    }

    echo "Available plugins:\n";

    $Directory = new \FilesystemIterator($dir);

    $i = 1;
    $plugins = [];
    foreach ($Directory as $item) {
      /** @var \SplFileInfo $item */
      if ($item->isDir()) {
        $plugins[$i] = $item;
        printf("%d: %s\n", $i, $item->getBasename());
        $i++;
      }
    }

    $userInput = $this->askUserInput('Which plugins do you want to install (use "all" to install all plugins)?');
    $pluginsToInstall = preg_split('/\s+/', $userInput);
    if ($pluginsToInstall === FALSE) {
      throw new \Exception('Failed to parse "'.$userInput.'"');
    }

    foreach ($plugins as $i => $pluginPath) {

      // Add verification method if control.yaml is present in root plugin folder
      if (!file_exists($pluginPath."/control.yaml")) {
        throw new \Exception($pluginPath."/control.yaml".' does not exist');
      }
      if (in_array('all', $pluginsToInstall) || in_array($pluginPath->getBasename(), $pluginsToInstall) || in_array($i, $pluginsToInstall)) {
        echo 'Installing plugin '.$pluginPath->getBasename()."\n";
      }
      // Register the plugins within LDAP
      $this->addPluginRecord($pluginPath."/control.yaml");

      // Move the folders and files to correct directories
      $this->copyDirectory($pluginPath->getPathname().'/addons', $this->vars['fd_home'].'/plugins/addons');
      $this->copyDirectory($pluginPath->getPathname().'/admin', $this->vars['fd_home'].'/plugins/admin');
      $this->copyDirectory($pluginPath->getPathname().'/config', $this->vars['fd_home'].'/plugins/config');
      $this->copyDirectory($pluginPath->getPathname().'/personal', $this->vars['fd_home'].'/plugins/personal');
      $this->copyDirectory($pluginPath->getPathname().'/html', $this->vars['fd_home'].'/html');
      $this->copyDirectory($pluginPath->getPathname().'/ihtml', $this->vars['fd_home'].'/ihtml');
      $this->copyDirectory($pluginPath->getPathname().'/include', $this->vars['fd_home'].'/include');
      $this->copyDirectory($pluginPath->getPathname().'/contrib/openldap', $this->vars['fd_home'].'/contrib/openldap');
      $this->copyDirectory($pluginPath->getPathname().'/contrib/etc', $this->vars['fd_config_dir'].'/'.$pluginPath->getBasename());
      $this->copyDirectory($pluginPath->getPathname().'/contrib/doc', $this->vars['fd_home'].'/contrib/doc');
      $this->copyDirectory($pluginPath->getPathname().'/locale', $this->vars['fd_home'].'/locale/plugins/'.$pluginPath->getBasename().'/locale');
    }
  }

  public function removePlugin ()
  {
  }

  public function listPlugins ()
  {
  }

  public function copyDirectory (string $source, string $dest): void
  {
    if ($this->verbose()) {
      printf('Copy %s to %s'."\n", $source, $dest);
    }

    if (file_exists($source)) {
      if (!file_exists($dest)) {
        if (mkdir($dest, 0755, TRUE) === FALSE) {
          throw new \Exception('Unable to create "'.$dest.'"');
        }
      }

      $Directory = new \FilesystemIterator($source);

      foreach ($Directory as $file) {
        /** @var \SplFileInfo $file */
        if ($file->isDir()) {
          $this->copyDirectory($file->getPathname(), $dest.'/'.$file->getBasename());
        } else {
          if (copy($file->getPathname(), $dest.'/'.$file->getBasename()) === FALSE) {
            throw new \Exception('Unable to copy '.$file->getPathname().' to '.$dest.'/'.$file->getBasename());
          }
        }
      }
    }
  }


}
