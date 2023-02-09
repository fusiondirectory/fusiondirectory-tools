<?php
/*
  This code is part of FusionDirectory (https://www.fusiondirectory.org/)

  Copyright (C) 2020-2021 FusionDirectory

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

require 'FusionDirectory/Cli/LdapApplication.php';
require 'FusionDirectory/Cli/VarHandling.php';

use \FusionDirectory\Ldap;

/**
 * fusiondirectory-setup tool, which provides useful commands to inspect or fix LDAP data and FusionDirectory installation
 */
class Setup extends LdapApplication
{
  use VarHandling;

  protected const CONFIGRDN = 'cn=config,ou=fusiondirectory';

  /**
   * FusionDirectory directory and class.cache file's path declaration
   * @var array<string,string>
   */
  protected $vars;

  public function __construct ()
  {
    $this->vars = [
      'fd_home'           => '/usr/share/fusiondirectory',
      'fd_cache'          => '/var/cache/fusiondirectory',
      'fd_config_dir'     => '/etc/fusiondirectory',
      'fd_smarty_path'    => '/usr/share/php/smarty3/Smarty.class.php',
      'fd_spool_dir'      => '/var/spool/fusiondirectory',
      'ldap_conf'         => '/etc/ldap/ldap.conf',
      'config_file'       => 'fusiondirectory.conf',
      'secrets_file'      => 'fusiondirectory.secrets',
      'locale_dir'        => 'locale',
      'class_cache'       => 'class.cache',
      'locale_cache_dir'  => 'locale',
      'tmp_dir'           => 'tmp',
      'fai_log_dir'       => 'fai',
      'template_dir'      => 'template'
    ];

    parent::__construct();

    $this->options  = array_merge(
      $this->getVarOptions(),
      [
        'write-vars' => [
          'help'    => 'Choose FusionDirectory Directories',
          'command' => 'cmdWriteVars',
        ],
        'show-version'  => [
          'help'    => 'Show FusionDirectory version from variables_common.inc',
          'command' => 'cmdShowVersion',
        ],
        'check-config'  => [
          'help'    => 'Checking FusionDirectory\'s config file',
          'command' => 'cmdCheckConfigFile',
        ],
        'check-directories' => [
          'help'    => 'Checking FusionDirectory\'s directories',
          'command' => 'cmdCheckDirectories',
        ],
        'show-config' => [
          'help'    => 'Show an LDAP dump of the FusionDirectory configuration',
          'command' => 'cmdShowConfiguration',
        ],
        'set-config:' => [
          'help'    => 'Set the value in LDAP of a FusionDirectory configuration field',
          'command' => 'cmdSetConfigVar',
        ],
        'check-ldap' => [
          'help'    => 'Checking your LDAP tree',
          'command' => 'cmdCheckLdap',
        ],
        'update-cache' => [
          'help'    => 'Update class.cache file',
          'command' => 'cmdUpdateCache',
        ],
        'encrypt-passwords' => [
          'help'    => 'Encrypt passwords in fusiondirectory.conf',
          'command' => 'cmdEncryptPasswords',
        ],
        'show-passwords' => [
          'help'    => 'Show passwords from fusiondirectory.conf',
          'command' => 'cmdShowPasswords',
        ],
        'install-plugins:'  => [
          'help'    => 'Install all FusionDirectory plugins from an archive or directory',
          'command' => 'cmdInstallPlugins',
        ],
        'update-locales'  => [
          'help'    => 'Update translation files',
          'command' => 'cmdUpdateLocales',
        ],
      ],
      $this->options
    );
  }

  /**
   * Run the tool
   * @param array<string> $argv
   * @throws \FusionDirectory\Ldap\Exception
   */
  public function run (array $argv): void
  {
    parent::run($argv);

    if (isset($this->getopt['set-var']) && !empty($this->getopt['set-var'])) {
      $this->cmdSetVar($this->getopt['set-var'] ?? []);
      unset($this->getopt['set-var']);
    }

    $this->runCommands();
  }

  /**
   * Call appropriate methods depending on options passed to the tool
   * Print their description before running them
   */
  protected function runCommands (): void
  {
    foreach ($this->getopt as $key => $value) {
      if (isset($this->options[$key]['command']) && ($value > 0)) {
        printf("# %s\n", $this->options[$key]['help']);
        call_user_func([$this, $this->options[$key]['command']]);
      } elseif (isset($this->options[$key.':']['command'])) {
        printf("# %s: %s\n", $this->options[$key.':']['help'], implode(', ', $value));
        call_user_func([$this, $this->options[$key.':']['command']], $value);
      }
    }
  }

  /**
   * Load locations information from FusionDirectory configuration file
   * @return array<array{tls: bool, uri: string, base: string, bind_dn: string, bind_pwd: string}> locations
   */
  protected function loadFusionDirectoryConfigurationFile (): array
  {
    $this->configFilePath   = $this->vars['fd_config_dir'].'/'.$this->vars['config_file'];
    $this->secretsFilePath  = $this->vars['fd_config_dir'].'/'.$this->vars['secrets_file'];

    return parent::loadFusionDirectoryConfigurationFile();
  }

