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

class InsertSchema extends Cli\Application
{
  /**
   * @var Ldap\Link
   */
  protected $ldap;

  /**
   * @var ?string
   */
  protected $db;

  /**
   * @var string
   */
  protected $defaultSchemaDir = '/etc/ldap/schema';

  public function __construct ()
  {
    parent::__construct();

    $this->options  = [
      'list-schemas'  => [
        'help'        => 'List schemas',
        'command'     => 'listSchemas',
      ],
      'insert-schema:'  => [
        'help'        => 'Insert schema',
        'command'     => 'insertSchema',
      ],
      'empty-schema:'  => [
        'help'        => 'Empty schema',
        'command'     => 'emptySchema',
      ],
      'remove-schema:'  => [
        'help'        => 'Remove schema (OpenLDAP > 2.5)',
        'command'     => 'removeSchema',
      ],
      'replace-schema:'  => [
        'help'        => 'Replace schema',
        'command'     => 'replaceSchema',
      ],
      'show-schema:' => [
        'help'        => 'Show a schema definitions',
        'command'     => 'showSchema',
      ],
      'ldapuri:'  => [
        'help'        => 'URI to connect to, defaults to ldapi:///',
      ],
      'binddn:'  => [
        'help'        => 'DN to bind with, default to none (external)',
      ],
      'bindpwd:'  => [
        'help'        => 'Password to bind with, defaults to none',
      ],
      'saslmech:'  => [
        'help'        => 'SASL mech, defaults to EXTERNAL',
      ],
      'saslrealm:'  => [
        'help'        => 'SASL realm',
      ],
      'saslauthcid:'  => [
        'help'        => 'SASL authcid',
      ],
      'saslauthzid:'  => [
        'help'        => 'SASL authzid',
      ],
      'simplebind'  => [
        'help'        => 'Disable SASL, use simple bind',
      ],
      'verbose'       => [
        'help'        => 'Verbose output',
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

    $this->runCommands();
  }

  /*
   * Helpers methods.
   */
  static protected function showIterationErrors (Ldap\Result $list, string $indent = ''): void
  {
    try {
      $list->assertIterationWentFine();
    } catch (Ldap\Exception $e) {
      printf('%sError: %s (%s)'."\n", $indent, $e->getMessage(), $e->getCode());
    }
  }

  static protected function formatLdapDate (string $ldapValue): string
  {
    try {
      return Ldap\GeneralizedTime::fromString($ldapValue)->format('Y-m-d');
    } catch (Ldap\Exception $e) {
      return $ldapValue;
    }
  }

  /* Commands */

  /**
   * @throws Ldap\Exception
   */
  protected function searchForSchemas (string $schemaSearch = NULL): Ldap\Result
  {
    if ($schemaSearch !== NULL) {
      $filter = '(&(objectClass=olcSchemaConfig)(cn={*}'.ldap_escape($schemaSearch, '', LDAP_ESCAPE_FILTER).'))';
    } else {
      $filter = '(objectClass=olcSchemaConfig)';
    }
    $list = $this->ldap->search(
      'cn=schema,cn=config',
      $filter,
      ['*','createTimestamp','modifyTimestamp'],
      'one'
    );
    $list->assert();
    return $list;
  }

  protected function listSchemas (string $schemaSearch = NULL): void
  {
    try {
      $list = $this->searchForSchemas($schemaSearch);
    } catch (Ldap\Exception $e) {
      echo 'Search for schemas failed: '.$e->getMessage()."\n";
      return;
    }
    if (($list->count() > 0) && ($schemaSearch === NULL)) {
      echo "Schemas:\n";
    }
    foreach ($list as $schema) {
      printf(' %-30s'."\t", preg_replace('/^\{\d+\}/', '', $schema['cn'][0]).':');
      if (isset($schema['createTimestamp'])) {
        printf('Added: %s', static::formatLdapDate($schema['createTimestamp'][0]));
      }
      if (empty($schema['olcAttributeTypes']) && empty($schema['olcObjectClasses'])) {
        echo ' - Empty';
      } else {
        echo sprintf(' - Attributes:%3d, ObjectClasses:%3d', count($schema['olcAttributeTypes'] ?? []), count($schema['olcObjectClasses'] ?? []));
      }
      if (isset($schema['modifyTimestamp']) && ($schema['createTimestamp'][0] != $schema['modifyTimestamp'][0])) {
        printf(' (Modified: %s)', static::formatLdapDate($schema['modifyTimestamp'][0]));
      }
      echo "\n";
      if ($schemaSearch !== NULL) {
        if (isset($schema['olcObjectIdentifier'])) {
          echo "\n".'# Object identifiers:'."\n";
          foreach ($schema['olcObjectIdentifier'] as $olcObjectIdentifier) {
            echo 'objectidentifier ';
            $this->dumpDefinition($olcObjectIdentifier);
          }
        }
        if (isset($schema['olcLdapSyntaxes'])) {
          echo "\n".'# LDAP syntaxes:'."\n";
          foreach ($schema['olcLdapSyntaxes'] as $olcLdapSyntax) {
            echo 'ldapsyntax ';
            $this->dumpDefinition($olcLdapSyntax);
          }
        }
        if (isset($schema['olcAttributeTypes'])) {
          echo "\n".'# Attributes:'."\n";
          foreach ($schema['olcAttributeTypes'] as $olcAttributeType) {
            echo 'attributetype ';
            $this->dumpDefinition($olcAttributeType);
          }
        }
        if (isset($schema['olcObjectClasses'])) {
          echo "\n".'# Object classes:'."\n";
          foreach ($schema['olcObjectClasses'] as $olcObjectClass) {
            echo 'objectclass ';
            $this->dumpDefinition($olcObjectClass);
          }
        }
        if (isset($schema['olcDitContentRules'])) {
          echo "\n".'# DIT content rules:'."\n";
          foreach ($schema['olcDitContentRules'] as $olcDitContentRule) {
            echo 'ditcontentrule ';
            $this->dumpDefinition($olcDitContentRule);
          }
        }
      }
    }
    static::showIterationErrors($list);
  }

  /**
   * @param array<string> $args
   */
  protected function showSchema (array $args): void
  {
    foreach ($args as $arg) {
      $this->listSchemas($arg);
    }
  }

  /**
   * @param array<string> $args
   */
  protected function emptySchema (array $args): void
  {
    foreach ($args as $schema) {
      try {
        [$schemaPath, $schemaName, $list] = $this->gatherSchemaInformation($schema);
      } catch (Ldap\Exception $e) {
        echo 'Search for schema failed: '.$e->getMessage()."\n";
        continue;
      }
      if (($list->count() <= 0)) {
        echo 'Failed: Found no schema named '.$schemaName."\n";
        continue;
      }
      if (($list->count() > 1)) {
        echo 'Failed: Several schemas found with name '.$schemaName."\n";
        continue;
      }
      $list->rewind();
      $schemaDn     = $list->key();
      $schemaAttrs  = $list->current();
      $attrs        = [];
      foreach (Ldap\Schema::ATTRIBUTES as $attr) {
        if (count($schemaAttrs[$attr] ?? []) > 0) {
          $attrs[$attr] = [];
        }
      }
      if (count($attrs) <= 0) {
        echo 'Schema '.$schemaName.' is already empty'."\n";
      } else {
        try {
          $result = $this->ldap->mod_del($schemaDn, $attrs);
          $result->assert();
          echo 'Successfuly emptied '.$schemaName."\n";
        } catch (Ldap\Exception $e) {
          printf('Emying schema "%s" failed: %s'."\n", $schemaDn, $e->getMessage());
          continue;
        }
      }
    }
  }

  /**
   * @return array{string, string, Ldap\Result}
   * @throws \Exception
   */
  protected function gatherSchemaInformation (string $path): array
  {
    if (preg_match('|^/|', $path) !== 1) {
      $path = $this->defaultSchemaDir.'/'.$path;
    }
    if (preg_match('/\.(schema|ldif)$/i', $path) !== 1) {
      $path .= '.schema';
    }
    if (preg_match('@^/.+/([^/]+).(schema|ldif)$@', $path, $m) === 1) {
      $name = $m[1];
    } else {
      throw new \Exception('Preg match failed on path '.$path);
    }
    $list   = $this->searchForSchemas($name);
    $count  = $list->count();
    if ($count < 0) {
      throw new \Exception('Count returned '.$count);
    }
    return [$path, $name, $list];
  }

  /**
   * @param array<string> $args
   */
  protected function insertSchema (array $args): void
  {
    foreach ($args as $arg) {
      try {
        [$schemaPath, $schemaName, $list] = $this->gatherSchemaInformation($arg);
        if ($list->count() > 0) {
          throw new \Exception('Schema '.$schemaName.' already inserted');
        }
        $schema = Ldap\Schema::parseSchemaFile($schemaName, $schemaPath);
        $dn     = $schema->computeDn();
        $result = $this->ldap->add($dn, $schema->toAddArray());
        $result->assert();
        printf('Schema %s inserted as %s'."\n", $schemaPath, $dn);
      } catch (\Exception $e) {
        echo 'Insertion failed: '.$e->getMessage()."\n";
        echo 'Operation aborted'."\n";
      }
    }
  }

  /**
   * @param array<string> $args
   */
  protected function replaceSchema (array $args): void
  {
    foreach ($args as $arg) {
      try {
        [$schemaPath, $schemaName, $list] = $this->gatherSchemaInformation($arg);
        if ($list->count() === 0) {
          throw new \Exception('Schema '.$schemaName.' not found');
        }
        $schema = Ldap\Schema::parseSchemaFile($schemaName, $schemaPath);
        $list->rewind();
        $dn     = $list->key();
        $result = $this->ldap->mod_replace($dn, $schema->toModReplaceArray($list->current()));
        $list->assertIterationWentFine();
        $result->assert();
        printf('Schema %s replaced by %s'."\n", $dn, $schemaPath);
      } catch (\Exception $e) {
        echo 'Replace failed: '.$e->getMessage()."\n";
        echo 'Operation aborted'."\n";
      }
    }
  }

  /**
   * @param array<string> $args
   */
  protected function removeSchema (array $args): void
  {
    foreach ($args as $schema) {
      try {
        [$schemaPath, $schemaName, $list] = $this->gatherSchemaInformation($schema);
      } catch (Ldap\Exception $e) {
        echo 'Search for schema failed: '.$e->getMessage()."\n";
        continue;
      }
      if (($list->count() <= 0)) {
        echo 'Failed: Found no schema named '.$schemaName."\n";
        continue;
      }
      if (($list->count() > 1)) {
        echo 'Failed: Several schemas found with name '.$schemaName."\n";
        continue;
      }
      $list->rewind();
      $schemaDn = $list->key();
      try {
        $result = $this->ldap->delete($schemaDn);
        $result->assert();
        echo 'Successfuly removed '.$schemaName."\n";
      } catch (Ldap\Exception $e) {
        printf('Removing schema "%s" failed: %s'."\n", $schemaDn, $e->getMessage());
        continue;
      }
    }
  }

  /**
   * @param array<string> $args
   */
  protected function showAttribute (array $args): void
  {
    foreach ($args as $attribute) {
      try {
        $list = $this->ldap->search(
          'cn=schema,cn=config',
          '(&'.
            '(objectClass=olcSchemaConfig)'.
            '(|'.
              '(olcAttributeTypes=*'.ldap_escape('NAME \''.$attribute.'\'', '', LDAP_ESCAPE_FILTER).'*)'.
              '(olcAttributeTypes=*'.ldap_escape('NAME ( \''.$attribute.'\'', '', LDAP_ESCAPE_FILTER).'*)'.
            ')'.
          ')',
          ['cn','olcAttributeTypes','olcObjectClasses','createTimestamp','modifyTimestamp'],
          'subtree'
        );
        $list->assert();
      } catch (Ldap\Exception $e) {
        printf('Search for Attribute %s failed: %s'."\n", $attribute, $e->getMessage());
        continue;
      }
      foreach ($list as $schema) {
        printf(' %-30s'."\t", preg_replace('/^\{\d+\}/', '', $schema['cn'][0]).':');
        if (isset($schema['createTimestamp'])) {
          printf('Added: %s', static::formatLdapDate($schema['createTimestamp'][0]));
        }
        if (empty($schema['olcAttributeTypes']) && empty($schema['olcObjectClasses'])) {
          echo ' - Empty';
        } else {
          echo sprintf(' - Attributes:%3d, ObjectClasses:%3d', count($schema['olcAttributeTypes'] ?? []), count($schema['olcObjectClasses'] ?? []));
        }
        if (isset($schema['modifyTimestamp']) && ($schema['createTimestamp'][0] != $schema['modifyTimestamp'][0])) {
          printf(' (Modified: %s)', static::formatLdapDate($schema['modifyTimestamp'][0]));
        }
        echo "\n";
        foreach ($schema['olcAttributeTypes'] as $olcAttributeType) {
          if (preg_match('/^(\{\d+\})?\(\s*([\d\.a-zA-Z:]+)\s+NAME[\s\(]+\''.$attribute.'\'/i', $olcAttributeType) === 1) {
            echo 'attributetype ';
            $this->dumpDefinition($olcAttributeType);
          }
        }
      }
      static::showIterationErrors($list);
    }
  }

  /**
   * @throws \FusionDirectory\Ldap\Exception
   */
  function debugInfo (Ldap\Link $ldap): void
  {
    global $getopt;

    var_dump($getopt);

    $list = $ldap->search('cn=config', '(objectClass=*)', ['objectClass'], 'one');
    $list->assert();
    foreach ($list as $k => $v) {
      echo "$k: ";
      echo json_encode($v)."\n";
    }
    $list->assertIterationWentFine();

    $ocs = $ldap->getObjectClasses();

    var_dump(count($ocs), $ocs['olcSchemaConfig']);

    $tree = [];
    foreach ($ocs as $oc => $infos) {
      if (isset($infos['SUP'])) {
        if (is_array($infos['SUP'])) {
          foreach ($infos['SUP'] as $SUP) {
            $tree[$SUP][] = $oc;
          }
        } elseif (is_string($infos['SUP'])) {
          $tree[$infos['SUP']][] = $oc;
        }
      }
    }

    $this->printTree($tree, 'olcConfig');
  }

}
