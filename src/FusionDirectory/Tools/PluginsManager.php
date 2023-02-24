<?php
/*
  This code is part of ldap-config-manager (https://www.fusiondirectory.org/)

  Copyright (C) 2023  FusionDirectory

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

  // Definition of variables
  protected $ldap; //serving to instantiate ldap object.
  protected $conf; //serving to instantiate setup object and fetch FD configurations details.

  private $pluginmanagementmapping = [
    "cn"                              => "information:name",
    "description"                     => "information:description",
    "fdPluginInfoVersion"             => "information:version",
    "fdPluginInfoAuthors"             => "information:authors",
    "fdPluginInfoStatus"              => "information:status",
    "fdPluginInfoScreenshotUrl"       => "information:screenshotUrl",
    "fdPluginInfoLogoUrl"             => "information:logoUrl",
    "fdPluginInfoTags"                => "information:tags",
    "fdPluginInfoLicence"             => "information:license",
    "fdPluginInfoOrigin"              => "information:origin",
    "fdPluginSupportProvider"         => "support:provider",
    "fdPluginSupportHomeUrl"          => "support:homeUrl",
    "fdPluginSupportTicketUrl"        => "support:ticketUrl",
    "fdPluginSupportDiscussionUrl"    => "support:discussionUrl",
    "fdPluginSupportDownloadUrl"      => "support:downloadUrl",
    "fdPluginSupportSchemaUrl"        => "support:schemaUrl",
    "fdPluginSupportContractUrl"      => "support:contractUrl",
    "fdPluginReqFdVersion"            => "requirement:fdVersion",
    "fdPluginReqPhpVersion"           => "requirement:phpVersion",
    "fdPluginReqPlugins"              => "requirement:plugins",
    "fdPluginContentPhpClass"         => "content:phpClassList",
    "fdPluginContentLdapObject"       => "content:ldapObjectList",
    "fdPluginContentLdapAttributes"   => "content:ldapAttributeList",
    "fdPluginContentFileList"         => "content:fileList",
  ];

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
        'register-plugin:'  => [
          'help'        => 'Register plugin within LDAP',
          'command'     => 'addPluginRecord',
        ],
        'unregister-plugin:'  => [
          'help'        => 'Delete entry within LDAP',
          'command'     => 'deletePluginRecord',
        ],
        'install-plugin:'  => [
          'help'        => 'Install plugin within FD and register it within LDAP',
          'command'     => 'installPlugin',
        ],
        'remove-plugin:'  => [
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

  // Following method is extracted from setup and should be set in a library.
  protected function connectLdap ()
  {
    // Instantiation of setup to get FD configurations + avoid if already loaded.
    if (!is_object($this->ldap)) {
      $setup = new Setup();
      $this->conf = $setup->loadFusionDirectoryConfigurationFile();

      $this->ldap = new Ldap\Link($this->conf['default']['uri']);
      $this->ldap->bind($this->conf['default']['bind_dn'], $this->conf['default']['bind_pwd']);
    }
  }

  // function that add plugin record
  // $params Path to the yaml file to be read.
  public function addPluginRecord (array $path) : bool
  {
    // Add verification method if description.yaml is present in root plugin folder
    if (!file_exists($path[0]."/description.yaml")) {
      throw new \Exception($path[0]."/description.yaml".' does not exist');
    }
    $pluginInfo = yaml_parse_file($path[0].'/description.yaml');

    $this->connectLdap();

    if (!$this->branchExist('ou=plugins,'.$this->conf['default']['base'])) {
      $this->createBranchPlugins();
    }

    // Create the proper CN
    $pluginDN = "cn=".$pluginInfo['information']['name'].",ou=plugins,".$this->conf['default']['base'];

    // Verifying if the record of the plugin already exists and delete it.
    if ($this->branchExist($pluginDN)) {
      echo 'Branch : ' .$pluginDN.' already exists, re-installing...' .PHP_EOL;
      $this->deletePluginRecord([$pluginDN]);
    }

    // Collect and arrange the info received by the yaml file.
    $obj = ['objectClass' => ['top','fdPlugin']];

    foreach (array_keys($this->pluginmanagementmapping) as $k) {
      $section = preg_split('/:/', $this->pluginmanagementmapping[$k]);
      if (isset($pluginInfo[$section[0]][$section[1]])) {
        $obj[$k] = $pluginInfo[$section[0]][$section[1]];
      }
    }

    // register within ldap
    try {
      $msg = $this->ldap->add($pluginDN, $obj);
      $msg->assert();
    } catch (Ldap\Exception $e) {
      echo "Error while creating LDAP entries" .PHP_EOL;
      throw $e;
    }
    echo 'Installing : '.$pluginDN.' ...' .PHP_EOL;

    return TRUE;
  }

  protected function branchExist (string $dn): bool
  {
    // Initiate variable to be compliant phpstan.
    $branchList = NULL;
    // Verify if the branch for plugins already exist and create it if not.
    try {
      $branchList = $this->ldap->search($dn, '(objectClass=*)', [], 'base');
    } catch (Ldap\Exception $e) {

      return FALSE;
    }
    return ($branchList->count() > 0);
  }

  /**
   * Create ou=plugins LDAP branch
   */
  protected function createBranchPlugins (): void
  {
    printf('Creating branch %s'."\n", 'ou=plugins');
    try {
      $branchAdd = $this->ldap->add(
        'ou=plugins,'.$this->conf['base'],
        [
          'ou'          => 'plugins',
          'objectClass' => 'organizationalUnit',
        ]
      );
    } catch (Ldap\Exception $e) {
      printf('Error while creating branch : %s !'."\n", 'ou=plugins');
      throw $e;
    }
  }

  // Method which either receive the full DN or the name of the plugin.
  public function deletePluginRecord (array $dn)
  {
    $this->connectLdap();
    $dn = $dn[0];

    preg_match('/cn=.*,ou.*,dc=/', $dn, $match);
    if (isset($match[0]) && !empty($match[0])) {
      try {
        $msg = $this->ldap->delete($dn);
        $msg->assert();
      } catch (Ldap\Exception $e) {
        printf('Error while deleting branch : %s !'."\n", $dn);
        throw $e;
      }
      printf('Deleted %s successfully.'."\n", $dn);
    } else {
      $pluginDN = "cn=".$dn.",ou=plugins,".$this->conf['default']['base'];
      try {
        $msg = $this->ldap->delete($pluginDN);
        $msg->assert();
      } catch (Ldap\Exception $e) {
        printf('Error while deleting branch : %s !'."\n", $dn);
        throw $e;
      }
      printf('Deleted %s successfully.'."\n", $dn);
    }
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

      if (in_array('all', $pluginsToInstall) || in_array($pluginPath->getBasename(), $pluginsToInstall) || in_array($i, $pluginsToInstall)) {
        echo 'Installing plugin '.$pluginPath->getBasename().'.'."\n";
      }
      // Register the plugins within LDAP
      $this->addPluginRecord([$pluginPath]);

      // YAML description must be saved within : /etc/fusiondirectory/yaml/nomplugin/description.yaml
      $this->copyDirectory($pluginPath->getPathname().'/decription.yaml', $this->vars['fd_config_dir'].'/yaml/'.$pluginPath->getBasename().'/');
      $this->copyDirectory($pluginPath->getPathname().'/addons', $this->vars['fd_home'].'/plugins/addons');
      $this->copyDirectory($pluginPath->getPathname().'/admin', $this->vars['fd_home'].'/plugins/admin');
      $this->copyDirectory($pluginPath->getPathname().'/config', $this->vars['fd_home'].'/plugins/config');
      $this->copyDirectory($pluginPath->getPathname().'/personal', $this->vars['fd_home'].'/plugins/personal');
      $this->copyDirectory($pluginPath->getPathname().'/html', $this->vars['fd_home'].'/html');
      $this->copyDirectory($pluginPath->getPathname().'/ihtml', $this->vars['fd_home'].'/ihtml');
      $this->copyDirectory($pluginPath->getPathname().'/include', $this->vars['fd_home'].'/include');
      $this->copyDirectory($pluginPath->getPathname().'/contrib/openldap', $this->vars['fd_home'].'/contrib/openldap');
      $this->copyDirectory($pluginPath->getPathname().'/contrib/etc', $this->vars['fd_config_dir'].'/'.$pluginPath->getBasename());
      $this->copyDirectory($pluginPath->getPathname().'/locale', $this->vars['fd_home'].'/locale/plugins/'.$pluginPath->getBasename().'/locale');
    }
  }

  public function removePlugin (array $info)
  {
    $this->connectLdap();

    foreach($info as $pluginName) {
      
      // Subject to change as it has been decided not to record filelists within LDAP and to keep the yaml instead.
      try {
        $mesg = $this->ldap->search("ou=plugins,".$this->conf['default']['base'], "(&(objectClass=fdPlugin)(cn=".$pluginName."))", ['fdPluginContentFileList']);
        $mesg->assert();
      } catch (Ldap\Exception $e) {
        printf('Error while search branch : %s for content file list!'."\n", $pluginName);
        throw $e;
      }

      $this->deletePluginRecord($pluginName);

      $pluginInfo = yaml_parse_file($this->vars['fd_config_dir'].'/yaml/'.$pluginName.'/description.yaml');
      print_r($plufinInfo);
    }
  }

  public function listPlugins ()
  {
    $this->connectLdap();
    $pluginattrs = ['cn','description','fdPluginInfoAuthors','fdPluginInfoVersion','fdPluginSupportHomeUrl','fdPluginInfoStatus','fdPluginSupportProvider','fdPluginInfoOrigin'];

    $mesg = $this->ldap->search("ou=plugins,".$this->conf['default']['base'], "(objectClass=fdPlugin)", $pluginattrs);
    $mesg->assert();

    // Recuperate only the full DN of the plugin and extract the exact plugin's name.
    foreach ($mesg as $key => $value) {
      $count[] = $key;
      preg_match('/cn=(.*),ou=/', $key, $match);
      $plugins[] = $match[1];
    }

    if (isset($count) && count($count) !== 0) {
        echo "Number of plugins installed : ".count($count). PHP_EOL;
      if (isset($plugins)) {
        foreach ($plugins as $plugin) {
          echo "Plugin : ".$plugin. " is installed" .PHP_EOL;
        }
      }
    } else {
      echo "No plugins installed ..." .PHP_EOL;
    }
  }

  public function copyDirectory (string $source, string $dest): void
  {
    printf('Copy %s to %s'."\n", $source, $dest);

    if (file_exists($source)) {
      if (!file_exists($dest)) {
        if (mkdir($dest, 0755, TRUE) === FALSE) {
          throw new \Exception('Unable to create "'.$dest.'"');
        }
      }

      $Directory = new \FilesystemIterator($source);

      foreach ($Directory as $file) {
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