  /* Helpers */

  /**
   * Get the apache user group name
   */
  protected function getApacheGroup (): string
  {
    $apacheGroup = '';

    /* try to identify the running distribution, if detection fails, ask for user input */
    if (file_exists('/etc/debian_version')) {
      $apacheGroup = 'www-data';
    } elseif (file_exists('/etc/redhat-release') || file_exists('/etc/mageia-release')) {
      $apacheGroup = 'apache';
    } elseif (file_exists('/etc/SuSE-release')) {
      $apacheGroup = 'www';
    } elseif (file_exists('/etc/arch-release')) {
      $apacheGroup = 'http';
    } else {
      echo '! Looks like you are not a Debian, Suse, Redhat or Mageia, I don\'t know your distribution !'."\n";
      $apacheGroup = $this->askUserInput('What is your apache group?');
    }
    if ($this->verbose()) {
      printf('Detected apache group to be %s'."\n", $apacheGroup);
    }

    return $apacheGroup;
  }

  /**
   * Check the rights of a directory or file, create missing directory if needed
   */
  protected function checkRights (string $dir, string $user, string $group, int $rights, bool $create): bool
  {
    if (file_exists($dir)) {
      echo "$dir exists…\n";
      $lstat = lstat($dir);
      if ($lstat === FALSE) {
        throw new \Exception('Unable to read '.$dir.' permissions'."\n");
      }

      /* extract the owner and the group of the directory */
      $pwuid = posix_getpwuid($lstat['uid']);
      $grgid = posix_getpwuid($lstat['gid']);
      if (($pwuid === FALSE) || ($grgid === FALSE)) {
        throw new \Exception('Unable to read '.$dir.' ownership information'."\n");
      }
      $dir_owner = $pwuid['name'];
      $dir_group = $grgid['name'];

      /* extract the dir's rights */
      $dir_rights = ($lstat['mode'] & 000777);

      if ( ($dir_owner !== $user) || ($dir_group !== $group) || ($dir_rights !== $rights) ) {
        if ( $this->askYnQuestion("$dir is not set properly, do you want to fix it ?: ") ) {
          if ($dir_owner !== $user) {
            if ($this->verbose()) {
              printf('Setting %s ower to %s'."\n", $dir, $user);
            }
            if (chown($dir, $user) === FALSE) {
              throw new \Exception('Unable to change '.$dir.' owner'."\n");
            }
          }
          if ($dir_group !== $group) {
            if ($this->verbose()) {
              printf('Setting %s group to %s'."\n", $dir, $group);
            }
            if (chgrp($dir, $group) === FALSE) {
              throw new \Exception('Unable to change '.$dir.' group'."\n");
            }
          }
          if ($dir_rights !== $rights) {
            if ($this->verbose()) {
              printf('Setting %s rights to %o'."\n", $dir, $rights);
            }
            if (chmod($dir, $rights) === FALSE) {
              throw new \Exception('Unable to change '.$dir.' rights'."\n");
            }
          }
        } else {
          echo 'Skipping…'."\n";
        }
      } else {
        echo 'Rights on "'.$dir.'" are correct'."\n";
      }
    } elseif ($create) {
      if ($this->askYnQuestion("Directory $dir doesn't exists, do you want to create it ?: ")) {
        /* Create the directory, and change the rights */
        if ($this->verbose()) {
          printf('Creating %s with rights %o'."\n", $dir, $rights);
        }
        mkdir($dir, $rights, TRUE);
        if ($this->verbose()) {
          printf('Setting %s ower to %s'."\n", $dir, $user);
        }
        if (chown($dir, $user) === FALSE) {
          throw new \Exception('Unable to change '.$dir.' owner'."\n");
        }
        if ($this->verbose()) {
          printf('Setting %s group to %s'."\n", $dir, $group);
        }
        if (chgrp($dir, $group) === FALSE) {
          throw new \Exception('Unable to change '.$dir.' group'."\n");
        }
      } else {
        echo 'Skipping…'."\n";
      }
    } else {
      return FALSE;
    }
    return TRUE;
  }

  /**
   * Read FusionDirectory configuration in LDAP and return it
   * @param array<int,string> $attrs
   * @return array{0: string, 1: array<string,array<int,string>>}
   */
  protected function readLdapConfiguration (array $attrs = ['*']): array
  {
    $this->readFusionDirectoryConfigurationFileAndConnectToLdap();

    if ($this->verbose()) {
      printf('Fetching FusionDirectory configuration from %s'."\n", static::CONFIGRDN.','.$this->base);
    }
    $list = $this->ldap->search(static::CONFIGRDN.','.$this->base, '(objectClass=fusionDirectoryConf)', $attrs, 'base');
    $list->assert();

    if ($list->count() < 1) {
      throw new \Exception('Could not find FusionDirectory configuration in the LDAP');
    } elseif ($list->count() > 1) {
      throw new \Exception('Found several FusionDirectory configurations in the LDAP');
    }

    $list->rewind();
    $res = [$list->key(), $list->current()];
    $list->assertIterationWentFine();
    return $res;
  }

