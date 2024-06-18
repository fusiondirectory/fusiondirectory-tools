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

use FusionDirectory\Ldap;
use FusionDirectory\Cli;
use FusionDirectory\Ldap\Exception;
use SodiumException;

/**
 * Tool to migrate data from one FusionDirectory version to the next, if need be
 */
class Migration extends Cli\LdapApplication
{
  public function __construct ()
  {
    parent::__construct();

    $this->options  = array_merge(
      $this->getVarOptions(),
      [
        'migrate-interfaces'    => [
          'help'        => 'Migrating your systems from FD < 1.4',
          'command'     => 'cmdMigrateInterfaces',
        ],
        'remove-supann-root'    => [
          'help'        => 'Remove SupAnn root establishment from FD < 1.4',
          'command'     => 'cmdRemoveSupannRoot',
        ],
        'migrate-users'    => [
          'help'        => 'Migrating your users',
          'command'     => 'cmdMigrateUsers',
        ],
        'check-ids'    => [
          'help'        => 'Checking for duplicated uid or gid numbers',
          'command'     => 'cmdCheckIds',
        ],
        'list-deprecated'    => [
          'help'        => 'List deprecated attributes and objectclasses',
          'command'     => 'cmdListDeprecated',
        ],
        'check-deprecated'    => [
          'help'        => 'List LDAP entries using deprecated attributes or objectclasses',
          'command'     => 'cmdCheckDeprecated',
        ],
        'ldif-deprecated'    => [
          'help'        => 'Print an LDIF removing deprecated attributes',
          'command'     => 'cmdLdifDeprecated',
        ],
      ],
      $this->options
    );
  }

  /**
   * Run the tool
   * @param array<string> $argv
   * @throws Exception|\Exception
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
   * Load locations information from FusionDirectory configuration file
   * @return array<array{tls: bool, uri: string, base: string, bind_dn: string, bind_pwd: string}> locations
   * @throws SodiumException
   */
  protected function loadFusionDirectoryConfigurationFile (): array
  {
    $this->configFilePath   = $this->vars['fd_config_dir'].'/'.$this->vars['config_file'];
    $this->secretsFilePath  = $this->vars['fd_config_dir'].'/'.$this->vars['secrets_file'];

    return parent::loadFusionDirectoryConfigurationFile();
  }

  /* Helpers */

  /**
   * Check if there are no duplicated values of $attribute for objects with class objectClass
   * @throws Exception
   */
  protected function checkIdNumbers (string $objectClass, string $attribute, string $type): void
  {
    if ($this->verbose()) {
      printf('Searching for several objects with objectClass %s using the same value of %s'."\n", $objectClass, $attribute);
    }
    $list = $this->ldap->search(
      $this->base,
      "(&(objectClass=$objectClass)($attribute=*))",
      [$attribute]
    );
    $list->assert();

    $tmp = [];

    foreach ($list as $dn => $entry) {
      if (!isset($tmp[$entry[$attribute][0]])) {
        $tmp[$entry[$attribute][0]] = [];
      }
      $tmp[$entry[$attribute][0]][] = $dn;
    }
    $list->assertIterationWentFine();

    $dups = 0;
    foreach ($tmp as $id => $dns) {
      if (count($dns) > 1) {
        $dups = 1;
        echo "The following $type use the same $attribute $id:\n";
        foreach ($dns as $dn) {
          echo "\t$dn\n";
        }
      }
    }
    if ($dups == 0) {
      echo "There are no duplicated ${attribute}s\n";
    }
  }

