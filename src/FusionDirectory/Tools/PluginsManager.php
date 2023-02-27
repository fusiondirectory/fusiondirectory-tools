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
    "fdPluginManagerInfoVersion"             => "information:version",
    "fdPluginManagerInfoAuthors"             => "information:authors",
    "fdPluginManagerInfoStatus"              => "information:status",
    "fdPluginManagerInfoScreenshotUrl"       => "information:screenshotUrl",
    "fdPluginManagerInfoLogoUrl"             => "information:logoUrl",
    "fdPluginManagerInfoTags"                => "information:tags",
    "fdPluginManagerInfoLicence"             => "information:license",
    "fdPluginManagerInfoOrigin"              => "information:origin",
    "fdPluginManagerSupportProvider"         => "support:provider",
    "fdPluginManagerSupportHomeUrl"          => "support:homeUrl",
    "fdPluginManagerSupportTicketUrl"        => "support:ticketUrl",
    "fdPluginManagerSupportDiscussionUrl"    => "support:discussionUrl",
    "fdPluginManagerSupportDownloadUrl"      => "support:downloadUrl",
    "fdPluginManagerSupportSchemaUrl"        => "support:schemaUrl",
    "fdPluginManagerSupportContractUrl"      => "support:contractUrl",
    "fdPluginManagerReqFdVersion"            => "requirement:fdVersion",
    "fdPluginManagerReqPhpVersion"           => "requirement:phpVersion",
    "fdPluginManagerReqPlugins"              => "requirement:plugins",
    "fdPluginManagerContentPhpClass"         => "content:phpClassList",
    "fdPluginManagerContentLdapObject"       => "content:ldapObjectList",
    "fdPluginManagerContentLdapAttributes"   => "content:ldapAttributeList",
  ];

  public function __construct ()
  {
    parent::__construct();

    // Variables to be set during script calling.
    $this->vars = [
      'fd_home'         => '/usr/share/fusiondirectory',
      'fd_config_dir'     => '/etc/fusiondirectory',
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

  // Load Setup and Ldap objects and create branch pluginManager if required.
  protected function requirements ()
  {
    // Instantiation of setup to get FD configurations + avoid if already loaded.
    if (!is_object($this->ldap)) {
      $setup = new Setup();
      $this->conf = $setup->loadFusionDirectoryConfigurationFile();

      $this->ldap = new Ldap\Link($this->conf['default']['uri']);
      $this->ldap->bind($this->conf['default']['bind_dn'], $this->conf['default']['bind_pwd']);

      if (!$this->branchExist('ou=pluginManager,'.$this->conf['default']['base'])) {
        $this->createBranchPlugins();
      }
    }
  }

  // function that add plugin record
  // $params Path to the yaml file to be read.
  public function addPluginRecord (array $path) : bool
  {
    // This below could be iterated easily to add multiple plugins all at once.
    // Add verification method if description.yaml is present in root plugin folder
    if (!file_exists($path[0]."/contrib/yaml/description.yaml")) {
      throw new \Exception($path[0]."/contrib/yaml/description.yaml".' does not exist');
    }

    $pluginInfo = yaml_parse_file($path[0].'/contrib/yaml/description.yaml');
    print_r($pluginInfo);
    // verification if the origin are set to source
    if (!empty($pluginInfo['information']['origin']) && $pluginInfo['information']['origin'] !== 'source') {
      throw new \Exception('Error, the plugin does not comes from proper origin.');
    }

    // Load Setup and Ldap objects and create branch pluginManager if required.
    $this->requirements();

    // Create the proper CN
    $pluginDN = "cn=".$pluginInfo['information']['name'].",ou=pluginManager,".$this->conf['default']['base'];

    // Verifying if the record of the plugin already exists and delete it.
    if ($this->branchExist($pluginDN)) {
      echo 'Branch : ' .$pluginDN.' already exists, re-installing...' .PHP_EOL;
      $this->deletePluginRecord([$pluginDN]);
    }

    // Collect and arrange the info received by the yaml file.
    $obj = ['objectClass' => ['top','fdPluginManager']];

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
   * Create ou=pluginManager LDAP branch
   */
  protected function createBranchPlugins (): void
  {
    printf('Creating branch %s'."\n", 'ou=pluginManager');
    try {
      $branchAdd = $this->ldap->add(
        'ou=pluginManager,'.$this->conf['default']['base'],
        [
          'ou'          => 'pluginManager',
          'objectClass' => 'organizationalUnit',
        ]
      );
    } catch (Ldap\Exception $e) {
      printf('Error while creating branch : %s !'."\n", 'ou=pluginManager');
      throw $e;
    }
  }

  // Method which either receive the full DN or the name of the plugin.
  public function deletePluginRecord (array $dn)
  {
    $this->requirements();
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
      printf('Deleted %s from LDAP successfully.'."\n", $dn);
    } else {
      $pluginDN = "cn=".$dn.",ou=pluginManager,".$this->conf['default']['base'];
      try {
        $msg = $this->ldap->delete($pluginDN);
        $msg->assert();
      } catch (Ldap\Exception $e) {
        printf('Error while deleting branch : %s !'."\n", $dn);
        throw $e;
      }
      printf('Deleted %s from LDAP successfully.'."\n", $dn);
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
      $this->copyDirectory($pluginPath->getPathname().'/contrib/yaml', $this->vars['fd_config_dir'].'/yaml/'.$pluginPath->getBasename().'/');
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
    $this->requirements();

    foreach ($info as $pluginName) {

      $this->deletePluginRecord([$pluginName]);
      $pluginInfo = yaml_parse_file($this->vars['fd_config_dir'].'/yaml/'.$pluginName.'/description.yaml');

      foreach ($pluginInfo['content']['fileList'] as $file) {
        // Get the first dir from the path
        $dirs = explode('/', $file);
        // remove the './' unrequired provided from $file
        array_shift($dirs);
        // Get the finale path required to delete the file with the './' removed.
        $final_path = implode('/', $dirs);

        switch ($dirs[0]) {
          case 'addons':
            $this->removeFile($this->vars['fd_home'].'/plugins/'.$final_path);
            break;
          case 'config':
            $this->removeFile($this->vars['fd_home'].'/plugins/'.$final_path);
            break;
          case 'personal':
            $this->removeFile($this->vars['fd_home'].'/plugins/'.$final_path);
            break;
          case 'admin':
            $this->removeFile($this->vars['fd_home'].'/plugins/'.$final_path);
            break;
          case 'html':
            $this->removeFile($this->vars['fd_home'].'/plugins/'.$final_path);
            break;
          case 'ihtml':
            $this->removeFile($this->vars['fd_home'].'/plugins/'.$final_path);
            break;
          case 'include':
            $this->removeFile($this->vars['fd_home'].'/plugins/'.$final_path);
            break;
          case 'contrib':
            if ($dirs[1] == 'openldap') {
              $this->removeFile($this->vars['fd_home'].'/'.$final_path);
            }
            if ($dirs[1] == 'etc') {
              $this->removeFile($this->vars['fd_config_dir'].'/'.basename(dirname($final_path)).'/'.basename($final_path));
            }
            break;
          case 'local':
            $this->removeFile($this->vars['fd_home'].'/locale/plugins/'.$pluginName.'/locale/'.basename(dirname($final_path)).'/'.basename($final_path));
            break;
        }
      }
      // Finally delete the yaml file of the plugin.
      $this->removeFile($this->vars['fd_config_dir'].'/yaml/'.$pluginName.'/description.yaml');
    }
  }

  // Simply remove files provided in args
  public function removeFile (string $file) : void
  {
    if (!file_exists($file)) {
      throw new \Exception('Unable to delete : '.$file.' it does not exist.');
    } else {
      if (!unlink($file)) {
        throw new \RuntimeException("Failed to unlink {$file}: " . var_export(error_get_last(), TRUE));
      } else {
        echo "unlink: {$file}" .PHP_EOL;
      }
    }
  }

  public function listPlugins ()
  {
    $this->requirements();

    $pluginattrs = ['cn','description','fdPluginManagerInfoAuthors','fdPluginManagerInfoVersion','fdPluginManagerSupportHomeUrl','fdPluginManagerInfoStatus','fdPluginManagerSupportProvider','fdPluginManagerInfoOrigin'];

    $mesg = $this->ldap->search("ou=pluginManager,".$this->conf['default']['base'], "(objectClass=fdPluginManager)", $pluginattrs);
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

      printf('Copy %s to %s'."\n", $source, $dest);
    }
    // Here should be an else reporting source file not found, but previous code force copy of possible unexisting dir.
  }
}