  /**
   * Check that an LDAP branch exists
   */
  protected function branchExists (string $dn): bool
  {
    try {
      /* Search for branch */
      $branchList = $this->ldap->search($dn, '(objectClass=*)', [], 'base');
      if ($branchList->errcode === 32) {
        return FALSE;
      }
      $branchList->assert();
    } catch (Ldap\Exception $e) {
      if ($e->getCode() === 32) {
        if ($this->verbose()) {
          printf('Branch %s does not exists'."\n", $dn);
        }
        return FALSE;
      }
      throw $e;
    }

    return ($branchList->count() > 0);
  }

  /**
   * Create an LDAP branch
   * @param string $ou branch in the form ou=name
   */
  protected function createBranch (string $ou): void
  {
    if (!preg_match('/^ou=([^,]+),?$/', $ou, $m)) {
      throw new \Exception("Can’t create branch of unknown type $ou");
    }
    if ($this->verbose()) {
      printf('Creating branch %s'."\n", $ou.','.$this->base);
    }
    $branchAdd = $this->ldap->add(
      $ou.','.$this->base,
      [
        'ou'          => $m[1],
        'objectClass' => 'organizationalUnit',
      ]
    );
    $branchAdd->assert();
  }

  /**
   * Check if there is an admin account.
   * Propose to add it if it is missing.
   * @param array<string,array<string>> $config
   * @param array<int,string> $peopleBranches
   */
  protected function checkAdmin (array $config, array $peopleBranches): void
  {
    /* Search for admin role */
    $adminRoles = $this->ldap->search(
      $this->base,
      '(&(objectClass=gosaRole)(gosaAclTemplate=*:all;cmdrw))',
      ['gosaAclTemplate']
    );
    $adminRoles->assert();
    $dns    = [];
    $roles  = [];
    $count  = 0;
    foreach ($adminRoles as $dn => $entry) {
      $role_dn64 = base64_encode($dn);
      $roles[] = $role_dn64;
      printf('Role "%s" is an admin ACL role'."\n", $dn);

      /* Search for base-wide assignments */
      $assignments = $this->ldap->search(
        $this->base,
        '(&(objectClass=gosaAcl)(gosaAclEntry=*:subtree:'.ldap_escape($role_dn64, '', LDAP_ESCAPE_FILTER).':*))',
        ['gosaAclEntry'],
        'base',
      );
      $assignments->assert();
      foreach ($assignments as $assignment) {
        foreach ($assignment['gosaAclEntry'] as $line) {
          if (preg_match('/^.:subtree:\Q'.$role_dn64.'\E/', $line)) {
            $parts    = explode(':', $line, 4);
            $members  = explode(',', $parts[3]);
            foreach ($members as $member) {
              /* Is this an existing user? */
              $dn = base64_decode($member);
              $memberNode = $this->ldap->search($dn, '(objectClass=inetOrgPerson)', [], 'base');
              $memberNode->assert();
              if ($memberNode->count() === 1) {
                printf('%s is a valid admin'."\n", $dn);
                return;
              }
              /* Is this a group? */
              $memberNode = $this->ldap->search($dn, '(objectClass=posixGroup)', ['memberUid'], 'base');
              $memberNode->assert();
              if ($memberNode->count() === 1) {
                /* Find group members */
                $memberNode->rewind();
                $memberEntry  = $memberNode->current();
                $filter = '(&(objectClass=inetOrgPerson)(|(uid='.implode(')(uid=', $memberEntry['memberUid']).')))';
                $groupMembers = $this->ldap->search($this->base, $filter);
                $groupMembers->assert();
                if ($groupMembers->count() > 0) {
                  $groupMembers->rewind();
                  printf('%s is a valid admin'."\n", $groupMembers->key());
                  return;
                }
              } else {
                $dns[] = $dn;
              }
            }
          }
        }
      }
      $count++;
    }
    if ($count < 1) {
      echo '! There is no admin ACL role'."\n";
    }
    foreach ($dns as $dn) {
      printf('! %s is supposed to be admin but does not exists'."\n", $dn);
    }
    if ($this->askYnQuestion('No valid admin account found, do you want to create it ?')) {
      $this->addLdapAdmin($config, $peopleBranches, $roles);
    }
  }