  /**
   * Get LDAP attributes which have been deprecated
   * @return array{0: array<int,string>, 1: array<int,string>}
   * @throws Exception
   */
  protected function getDeprecated (): array
  {
    $dse  = $this->ldap->getDSE(['subschemaSubentry']);
    $list = $this->ldap->search(
      $dse['subschemaSubentry'][0],
      '(objectClass=*)',
      ['attributeTypes','objectClasses'],
      'base'
    );
    $list->assert();
    $attributes = [];
    $classes    = [];
    foreach ($list as $schema) {
      foreach ($schema['attributeTypes'] as $attributeType) {
        if (strpos($attributeType, 'OBSOLETE') !== FALSE) {
          $attributes[] = $attributeType;
        }
      }
      foreach ($schema['objectClasses'] as $objectClass) {
        if (strpos($objectClass, 'OBSOLETE') !== FALSE) {
          $classes[] = $objectClass;
        }
      }
      $list->assertIterationWentFine();
    }

    return [$attributes, $classes];
  }

  /* Commands */

  /**
   * Check for duplication uid or gid numbers in the LDAP tree
   * @throws Exception
   */
  protected function cmdCheckIds (): void
  {
    try {
      $this->readFusionDirectoryConfigurationFileAndConnectToLdap();
    } catch (Exception|SodiumException $e) {
      echo $e->getMessage();
    }

    $this->checkIdNumbers('posixAccount', 'uidNumber', 'users');

    $this->checkIdNumbers('posixGroup', 'gidNumber', 'groups');
  }

  /**
   * List deprecated attributes and classes from schemas
   * @throws Exception
   * @throws SodiumException
   */
  protected function cmdListDeprecated (): void
  {
    $this->readFusionDirectoryConfigurationFileAndConnectToLdap();

    [$attributes, $objectclasses] = $this->getDeprecated();

    echo "Deprecated attributes:\n";
    foreach ($attributes as $attribute) {
      $at = Ldap\Schema::parseDefinition($attribute);
      printf(" %-30s\t%-60s\t- %s\n",
        ($at['NAME'] ?? ''),
        '('.($at['DESC'] ?? '').')', $at['OID']
      );
    }

    echo "\nDeprecated objectClasses:\n";
    foreach ($objectclasses as $objectclass) {
      $oc = Ldap\Schema::parseDefinition($objectclass);
      printf(" %-30s\t%-60s\t- %s\n",
        ($oc['NAME'] ?? ''),
        '('.($oc['DESC'] ?? '').')', $oc['OID']
      );
    }
  }

  /**
   * Check if there are entries using deprecated attributes or classes in the LDAP tree
   * @throws Exception
   * @throws SodiumException
   */
  protected function cmdCheckDeprecated (): void
  {
    $this->readFusionDirectoryConfigurationFileAndConnectToLdap();

    list($attributes, $objectclasses) = $this->getDeprecated();

    $filterAttrs = '(|'.implode('', array_map(
      function ($attribute)
      {
        $at = Ldap\Schema::parseDefinition($attribute);
        return '('.$at['NAME'].'=*)';
      },
      $attributes
    )).')';

    $list = $this->ldap->search($this->base, $filterAttrs, ['dn']);
    $list->assert();

    if ($list->count() > 0) {
      foreach ($list as $dn => $entry) {
        echo $dn." contains an obsolete attribute\n";
      }
      $list->assertIterationWentFine();
    } else {
      echo "There are no entries in the LDAP using obsolete attributes\n";
    }

    $useobsoletes = 0;
    foreach ($objectclasses as $objectclass) {
      $oc = Ldap\Schema::parseDefinition($objectclass);
      $list = $this->ldap->search(
        $this->base,
        '(objectClass='.$oc['NAME'].')',
        ['dn']
      );
      $list->assert();

      if ($list->count() > 0) {
        $useobsoletes = 1;
        foreach ($list as $dn => $entry) {
          echo $dn." uses the obsolete object class ".$oc['NAME']."\n";
        }
        $list->assertIterationWentFine();
      }
    }

    if (!$useobsoletes) {
      echo "There are no entries in the LDAP using obsolete classes\n";
    }
  }