  /**
   * Add the FusionDirectory's admin account
   * @param array<string,array<string>> $config
   * @param array<int,string> $peopleBranches
   * @param array<int,string> $roles
   */
  protected function addLdapAdmin (array $config, array $peopleBranches, array $roles): void
  {
    $attr = ($config['fdAccountPrimaryAttribute'][0] ?? 'uid');

    if (isset($config['fdForcePasswordDefaultHash'][0]) && isset($config['fdPasswordDefaultHash'][0])) {
      if (($config['fdForcePasswordDefaultHash'][0] === 'TRUE') && (strtolower($config['fdPasswordDefaultHash'][0]) !== 'ssha')) {
        echo 'Warning: Administator password will be hashed with ssha instead of forced default '.$config['fdPasswordDefaultHash'][0]."\n";
      }
    }

    /* Sort branches by length to have the root one first */
    usort($peopleBranches, function($a, $b) {
      return strlen($b) <=> strlen($a);
    });

    $fdAdminUid = $this->askUserInput('Please enter a login for FusionDirectory\'s admin', 'fd-admin');

    /* Does this user exists? */
    $dn = '';
    foreach ($peopleBranches as $peopleBranch) {
      $list = $this->ldap->search(
        $peopleBranch,
        '(&(objectClass=inetOrgPerson)(uid='.ldap_escape($fdAdminUid, '', LDAP_ESCAPE_FILTER).'))',
        ['uid']
      );
      $list->assert();
      if ($list->count() > 0) {
        printf('User %s already existing, adding admin acl to it'."\n", $fdAdminUid);
        $list->rewind();
        $dn = $list->key();
        break;
      }
    }

    if ($dn === '') {
      $fdAdminPwd         = $this->askUserInput('Please enter FusionDirectory\'s admin password', '', TRUE);
      $fdAdminPwdConfirm  = $this->askUserInput('Please enter it again', '', TRUE);

      /* While the confirmation password is not the same than the first one */
      while (($fdAdminPwdConfirm !== $fdAdminPwd) && ($fdAdminPwdConfirm !== 'quit')) {
        $fdAdminPwdConfirm = $this->askUserInput('! Inputs don\'t match, try again or type "quit" to end this function');
      }
      if ($fdAdminPwdConfirm === 'quit') {
        return;
      }

      /* FIXME: Directly call FD code here? (either to hash password, or even to create user) */
      $salt = substr(pack('h*', md5((string)random_int(0, PHP_INT_MAX))), 0, 8);
      $salt = substr(pack('H*', sha1($salt.$fdAdminPwd)), 0, 4);
      $hashedPasswd  = '{SSHA}'.base64_encode(pack('H*', sha1($fdAdminPwd.$salt)).$salt);
      $obj = [
        'cn'            => 'System Administrator',
        'givenname'     => 'System',
        'sn'            => 'Administrator',
        'uid'           => $fdAdminUid,
        'objectclass'   => ['person', 'organizationalPerson', 'inetOrgPerson'],
        'userPassword'  => $hashedPasswd,
      ];
      if (!isset($obj[$attr])) {
        printf('Error: invalid account primary attribute %s, using uid'."\n", $attr);
        $attr = 'uid';
      }
      $dn = $attr.'='.ldap_escape($obj[$attr], '', LDAP_ESCAPE_DN).','.$peopleBranches[0];

      /* Add the administator user object */
      if ($this->verbose()) {
        printf('Creating user %s'."\n", $dn);
      }
      $adminAdd = $this->ldap->add($dn, $obj);
      $adminAdd->assert();
    }

    /* Create admin role if not existing */
    if (count($roles) === 0) {
      $roleDn = $this->createRole('admin', 'all;cmdrw', ($config['fdAclRoleRDN'][0] ?? 'ou=aclroles'));
      $role = base64_encode($roleDn);
    } else {
      $role = $roles[0];
    }

    $acls = $this->ldap->search(
      $this->base,
      '(objectClass=*)',
      ['objectClass', 'gosaAclEntry'],
      'base',
    );
    $acls->assert();
    if ($acls->count() === 0) {
      throw new \Exception('Failed to search acls in "'.$this->base.'"');
    }
    $acls->rewind();
    $oclass = $acls->current()['objectClass'];
    /* Add admin acl */
    $newacl = ['0:subtree:'.$role.':'.base64_encode($dn)];
    if (!in_array('gosaAcl', $oclass)) {
      $oclass[] = 'gosaAcl';
    } else {
      $acl = ($acls->current()['gosaAclEntry'] ?? []);
      $i = 1;
      foreach ($acl as $line) {
        /* Reorder existing non-admin acls */
        $line     = preg_replace('/^\d+:/', $i.':', $line);
        $newacl[] = $line;
        $i++;
      }
    }
    $result = $this->ldap->mod_replace(
      $this->base,
      [
        'objectClass'   => $oclass,
        'gosaAclEntry'  => $newacl,
      ]
    );
    $result->assert();
  }

  /**
   * Insert a new role object in the LDAP
   */
  protected function createRole (string $cn, string $acl, string $aclrolerdn): string
  {
    $role = [
      'cn'              => $cn,
      'objectclass'     => ['gosaRole'],
      'gosaAclTemplate' => '0:'.$acl,
    ];

    if (!$this->branchExists($aclrolerdn.','.$this->base)) {
      $this->createBranch($aclrolerdn);
    }

    $roleDn = 'cn='.ldap_escape($cn, '', LDAP_ESCAPE_DN).','.$aclrolerdn.','.$this->base;
    if ($this->verbose()) {
      printf('Creating role %s'."\n", $roleDn);
    }
    $roleAdd = $this->ldap->add($roleDn, $role);
    $roleAdd->assert();
    return $roleDn;
  }

  /**
   * Scan recursivly a directory to find .inc files
   * @return array<string,string>
   */
  protected function getClassesList (string $path): array
  {
    /* Recursive iterator on the directory */
    $Directory  = new \RecursiveDirectoryIterator($path, \FilesystemIterator::KEY_AS_PATHNAME | \FilesystemIterator::CURRENT_AS_PATHNAME);
    /* Flatten the iterator to iterate directly on all files in the tree */
    $Iterator   = new \RecursiveIteratorIterator($Directory);
    /* Filter by regex */
    $Regex      = new \RegexIterator($Iterator, '/^.+\.inc$/i');

    $classes = [];
    foreach ($Regex as $filepath) {
      if (!preg_match('/.*smarty.*/', $filepath)) {
        $lines    = file($filepath);
        if ($lines === FALSE) {
          continue;
        }
        $filepath = preg_replace('/^'.preg_quote($this->vars['fd_home'], '/').'/', '', $filepath);
        foreach ($lines as $line) {
          if (preg_match('/^((abstract )?class|interface)\s*(\w+).*/', $line, $m)) {
            $classes[(string)$m[3]] = $filepath;
          }
        }
      }
    }

    return $classes;
  }

  /**
   * Set file permissions and ownership information
   */
  protected function setFileRights (string $path, int $rights, string $user, string $group): void
  {
    if ($this->verbose()) {
      printf('Setting file %s rights and owner to %o %s:%s'."\n", $path, $rights, $user, $group);
    }
    if (chmod($path, $rights) === FALSE) {
      throw new \Exception('Unable to change "'.$path.'" rights');
    }
    if (chown($path, $user) === FALSE) {
      throw new \Exception('Unable to change "'.$path.'" owner');
    }
    if (chgrp($path, $group) === FALSE) {
      throw new \Exception('Unable to change "'.$path.'" group');
    }
  }

  /**
   * Create a directory and copy plugin files in it
   */
  protected function copyDirectory (string $source, string $dest): void
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

  /* Commands */

  /**
   * Write vars into variables.inc file for FusionDirectory
   */
  protected function cmdWriteVars (): void
  {
    $filecontent = <<<EOF
<?php
/*!
 * \\file variables.inc
 * Define common locations and variables
 * Generated by fusiondirectory-setup
 */

require_once('variables_common.inc');

if (!defined("CONFIG_DIR")) {
  define("CONFIG_DIR", "{$this->vars['fd_config_dir']}/"); /* FusionDirectory etc path */
}

/*!
 * \brief Allow setting the config file in the apache configuration
 *   e.g.  SetEnv CONFIG_FILE fusiondirectory.conf 1.0
 */
if (!defined("CONFIG_FILE")) {
  define("CONFIG_FILE", "{$this->vars['config_file']}"); /* FusionDirectory filename */
}

/*!
 * \brief Path for smarty3 libraries
 */
define("SMARTY", "{$this->vars['fd_smarty_path']}");

/*!
 * \brief Smarty compile dir
 */
define("SPOOL_DIR", "{$this->vars['fd_spool_dir']}/"); /* FusionDirectory spool directory */

/*!
 * \brief Global cache dir
 */
define("CACHE_DIR", "{$this->vars['fd_cache']}/"); /* FusionDirectory var directory */

/*!
 * \brief Global locale cache dir
 */
define("LOCALE_DIR", "{$this->vars['fd_cache']}/{$this->vars['locale_cache_dir']}/"); /* FusionDirectory locale directory */

/*!
 * \brief Global tmp dir
 */
define("TEMP_DIR", "{$this->vars['fd_cache']}/{$this->vars['tmp_dir']}/"); /* FusionDirectory tmp directory */

/*!
 * \brief Directory containing the configuration template
 */
define("CONFIG_TEMPLATE_DIR", "{$this->vars['fd_cache']}/{$this->vars['template_dir']}/"); /* FusionDirectory template directory */

/*!
 * \brief Directory containing the fai logs
 */
define("FAI_LOG_DIR", "{$this->vars['fd_cache']}/{$this->vars['fai_log_dir']}/"); /* FusionDirectory fai directory */

/*!
 * \brief Directory containing the vacation files
 */
define("SUPANN_DIR", "{$this->vars['fd_config_dir']}/supann/"); /* FusionDirectory supann template directory */

/*!
 * \brief name of the class.cache file
 */
define("CLASS_CACHE", "{$this->vars['class_cache']}"); /* name of the class cache */
EOF;

    $variablesPath = $this->vars['fd_home'].'/include/variables.inc';
    $success = file_put_contents($variablesPath, $filecontent);
    if ($success === FALSE) {
      throw new \Exception('Failed to write in "'.$variablesPath.'"');
    }
  }