  /**
   * Remove SupAnn root information from FD<1.4
   * @throws Exception
   * @throws SodiumException
   */
  protected function cmdRemoveSupannRoot (): void
  {
    $this->readFusionDirectoryConfigurationFileAndConnectToLdap();

    $list = $this->ldap->search($this->base, '(&(objectClass=supannOrg)(objectClass=eduOrg))', ['dn'], 'one');
    $list->assert();

    if ($list->count() > 0) {
      echo 'There is an outdated SupAnn establishement stored under root node:'."\n";

      foreach ($list as $dn => $entry) {
        echo $dn."\n";
      }

      echo 'You should remove this entry and check the "Root establishment" checkbox'."\n";
      echo ' in FusionDirectory to save it in the root node instead.'."\n";

      if ($this->askYnQuestion('Remove this entry?')) {
        foreach ($list as $dn => $entry) {
          try {
            $result = $this->ldap->delete($dn);
            $result->assert();
            echo 'Deleted entry "'.$dn.'"'."\n";
          } catch (Exception $e) {
            echo 'Failed to delete entry "'.$dn.'": '.$e->getMessage()."\n";
          }
        }
      }
    } else {
      echo 'There is no outdated SupAnn establishement stored under root node.'."\n";
    }
  }

  /**
   * Add object classes to people branch users
   * @throws Exception
   */
  protected function cmdMigrateUsers (): void
  {
    try {
      $this->readFusionDirectoryConfigurationFileAndConnectToLdap();
    } catch (Exception|SodiumException $e) {
    }

    if ($this->verbose()) {
      printf('Searching for user objects missing objectClass inetOrgPerson'."\n");
    }
    $list = $this->ldap->search(
      $this->base,
      '(&'.
        '(|'.
          '(objectClass=posixAccount)'.
          '(objectClass=person)'.
          '(objectClass=OpenLDAPperson)'.
        ')'.
        '(!(objectClass=ipHost))'.
        '(!(objectClass=inetOrgPerson))'.
        '(uid=*)'.
      ')',
      ['objectClass']
    );
    $list->assert();

    if ($list->count() > 0) {
      echo 'The following users are missing objectClasses:'."\n";

      foreach ($list as $dn => $entry) {
        echo $dn."\n";
      }

      if ($this->askYnQuestion('Add the inetOrgPerson objectClass to all these entries?')) {
        foreach ($list as $dn => $entry) {
          try {
            $result = $this->ldap->mod_add($dn, ['objectClass' => array_values(array_diff(['person','organizationalPerson','inetOrgPerson'], $entry['objectClass']))]);
            $result->assert();
          } catch (Exception $e) {
            echo 'Failed to modify entry "'.$dn.'": '.$e->getMessage()."\n";
          }
        }
      }
    }
  }