  /**
   * Show FusionDirectory version string
   */
  protected function cmdShowVersion (): void
  {
    $variablesPath = $this->vars['fd_home'].'/include/variables_common.inc';
    if ($this->verbose()) {
      printf('Reading version information from %s'."\n", $variablesPath);
    }
    if (file_exists($variablesPath)) {
      $lines = file($variablesPath, FILE_SKIP_EMPTY_LINES);
      if ($lines === FALSE) {
        throw new \Exception('Could not open "'.$variablesPath.'"');
      }
      foreach ($lines as $line) {
        if (preg_match('/^define\\s*\\(["\']FD_VERSION["\'],\\s*"([^"]+)"\\);/', $line, $m)) {
          echo 'FusionDirectory version is '.$m[1]."\n";
          return;
        }
      }
      throw new \Exception('File "'.$variablesPath.'" does not contain version information'."\n");
    } else {
      throw new \Exception('File "'.$variablesPath.'" does not exists, can’t find out FusionDirectory version'."\n");
    }
  }

  /**
   * Check FusionDirectory configuration file permissions and ownership
   */
  protected function cmdCheckConfigFile (): void
  {
    $apache_group = $this->getApacheGroup();

    if (!$this->checkRights($this->vars['fd_config_dir'].'/'.$this->vars['config_file'], 'root', $apache_group, 0640, FALSE)) {
      throw new \Exception('The config file does not exists!');
    }
  }

  /**
   * Check FusionDirectory's directories
   */
  protected function cmdCheckDirectories (): void
  {
    $apache_group = $this->getApacheGroup();

    $root_config_dirs   = [
      $this->vars['fd_home'],
      $this->vars['fd_config_dir']
    ];
    $apache_config_dirs = [
      $this->vars['fd_spool_dir'],
      $this->vars['fd_cache'],
      $this->vars['fd_cache'].'/'.$this->vars['tmp_dir'],
      $this->vars['fd_cache'].'/'.$this->vars['fai_log_dir'],
      $this->vars['fd_cache'].'/'.$this->vars['template_dir'],
    ];

    foreach ($root_config_dirs as $dir) {
      $this->checkRights($dir, 'root', 'root', 0755, TRUE);
    }

    foreach ($apache_config_dirs as $dir) {
      $this->checkRights($dir, 'root', $apache_group, 0770, TRUE);
    }
  }

  /**
   * Dump FusionDirectory configuration from LDAP
   */
  protected function cmdShowConfiguration (): void
  {
    [$dn, $config] = $this->readLdapConfiguration();

    echo "dn:$dn\n\n";
    foreach ($config as $attribute => $values) {
      foreach ($values as $i => $value) {
        if ($i === 0) {
          printf("%40s: %s\n", $attribute, $value);
        } else {
          printf(str_repeat(' ', 40)."  %s\n", $value);
        }
      }
    }
  }

  /**
   * Set value for a FusionDirectory configuration variable in the LDAP
   * @param array<string> $vars
   */
  protected function cmdSetConfigVar (array $vars): void
  {
    $this->readFusionDirectoryConfigurationFileAndConnectToLdap();

    foreach ($vars as $var) {
      if (preg_match('/^([^=]+)=(.+)$/', $var, $m)) {
        [$var, $value] = [(string)$m[1], (string)$m[2]];
        if (!(preg_match('/^fd/', $var))) {
          $var = 'fd'.$var;
        }

        printf('Setting configuration var %s to "%s"'."\n", $var, $value);

        $result = $this->ldap->mod_replace(static::CONFIGRDN.','.$this->base, [$var => $value]);
        $result->assert();
      } else {
        throw new \Exception('Incorrect syntax for --set-config: "'.$var.'". Use var=value');
      }
    }
  }

  /**
   * Check LDAP tree content
   */
  protected function cmdCheckLdap (): void
  {
    $this->readFusionDirectoryConfigurationFileAndConnectToLdap();

    $admin_add = "";
    [$configdn, $config] = $this->readLdapConfiguration();
    $userrdn = ($config['fdUserRDN'][0] ?? '');
    $grouprdn = ($config['fdOGroupRDN'][0] ?? '');

    if ($userrdn !== '') {
      /* Collect existing people branches (even if main one may not exists) */
      $people = $this->ldap->search($this->base, $userrdn);
      $people->assert();
      $peopleBranches = [];
      foreach ($people as $dn => $entry) {
        $peopleBranches[] = $dn;
      }
      $people->assertIterationWentFine();

      if ($this->branchExists($userrdn.','.$this->base)) {
        /* if people branch exists */
        $this->checkAdmin($config, $peopleBranches);
      } else {
        /* if ou=people doesn't exists */
        echo '! '.$userrdn.','.$this->base.' not found in your LDAP directory'."\n";

        if ($this->askYnQuestion('Do you want to create it ?: ')) {
          $this->createBranch($userrdn);
          $peopleBranches[] = $userrdn.','.$this->base;
          $this->checkAdmin($config, $peopleBranches);
        } else {
          echo 'Skipping…'."\n";
        }
      }
    }

    if (!$this->branchExists($grouprdn.','.$this->base)) {
      echo '! '.$grouprdn.','.$this->base.' not found in your LDAP directory'."\n";

      if ($this->askYnQuestion('Do you want to create it ?: ')) {
        $this->createBranch($grouprdn);
      } else {
        echo 'Skipping…'."\n";
      }
    }

    /* Search for workstations and object groups */
    $faiclasses = $this->ldap->search($this->base, '(&(FAIclass=*)(!(objectClass~=FAIprofile)))');
    $faiclasses->assert();
    foreach ($faiclasses as $dn => $entry) {
      $faiclass = $entry['FAIclass'][0];
      $profiles = explode(' ', $faiclass);
      if (count($profiles) > 2) {
        printf('! System or group "%s" have more than one FAI profile : %s'."\n", $entry['cn'][0], $faiclass);
      } elseif (count($profiles) < 2) {
        printf('! System or group "%s" have no release set in its FAIclass : %s'."\n", $entry['cn'][0], $faiclass);
      }
    }

    /* Search for old config dn */
    if ($this->branchExists('cn=fusiondirectory,ou=configs,'.$this->base)) {
      printf('! There is a configuration in cn=fusiondirectory,ou=configs,%s in your LDAP directory'."\n", $this->base);
      printf('! The correct configuration dn is now %s,%s'."\n", static::CONFIGRDN, $this->base);
      printf('! FusionDirectory will not read your configuration at its current dn'."\n");

      if ($this->askYnQuestion('Do you want to move and rename this entry? ')) {
        if (!$this->branchExists('ou=fusiondirectory,'.$this->base)) {
          $this->createBranch('ou=fusiondirectory');
        }
        $result = $this->ldap->rename(
          'cn=fusiondirectory,ou=configs,'.$this->base,
          'cn=config', 'ou=fusiondirectory,'.$this->base,
          TRUE,
        );
        $result->assert();
      } else {
        echo 'Skipping…'."\n";
      }
    }
  }

  /**
   * Create or update /var/cache/fusiondirectory/class.cache
   */
  protected function cmdUpdateCache (): void
  {
    $classes = $this->getClassesList($this->vars['fd_home']);

    /* Create or overwrite cache file */
    if ($this->verbose()) {
      printf('Updating %s'."\n", $this->vars['fd_cache'].'/'.$this->vars['class_cache']);
    }
    $file = new \SplFileObject($this->vars['fd_cache'].'/'.$this->vars['class_cache'], 'w');

    $file->fwrite("<?php\n\$class_mapping = ");
    $file->fwrite(var_export($classes, TRUE));
    $file->fwrite(";\n");
  }

  /**
   * Encrypt LDAP passwords in fusiondirectory.conf
   */
  protected function cmdEncryptPasswords (): void
  {
    if (!class_exists('\FusionDirectory\Core\SecretBox')) {
      /* Temporary hack waiting for core namespace/autoload refactor */
      require_once($this->vars['fd_home'].'/include/SecretBox.inc');
    }
    $fdConfigFile   = $this->vars['fd_config_dir'].'/'.$this->vars['config_file'];
    $fdSecretsFile  = $this->vars['fd_config_dir'].'/'.$this->vars['secrets_file'];
    if (!file_exists($fdConfigFile)) {
      throw new \Exception("Cannot find a valid configuration file ($fdConfigFile)!");
    }
    if (file_exists($fdSecretsFile)) {
      throw new \Exception("There's already a file \"$fdSecretsFile\". Cannot convert your existing fusiondirectory.conf - aborted");
    }
    echo "Starting password encryption\n";
    echo "* Generating random master key\n";
    $masterKey = \FusionDirectory\Core\SecretBox::generateSecretKey();
    echo "* Creating \"$fdSecretsFile\"\n";
    $secretsFile = new \SplFileObject($fdSecretsFile, 'w');
    $secretsFile->fwrite('RequestHeader set FDKEY '.base64_encode($masterKey)."\n");
    $this->setFileRights($fdSecretsFile, 0600, 'root', 'root');

    /* Move original fusiondirectory.conf out of the way and make it unreadable for the web user */
    echo "* Creating backup in \"$fdConfigFile.orig\"\n";
    if (copy($fdConfigFile, $fdConfigFile.'.orig') === FALSE) {
      throw new \Exception('Unable to copy '.$fdConfigFile.' to '.$fdConfigFile.'.orig');
    }
    $this->setFileRights($fdConfigFile.'.orig', 0600, 'root', 'root');

    echo "* Loading \"$fdConfigFile\"\n";
    $xml = new \SimpleXMLElement($fdConfigFile, 0, TRUE);
    foreach ($xml->main->location as $loc) {
      $ref = $loc->referral[0];
      echo '* Encrypting FusionDirectory password for "'.$ref['adminDn'].'"'."\n";
      $ref['adminPassword'] = \FusionDirectory\Core\SecretBox::encrypt($ref['adminPassword'], $masterKey);
    }

    echo '* Saving modified "'.$fdConfigFile.'"'."\n";
    if ($xml->asXML($fdConfigFile) === FALSE) {
      throw new \Exception("Cannot write modified $fdConfigFile - aborted");
    }
    echo "OK\n\n";

    echo "
  Please adapt your http fusiondirectory declaration to include the newly
  created $fdSecretsFile.

  Example:

  # Include FusionDirectory to your web service
  Alias /fusiondirectory {$this->vars['fd_home']}/html

  <Directory {$this->vars['fd_home']}/html>
    # Include the secrects file
    include $fdSecretsFile
  </Directory>


  Please reload your httpd configuration after you've modified anything.\n";
  }