  /**
   * Migrate interfaces from FD<1.4 to FD>=1.4
   * @throws Exception
   */
  protected function cmdMigrateInterfaces (): void
  {
    try {
      $this->readFusionDirectoryConfigurationFileAndConnectToLdap();
    } catch (Exception|SodiumException $e) {
      echo $e->getMessage();
    }

    $entriesToMigrate = [];
    $entriesToIgnore  = [];

    $systemOCs = ['fdWorkstation', 'fdServer', 'fdTerminal', 'fdPrinter', 'fdPhone', 'fdMobilePhone', 'device'];

    $list = $this->ldap->search(
      $this->base, '(&(|(objectClass='.implode(')(objectClass=', $systemOCs).'))(|(ipHostNumber=*)(macAddress=*)))'
    );
    $list->assert();

    foreach ($list as $dn => $entry) {
      $list2 = $this->ldap->search($dn, '(objectClass=fdNetworkInterface)', [], 'one');
      $list2->assert();
      if ($list2->count() == 0) {
        $macs = $entry['macAddress'];
        if (count($macs) > 1) {
          $entriesToIgnore[$dn] = $entry;
          continue;
        }
        $entriesToMigrate[$dn] = $entry;
      }
    }

    if (count($entriesToMigrate) > 0) {
      echo 'The following systems are missing an interface node and can be migrated automatically:'."\n";
      foreach ($entriesToMigrate as $dn => $entry) {
        $macs = $entry['macAddress'];
        $ips  = $entry['ipHostNumber'];
        echo $dn;
        if (count($macs) > 0) {
          echo ' with MAC '.implode(', ', $macs);
        } else {
          echo ' with no MAC';
        }
        if (count($ips) > 0) {
          echo ' and IP '.implode(', ', $ips)."\n";
        } else {
          echo ' and no IP'."\n";
        }
      }
      echo "\n";

      if ($this->askYnQuestion('Migrate these systems by adding an interface node')) {
        $interface_cn = $this->askUserInput('Please enter the name for interfaces created by this migration', 'eth0');
        $count = 0;
        foreach ($entriesToMigrate as $dn => $entry) {
          $macs = $entry['macAddress'];
          $ips  = $entry['ipHostNumber'];
          $interface = [
            'cn'            => $interface_cn,
            'objectClass'   => 'fdNetworkInterface',
          ];

          if (count($macs) > 0) {
            $interface['macAddress'] = $macs;
          }

          if (count($ips) > 0) {
            $interface['ipHostNumber'] = $ips;
          }

          $interface_add = $this->ldap->add("cn=$interface_cn,".$dn, $interface);
          $interface_add->assert();
          $count++;
        }
        echo $count." entries migrated\n";
      }
    }

    if (count($entriesToIgnore) > 0) {
      echo 'The following systems are missing interfaces nodes but cannot be migrated because they have several MAC addresses:'."\n";
      foreach ($entriesToIgnore as $dn => $entry) {
        $macs = $entry['macAddress'];
        $ips  = $entry['ipHostNumber'];
        echo $dn;
        if (count($macs) > 0) {
          echo ' with MAC '.implode(', ', $macs);
        } else {
          echo ' with no MAC';
        }
        if (count($ips) > 0) {
          echo ' and IP '.implode(', ', $ips)."\n";
        } else {
          echo ' and no IP'."\n";
        }
      }
      echo "\n";
      echo 'Please edit them by hand in FusionDirectory to add interfaces'."\n";
    }

    if ((count($entriesToMigrate) == 0) && (count($entriesToIgnore) == 0)) {
      echo "\n".'No systems are missing interfaces, nothing to do'."\n";
    }
  }

  /**
   * Print a LDIF file removing attributes which have been deprecated
   * @throws Exception
   * @throws SodiumException
   */
  protected function cmdLdifDeprecated (): void
  {
    $this->readFusionDirectoryConfigurationFileAndConnectToLdap();

    [$attributes, $classes] = $this->getDeprecated();

    $filterAttrs = '(|'.implode('', array_map(
      function ($attribute)
      {
        $at = Ldap\Schema::parseDefinition($attribute);
        return '('.$at['NAME'].'=*)';
      },
      $attributes
    )).')';

    $list = $this->ldap->search($this->base, $filterAttrs, ['*']);
    $list->assert();

    if ($list->count() > 0) {
      foreach ($list as $dn => $entry) {
        echo 'dn:'.$dn."\n";
        echo 'changetype:modify'."\n";
        foreach ($attributes as $attribute) {
          $at = Ldap\Schema::parseDefinition($attribute);
          if (isset($entry[$at['NAME']])) {
            echo 'delete:'.$at['NAME']."\n-\n";
          }
        }
        echo "\n";
      }
    } else {
      echo '# There are no entries in the LDAP using obsolete attributes'."\n";
    }

    $filterClasses = '(|'.implode('', array_map(
      function ($class)
      {
        $oc = Ldap\Schema::parseDefinition($class);
        return '(objectClass='.$oc['NAME'].')';
      },
      $classes
    )).')';

    $list = $this->ldap->search($this->base, $filterClasses, ['dn']);
    $list->assert();

    if ($list->count() > 0) {
      echo "# WARNING: There are entries in the LDAP using obsolete classes, you need to edit them manually\n";
    } else {
      echo "# There are no entries in the LDAP using obsolete classes\n";
    }
  }
}