  /**
   * Show LDAP passwords from fusiondirectory.conf
   */
  protected function cmdShowPasswords (): void
  {
    $locations = $this->loadFusionDirectoryConfigurationFile();

    foreach ($locations as $key => $location) {
      printf("Location \"%s\":\n", $key);
      printf(" %-15s%s\n", 'URI',           $location['uri']);
      printf(" %-15s%s\n", 'Base',          $location['base']);
      printf(" %-15s%s\n", 'Bind DN',       $location['bind_dn']);
      printf(" %-15s%s\n", 'Bind password', $location['bind_pwd']);
      printf(" %-15s%s\n", 'TLS',           ($location['tls'] ? 'on' : 'off'));
    }
  }

  /**
   * Install all the FD's plugins from a directory
   * @param array<string> $paths
   */
  protected function cmdInstallPlugins (array $paths): void
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
        echo 'Installing plugin '.$pluginPath->getBasename()."\n";
      } else {
        continue;
      }

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

    /* Finally update FusionDirectory's class.cache and locales */
    echo 'Updating class.cache'."\n";
    $this->cmdUpdateCache();
    echo 'Updating locales'."\n";
    $this->cmdUpdateLocales();
  }

  /**
   * Update locale files
   */
  protected function cmdUpdateLocales (): void
  {
    $localeDir      = $this->vars['fd_home'].'/'.$this->vars['locale_dir'];
    $localeCacheDir = $this->vars['fd_cache'].'/'.$this->vars['locale_cache_dir'];

    if ($this->verbose()) {
      printf('Searching for locale files in %s'."\n", $localeDir);
    }
    /* Recursive iterator on the directory */
    $Directory  = new \RecursiveDirectoryIterator($localeDir, \FilesystemIterator::KEY_AS_PATHNAME | \FilesystemIterator::CURRENT_AS_PATHNAME);
    /* Flatten the iterator to iterate directly on all files in the tree */
    $Iterator   = new \RecursiveIteratorIterator($Directory);
    /* Filter by regex */
    $Regex      = new \RegexIterator($Iterator, '/^.+\/fusiondirectory.po$/i');

    $allFiles = [];
    foreach ($Regex as $file) {
      /* if the file's directory is ???/<language>/fusiondirectory.po */
      if (preg_match('|^.*/(\w+)/fusiondirectory.po$|', $file, $m)) {
        /* Push the file's path in the language (fr/en/es/it...) array */
        $allFiles[$m[1]][] = $file;
      }
    }

    foreach ($allFiles as $lang => $files) {
      /* Directory wich will contain the .mo file for this language */
      $langCacheDir = $localeCacheDir.'/'.$lang.'/LC_MESSAGES';

      if (!file_exists($langCacheDir)) {
        if (mkdir($langCacheDir, 0777, TRUE) === FALSE) {
          throw new \Exception('Can\'t create "'.$langCacheDir.'"');
        }
      }

      if ($this->verbose()) {
        printf('Compiling locales for language %s…'."\n", $lang);
      }

      $poFiles = implode(' ', array_map('escapeshellarg', $files));

      /* Merge .po files */
      $command = 'msgcat --use-first '.$poFiles.'>'.$langCacheDir.'/fusiondirectory.po';
      if ($this->verbose()) {
        echo '$ '.$command."\n";
      }
      passthru($command, $returnCode);
      if ($returnCode !== 0) {
        throw new \Exception('Unable to merge .po files for '.$lang.' with msgcat, is it installed?');
      }

      /* Compile .po files in .mo files */
      $command = 'msgfmt -o '.escapeshellarg($langCacheDir.'/fusiondirectory.mo').' '.escapeshellarg($langCacheDir.'/fusiondirectory.po');
      if ($this->verbose()) {
        echo '$ '.$command."\n";
      }
      passthru($command, $returnCode);
      if ($returnCode !== 0) {
        throw new \Exception('Unable to compile .mo files with msgfmt, is it installed?');
      }

      if (unlink($langCacheDir.'/fusiondirectory.po') === FALSE) {
        echo 'Warning: Failed to delete '.$langCacheDir.'/fusiondirectory.po'."\n";
      }
    }
  }
}
