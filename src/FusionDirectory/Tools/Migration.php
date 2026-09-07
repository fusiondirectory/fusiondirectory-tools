<?php
/*
  This code is part of FusionDirectory (https://www.fusiondirectory.org/)

  Copyright (C) 2020-2026 FusionDirectory

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

    $this->options = array_merge(
      $this->getVarOptions(),
      [
        'migrate-interfaces' => [
          'help'    => 'Migrating your systems from FD < 1.4',
          'command' => 'cmdMigrateInterfaces',
        ],
        'remove-supann-root' => [
          'help'    => 'Remove SupAnn root establishment from FD < 1.4',
          'command' => 'cmdRemoveSupannRoot',
        ],
        'migrate-users'      => [
          'help'    => 'Migrating your users',
          'command' => 'cmdMigrateUsers',
        ],
        'migrate-supannobjects'      => [
          'help'    => 'Migrating your Supann Objects',
          'command' => 'cmdMigrateSupannObjects',
        ],
        'migrate-consent'           => [
          'help'    => 'Migrating your Supann Consent Objects',
          'command' => 'cmdMigrateConsent',
        ],
        'migrate-supann-labels'  => [
          'help'    => 'Migrate supann label attributes to generic fdSupannLabel',
          'command' => 'cmdMigrateSupannLabelAttributes',
        ],
        'check-ids'          => [
          'help'    => 'Checking for duplicated uid or gid numbers',
          'command' => 'cmdCheckIds',
        ],
        'list-deprecated'    => [
          'help'    => 'List deprecated attributes and objectclasses',
          'command' => 'cmdListDeprecated',
        ],
        'check-deprecated'   => [
          'help'    => 'List LDAP entries using deprecated attributes or objectclasses',
          'command' => 'cmdCheckDeprecated',
        ],
        'ldif-deprecated'    => [
          'help'    => 'Print an LDIF removing deprecated attributes',
          'command' => 'cmdLdifDeprecated',
        ],
        'migrate-snapshot-base' => [
          'help'    => 'Migrate fdSnapshotBase from full DN to relative OU',
          'command' => 'cmdMigrateSnapshotBase',
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
      $this->cmdSetVar($this->getopt['set-var']);
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
    $this->configFilePath  = $this->vars['fd_config_dir'] . '/' . $this->vars['config_file'];
    $this->secretsFilePath = $this->vars['fd_config_dir'] . '/' . $this->vars['secrets_file'];

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
      printf('Searching for several objects with objectClass %s using the same value of %s' . "\n", $objectClass, $attribute);
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
      ['attributeTypes', 'objectClasses'],
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
    } catch (Exception | SodiumException $e) {
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
             '(' . ($at['DESC'] ?? '') . ')', $at['OID']
      );
    }

    echo "\nDeprecated objectClasses:\n";
    foreach ($objectclasses as $objectclass) {
      $oc = Ldap\Schema::parseDefinition($objectclass);
      printf(" %-30s\t%-60s\t- %s\n",
        ($oc['NAME'] ?? ''),
             '(' . ($oc['DESC'] ?? '') . ')', $oc['OID']
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

    $filterAttrs = '(|' . implode('', array_map(
        function ($attribute) {
          $at = Ldap\Schema::parseDefinition($attribute);
          return '(' . $at['NAME'] . '=*)';
        },
        $attributes
      )) . ')';

    $list = $this->ldap->search($this->base, $filterAttrs, ['dn']);
    $list->assert();

    if ($list->count() > 0) {
      foreach ($list as $dn => $entry) {
        echo $dn . " contains an obsolete attribute\n";
      }
      $list->assertIterationWentFine();
    } else {
      echo "There are no entries in the LDAP using obsolete attributes\n";
    }

    $useobsoletes = 0;
    foreach ($objectclasses as $objectclass) {
      $oc   = Ldap\Schema::parseDefinition($objectclass);
      $list = $this->ldap->search(
        $this->base,
        '(objectClass=' . $oc['NAME'] . ')',
        ['dn']
      );
      $list->assert();

      if ($list->count() > 0) {
        $useobsoletes = 1;
        foreach ($list as $dn => $entry) {
          echo $dn . " uses the obsolete object class " . $oc['NAME'] . "\n";
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
      echo 'There is an outdated SupAnn establishement stored under root node:' . "\n";

      foreach ($list as $dn => $entry) {
        echo $dn . "\n";
      }

      echo 'You should remove this entry and check the "Root establishment" checkbox' . "\n";
      echo ' in FusionDirectory to save it in the root node instead.' . "\n";

      if ($this->askYnQuestion('Remove this entry?')) {
        foreach ($list as $dn => $entry) {
          try {
            $result = $this->ldap->delete($dn);
            $result->assert();
            echo 'Deleted entry "' . $dn . '"' . "\n";
          } catch (Exception $e) {
            echo 'Failed to delete entry "' . $dn . '": ' . $e->getMessage() . "\n";
          }
        }
      }
    } else {
      echo 'There is no outdated SupAnn establishement stored under root node.' . "\n";
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
    } catch (Exception | SodiumException $e) {
    }

    if ($this->verbose()) {
      printf('Searching for user objects missing objectClass inetOrgPerson' . "\n");
    }
    $list = $this->ldap->search(
      $this->base,
      '(&' .
      '(|' .
      '(objectClass=posixAccount)' .
      '(objectClass=person)' .
      '(objectClass=OpenLDAPperson)' .
      ')' .
      '(!(objectClass=ipHost))' .
      '(!(objectClass=inetOrgPerson))' .
      '(uid=*)' .
      ')',
      ['objectClass']
    );
    $list->assert();

    if ($list->count() > 0) {
      echo 'The following users are missing objectClasses:' . "\n";

      foreach ($list as $dn => $entry) {
        echo $dn . "\n";
      }

      if ($this->askYnQuestion('Add the inetOrgPerson objectClass to all these entries?')) {
        foreach ($list as $dn => $entry) {
          try {
            $result = $this->ldap->mod_add($dn, ['objectClass' => array_values(array_diff(['person', 'organizationalPerson', 'inetOrgPerson'], $entry['objectClass']))]);
            $result->assert();
          } catch (Exception $e) {
            echo 'Failed to modify entry "' . $dn . '": ' . $e->getMessage() . "\n";
          }
        }
      }
    }
  }

  /**
   * Migrate SupannObjects
   * @throws Exception
   */
  protected function cmdMigrateSupannObjects (): void
  {
    try {
      $this->readFusionDirectoryConfigurationFileAndConnectToLdap();
    } catch (Exception | SodiumException $e) {
    }

    if ($this->verbose()) {
      printf('Searching for SupannObjects to be migrate' . "\n");
    }

    try {
      $list = $this->ldap->search(
        'cn=config,ou=fusiondirectory,' . $this->base,
        '(&' .
        '(|' .
        '(fdSupannRessourceLabels=*)' .
        '(fdSupannRessourceSubStates=*)' .
        '(fdSupannRessourceSubStatesLabels=*)' .
        '(fdSupannCiviliteValues=*)' .
        '(fdMainPopulationCodeConf=*)' .
        '(fdLocalPopulationCodeConf=*)' .
        ')' .
        ')',
        ['fdSupannRessourceLabels', 'fdSupannRessourceSubStates', 'fdSupannRessourceSubStatesLabels', 'fdSupannCiviliteValues', 'fdMainPopulationCodeConf', 'fdLocalPopulationCodeConf']
      );
      $list->assert();
      echo 'SupannObjects entries found in configuration' . "\n";

      try {
        $ou        = 'ou=supannobjects';
        $ouName    = 'supannobjects';
        echo 'Create ou=supannobjects branch' . "\n";
        $branchAdd = $this->ldap->add(
          $ou . ',' . $this->base,
          [
            'ou'          => $ouName,
            'objectClass' => 'organizationalUnit',
          ]
        );
        $branchAdd->assert();
      } catch (Exception $e) {
        echo "Exception: " . $e->getMessage() . PHP_EOL;
      }

      try {
        $ou        = 'ou=ressources,ou=supannobjects';
        $ouName    = 'ressources';
        echo 'Create ou=ressources,ou=supannobjects branch' . "\n";
        $branchAdd = $this->ldap->add(
          $ou . ',' . $this->base,
          [
            'ou'          => $ouName,
            'objectClass' => 'organizationalUnit',
          ]
        );
        $branchAdd->assert();
      } catch (Exception $e) {
        echo "Exception: " . $e->getMessage() . PHP_EOL;
      }

      try {
        $ou        = 'ou=states,ou=supannobjects';
        $ouName    = 'states';
        echo 'Create ou=states,ou=supannobjects branch' . "\n";
        $branchAdd = $this->ldap->add(
          $ou . ',' . $this->base,
          [
            'ou'          => $ouName,
            'objectClass' => 'organizationalUnit',
          ]
        );
        $branchAdd->assert();
      } catch (Exception $e) {
        echo "Exception: " . $e->getMessage() . PHP_EOL;
      }

      try {
        $ou        = 'ou=substates,ou=supannobjects';
        $ouName    = 'substates';
        echo 'Create ou=substates,ou=supannobjects branch' . "\n";
        $branchAdd = $this->ldap->add(
          $ou . ',' . $this->base,
          [
            'ou'          => $ouName,
            'objectClass' => 'organizationalUnit',
          ]
        );
        $branchAdd->assert();
      } catch (Exception $e) {
        echo "Exception: " . $e->getMessage() . PHP_EOL;
      }

      try {
        $ou        = 'ou=populationcodes,ou=supannobjects';
        $ouName    = 'populationcodes';
        echo 'Create ou=populationcodes,ou=supannobjects branch' . "\n";
        $branchAdd = $this->ldap->add(
          $ou . ',' . $this->base,
          [
            'ou'          => $ouName,
            'objectClass' => 'organizationalUnit',
          ]
        );
        $branchAdd->assert();
      } catch (Exception $e) {
        echo "Exception: " . $e->getMessage() . PHP_EOL;
      }

      try {
        $ou        = 'ou=civilite,ou=supannobjects';
        $ouName    = 'civilite';
        echo 'Create ou=civilite,ou=supannobjects branch' . "\n";
        $branchAdd = $this->ldap->add(
          $ou . ',' . $this->base,
          [
            'ou'          => $ouName,
            'objectClass' => 'organizationalUnit',
          ]
        );
        $branchAdd->assert();
      } catch (Exception $e) {
        echo "Exception: " . $e->getMessage() . PHP_EOL;
      }

      // Track processed population codes to avoid duplicates between defaults and config
      $processedPopulationCodes = [];

      // Add default population codes (from old setBasicMainCodes())
      $defaultPopulationCodes = [
        '{SUPANN}P', '{SUPANN}PX', '{SUPANN}PXE', '{SUPANN}PXL', '{SUPANN}PXR', '{SUPANN}PXSP', '{SUPANN}PXU',
        '{SUPANN}R',
        '{SUPANN}RG', '{SUPANN}RGI', '{SUPANN}RGIE', '{SUPANN}RGIS', '{SUPANN}RGN',
        '{SUPANN}RGNC', '{SUPANN}RGNCC', '{SUPANN}RGNCD', '{SUPANN}RGNE', '{SUPANN}RGNF',
        '{SUPANN}RGNFA', '{SUPANN}RGNFC', '{SUPANN}RGNFD', '{SUPANN}RGNS', '{SUPANN}RGNSP',
        '{SUPANN}RGP', '{SUPANN}RGPE', '{SUPANN}RGPET', '{SUPANN}RGPF', '{SUPANN}RGPFT', '{SUPANN}RGPST',
        '{SUPANN}RHTC', '{SUPANN}RHTCE', '{SUPANN}RHJCF', '{SUPANN}RHJSG', '{SUPANN}RHLE',
        '{SUPANN}RHLS', '{SUPANN}RHMF', '{SUPANN}RHTSO',
        '{SUPANN}TER',
      ];

      foreach ($defaultPopulationCodes as $code) {
        $processedPopulationCodes[$code] = TRUE;

        $dn    = 'fdSupannPopulationCodeName=' . $code . ',ou=populationcodes,ou=supannobjects,' . $this->base;
        $attrs = [
          'objectClass'                => 'fdSupannPopulationCode',
          'fdSupannPopulationCodeName' => $code,
          'fdSupannLabel'              => $code,
        ];

        echo 'Adding default population code ' . $dn . "\n";
        try {
          $result = $this->ldap->add($dn, $attrs);
          $result->assert();
        } catch (Exception $e) {
          echo 'Failed to add default population code "' . $code . '": ' . $e->getMessage() . "\n";
        }
      }

      // Add default supannCivilite
      $mainCivilite = [
        "Mme" => "Mme",
        "M."  => "M.",
      ];

      foreach ($mainCivilite as $civilite => $label) {
        $dn    = 'fdSupannCiviliteName=' . $civilite .',ou=civilite,ou=supannobjects,' . $this->base;
        echo 'Adding civilite ' . $dn . "\n";
        $attrs = [
          'objectClass'            => 'fdSupannCivilite',
          'fdSupannCiviliteName'      => $civilite,
          'fdSupannLabel' => $label,
        ];
        try {
          $result = $this->ldap->add($dn, $attrs);
          $result->assert();
        } catch (Exception $e) {
          echo 'Failed to add default civilite "' . $dn . '": ' . $e->getMessage() . "\n";
        }
      }

      // Add COMPTE and MAIL ressource
      $mainRessources = [
        "COMPTE" => "Compte",
        "MAIL"   => "Mail"
      ];

      foreach ($mainRessources as $resource => $label) {
        $dn    = 'fdSupannRessourceName=' . $resource .',ou=ressources,ou=supannobjects,' . $this->base;
        echo 'Adding ressource ' . $dn . "\n";
        $attrs = [
          'objectClass'            => 'fdSupannRessource',
          'fdSupannRessourceName'  => $resource,
          'fdSupannLabel' => $label,
        ];
        try {
          $result = $this->ldap->add($dn, $attrs);
          $result->assert();
        } catch (Exception $e) {
          echo 'Failed to add default ressources "' . $dn . '": ' . $e->getMessage() . "\n";
        }
        }

      // Add default states
      $mainStates = [
        "A" => "Active",
        "I" => "Inactive",
        "S" => "Suspendu"
      ];

      foreach ($mainStates as $state => $label) {
        $dn    = 'fdSupannStateName=' . $state .',ou=states,ou=supannobjects,' . $this->base;
        echo 'Adding states ' . $dn . "\n";
        $attrs = [
          'objectClass'            => 'fdSupannRessourceState',
          'fdSupannStateName'      => $state,
          'fdSupannLabel' => $label,
        ];
        try {
          $result = $this->ldap->add($dn, $attrs);
          $result->assert();
        } catch (Exception $e) {
          echo 'Failed to add default states "' . $dn . '": ' . $e->getMessage() . "\n";
        }
      }

      // Label array for matching substate and label
      $substateLabels = [
        "SupannAnticipe"            => "Anticipé",
        "SupannActif"               => "Active",
        "SupannSursis"              => "Sursis",
        "SupannPrecree"             => "Pré-créé",
        "SupannCree"                => "Créé",
        "SupannExpire"              => "Expiré",
        "SupannInactif"             => "Inactif",
        "SupannSupprDonnees"        => "Suppression des données",
        "SupannSupprCompte"         => "Supression définitive",
        "SupannVerrouille"          => "Verrouillé",
        "SupannVerrouAdministratif" => "Verouillage administratif",
        "SupannVerrouTechnique"     => "Verouillage technique"
      ];

      $stateSubstateLink = [
        "A" => ["SupannAnticipe", "SupannActif", "SupannSursis"],
        "I" => [
          "SupannPrecree", "SupannCree", "SupannExpire",
          "SupannInactif", "SupannSupprDonnees", "SupannSupprCompte",
          "SupannVerrouille", "SupannVerrouAdministratif", "SupannVerrouTechnique"
        ],
        "S" => ["SupannVerrouille", "SupannVerrouAdministratif", "SupannVerrouTechnique"]
      ];

      // Complete the substateLabels with fdSupannRessourceSubStatesLabels
      if ($list->count() > 0) {
        echo 'Complete substateLabels with fdSupannRessourceSubStatesLabels' . "\n";
        foreach ($list as $dn => $entries) {
          foreach ($entries as $key => $values) {
            foreach ($values as $i => $entry) {
              if ($key == 'fdSupannRessourceSubStatesLabels') {
                $substate = explode(':', $entry)[0];
                $label    = explode(':', $entry)[1];

                // Add $substate => $label
                echo 'Add substate "' . $substate . '" => "' . $label . '" to substateLabels' . "\n";
                $substateLabels += [ $substate => $label ];
              }
            }
          }
        }
      } else {
        // No entry in configuration but still try to add the default substate
        foreach ($substateLabels as $substate => $substateLabel) {
          $dn    = 'fdSupannSubStateName=' . $substate .',ou=substates,ou=supannobjects,' . $this->base;
          echo 'Adding substate ' . $dn . "\n";
          $attrs = [
            'objectClass'            => 'fdSupannRessourceSubState',
            'fdSupannSubStateName'   => $substate,
            'fdSupannLabel'          => $substateLabel,
          ];
          try {
            $result = $this->ldap->add($dn, $attrs);
            $result->assert();
          } catch (Exception $e) {
            echo 'Failed to add default substates "' . $dn . '": ' . $e->getMessage() . "\n";
          }
        }

        foreach ($stateSubstateLink as $state => $subStateArray) {
          foreach ($subStateArray as $substate) {
            // Add substate to the correct state
            $dnState = 'fdSupannStateName=' . $state .',ou=states,ou=supannobjects,' . $this->base;
            echo 'Link substate ' . $substate . ' for state ' . $state . PHP_EOL;
            try {
              $result = $this->ldap->mod_add($dnState, [
                "fdSupannSubStateList" => 'fdSupannSubStateName=' . $substate .',ou=substates,ou=supannobjects,' . $this->base
              ]);
              $result->assert();
            } catch (Exception $e) {
              echo 'Failed to add link or default substates "' . $dn . '": ' . $e->getMessage() . "\n";
            }
          }
        }
      }

      if ($list->count() > 0) {
        if ($this->askYnQuestion('Do you want to migrate the SupannObjects?')) {
          foreach ($list as $dn => $entries) {
            foreach ($entries as $key => $values) {
              foreach ($values as $i => $entry) {
                try {
                  if ($key == 'fdSupannRessourceSubStates') {
                    $state    = explode(':', $entry)[1];
                    $substate = explode(':', $entry)[2];

                    // Get the defined label if it exists
                    echo 'Search substate label for "' . $substate . '"' . "\n";
                    if (isset($substateLabels[$substate])) {
                      $substateLabel = $substateLabels[$substate];
                      echo 'Label for "' . $substate . '" = "' . $substateLabel . '"' . "\n";
                    } else {
                      $substateLabel = $substate;
                      echo 'Label for "' . $substate . '" = "' . $substateLabel . '"' . "\n";
                    }

                    $dn    = 'fdSupannSubStateName=' . $substate .',ou=substates,ou=supannobjects,' . $this->base;
                    echo 'Adding substate ' . $dn . "\n";
                    $attrs = [
                      'objectClass'            => 'fdSupannRessourceSubState',
                      'fdSupannSubStateName'   => $substate,
                      'fdSupannLabel' => $substateLabel,
                    ];

                    $result = $this->ldap->add($dn, $attrs);
                    $result->assert();

                    // Add substate to the correct state
                    $dnState = 'fdSupannStateName=' . $state .',ou=states,ou=supannobjects,' . $this->base;
                    echo 'Link substate ' . $dn . ' to state ' . $dnState . "\n";
                    $result = $this->ldap->mod_add($dnState, [
                      "fdSupannSubStateList" => $dn
                    ]);
                    $result->assert();
                  } else if ($key == 'fdSupannRessourceLabels') {
                    $resource = explode(':', $entry)[0];
                    $label    = explode(':', $entry)[1];

                    $dn    = 'fdSupannRessourceName=' . $resource .',ou=ressources,ou=supannobjects,' . $this->base;
                    $attrs = [
                      'objectClass'            => 'fdSupannRessource',
                      'fdSupannRessourceName'  => $resource,
                      'fdSupannLabel' => $label,
                    ];
                    echo 'Adding ressource ' . $dn . "\n";
                    $result = $this->ldap->add($dn, $attrs);
                    $result->assert();
                  } else if ($key == 'fdSupannCiviliteValues') {
                    $name  = $entry;
                    $label = $entry;

                    $dn    = 'fdSupannCiviliteName=' . $name .',ou=ressources,ou=civilite,' . $this->base;
                    $attrs = [
                      'objectClass'            => 'fdSupannCivilite',
                      'fdSupannCiviliteName'  => $name,
                      'fdSupannLabel' => $label,
                    ];
                    echo 'Adding civilite ' . $dn . "\n";
                    $result = $this->ldap->add($dn, $attrs);
                    $result->assert();
                  } else if ($key == 'fdMainPopulationCodeConf' || $key == 'fdLocalPopulationCodeConf') {
                    $name  = $entry;
                    $label = $entry;

                    if (isset($processedPopulationCodes[$name])) {
                      continue;
                    }
                    $processedPopulationCodes[$name] = TRUE;

                    $dn    = 'fdSupannPopulationCodeName=' . $name .',ou=populationcodes,ou=supannobjects,' . $this->base;
                    $attrs = [
                      'objectClass'                => 'fdSupannPopulationCode',
                      'fdSupannPopulationCodeName' => $name,
                      'fdSupannLabel'              => $label,
                    ];
                    echo 'Adding population code ' . $dn . "\n";
                    $result = $this->ldap->add($dn, $attrs);
                    $result->assert();
                  }
                } catch (Exception $e) {
                  echo 'Failed to add entry "' . $entry . '": ' . $e->getMessage() . "\n";
                }
              }
            }
            try {
              echo 'Delete supannObjects from configuration' . "\n";
              $result = $this->ldap->mod_del('cn=config,ou=fusiondirectory,' . $this->base, $entries);
              $result->assert();
              echo 'Add supannObjects RDN to configuration' . "\n";
              $result = $this->ldap->mod_add('cn=config,ou=fusiondirectory,' . $this->base, [
                "fdSupannObjectsRDN"           => "ou=supannobjects",
                "fdSupannRessourceRDN"         => "ou=ressources,ou=supannobjects",
                "fdSupannStateRDN"             => "ou=states,ou=supannobjects",
                "fdSupannSubStateRDN"          => "ou=substates,ou=supannobjects",
                "fdSupannPopulationCodeRDN"    => "ou=populationcodes,ou=supannobjects"
              ]);
              $result->assert();
            } catch (Exception $e) {
              echo 'Failed to delete the SupannObjects entries: ' . $e->getMessage() . "\n";
            }
          }
        }
      }
    } catch (Exception $e) {
      echo 'No fdSupannRessourceLabels, fdSupannRessourceSubStates, fdSupannRessourceSubStatesLabels, fdSupannCiviliteValues, fdMainPopulationCodeConf or fdLocalPopulationCodeConf attributes found in configuration: '
         . $e->getMessage() . "\n";
    }
  }

  /**
   * Migrate Supann Consent Objects from config to LDAP entries
   * @throws Exception
   */
  protected function cmdMigrateConsent (): void
  {
    try {
      $this->readFusionDirectoryConfigurationFileAndConnectToLdap();
    } catch (Exception | SodiumException $e) {
      echo $e->getMessage();
      return;
    }

    if ($this->verbose()) {
      printf('Searching for Supann Consent Objects to migrate' . "\n");
    }

    try {
      $list = $this->ldap->search(
        'cn=config,ou=fusiondirectory,' . $this->base,
        '(&' .
        '(|' .
        '(fdSupannConsentementObjects=*)' .
        '(fdSupannConsentementTypes=*)' .
        ')' .
        ')',
        ['fdSupannConsentementObjects', 'fdSupannConsentementTypes']
      );
      $list->assert();
      echo 'Supann Consent entries found in configuration' . "\n";

      $ou     = 'ou=consent,ou=supannobjects';
      $ouName = 'consent';
      echo 'Create ou=consent,ou=supannobjects branch' . "\n";
      $branchAdd = $this->ldap->add(
        $ou . ',' . $this->base,
        [
          'ou'          => $ouName,
          'objectClass' => 'organizationalUnit',
        ]
      );
      $branchAdd->assert();

      if ($list->count() > 0) {
        if ($this->askYnQuestion('Do you want to migrate the Supann Consent Objects?')) {
          foreach ($list as $dn => $entries) {
            // Migrate consent objects
            if (isset($entries['fdSupannConsentementObjects'])) {
              foreach ($entries['fdSupannConsentementObjects'] as $i => $entry) {
                if ($i === 'count') {
                  continue;
                }
                try {
                  list($object, $label) = explode(';', $entry, 2);
                  $dn    = 'fdSupannConsentObjectName=' . $object . ',ou=consent,ou=supannobjects,' . $this->base;
                  $attrs = [
                    'objectClass'               => 'fdSupannConsentObject',
                    'fdSupannConsentObjectName'  => $object,
                    'fdSupannLabel' => $label,
                  ];
                  echo 'Adding consent object ' . $dn . "\n";
                  $result = $this->ldap->add($dn, $attrs);
                  $result->assert();
                } catch (Exception $e) {
                  echo 'Failed to add consent object "' . $entry . '": ' . $e->getMessage() . "\n";
                }
              }
            }

            // Migrate consent types
            if (isset($entries['fdSupannConsentementTypes'])) {
              foreach ($entries['fdSupannConsentementTypes'] as $i => $entry) {
                if ($i === 'count') {
                  continue;
                }
                try {
                  list($type, $label) = explode(';', $entry, 2);
                  $dn    = 'fdSupannConsentTypeName=' . $type . ',ou=consent,ou=supannobjects,' . $this->base;
                  $attrs = [
                    'objectClass'              => 'fdSupannConsentType',
                    'fdSupannConsentTypeName'  => $type,
                    'fdSupannLabel' => $label,
                  ];
                  echo 'Adding consent type ' . $dn . "\n";
                  $result = $this->ldap->add($dn, $attrs);
                  $result->assert();
                } catch (Exception $e) {
                  echo 'Failed to add consent type "' . $entry . '": ' . $e->getMessage() . "\n";
                }
              }
            }

            try {
              echo 'Delete consent from configuration' . "\n";
              $result = $this->ldap->mod_del('cn=config,ou=fusiondirectory,' . $this->base, [
                'fdSupannConsentementObjects' => [],
                'fdSupannConsentementTypes'   => [],
              ]);
              $result->assert();

              echo 'Add consent RDN to configuration' . "\n";
              $result = $this->ldap->mod_add('cn=config,ou=fusiondirectory,' . $this->base, [
                "fdSupannConsentRDN" => "ou=consent,ou=supannobjects",
              ]);
              $result->assert();
            } catch (Exception $e) {
              echo 'Failed to update configuration: ' . $e->getMessage() . "\n";
            }
          }
        }
      }
    } catch (Exception $e) {
      echo 'No fdSupannConsentementObjects or fdSupannConsentementTypes attributes found in configuration: '
         . $e->getMessage() . "\n";
    }
  }

  /**
   * Migrate supann label attributes from fdSupannRessourceLabel/fdSupannCiviliteLabel to generic fdSupannLabel
   * @throws Exception
   */
  protected function cmdMigrateSupannLabelAttributes (): void
  {
    try {
      $this->readFusionDirectoryConfigurationFileAndConnectToLdap();
    } catch (Exception | SodiumException $e) {
      echo $e->getMessage();
      return;
    }

    $filter = '(|(fdSupannRessourceLabel=*)(fdSupannCiviliteLabel=*)(fdSupannConsentTypeLabel=*)(fdSupannConsentObjectLabel=*))';
    $ldap = $this->ldap->search($this->base, $filter,
        ['dn', 'fdSupannRessourceLabel', 'fdSupannCiviliteLabel', 'fdSupannConsentTypeLabel', 'fdSupannConsentObjectLabel']);
    $ldap->assert();

    $count = 0;
    foreach ($ldap as $dn => $attrs) {
      $oldLabel = $attrs['fdSupannRessourceLabel'][0]
                ?? $attrs['fdSupannCiviliteLabel'][0]
                ?? $attrs['fdSupannConsentTypeLabel'][0]
                ?? $attrs['fdSupannConsentObjectLabel'][0]
                ?? NULL;
      if ($oldLabel === NULL) {
        continue;
      }

      echo "Migrating $dn: setting fdSupannLabel='$oldLabel'\n";

      try {
        $result = $this->ldap->mod_add($dn, ['fdSupannLabel' => $oldLabel]);
        $result->assert();

        if (!empty($attrs['fdSupannRessourceLabel'])) {
          $result = $this->ldap->mod_del($dn, ['fdSupannRessourceLabel' => []]);
          $result->assert();
        }
        if (!empty($attrs['fdSupannCiviliteLabel'])) {
          $result = $this->ldap->mod_del($dn, ['fdSupannCiviliteLabel' => []]);
          $result->assert();
        }
        if (!empty($attrs['fdSupannConsentTypeLabel'])) {
          $result = $this->ldap->mod_del($dn, ['fdSupannConsentTypeLabel' => []]);
          $result->assert();
        }
        if (!empty($attrs['fdSupannConsentObjectLabel'])) {
          $result = $this->ldap->mod_del($dn, ['fdSupannConsentObjectLabel' => []]);
          $result->assert();
        }

        $count++;
      } catch (Exception $e) {
        echo "Failed to migrate $dn: " . $e->getMessage() . "\n";
      }
    }

    echo "Migrated $count entries to fdSupannLabel\n";
  }

  /**
   * Migrate interfaces from FD<1.4 to FD>=1.4
   * @throws Exception
   */
  protected function cmdMigrateInterfaces (): void
  {
    try {
      $this->readFusionDirectoryConfigurationFileAndConnectToLdap();
    } catch (Exception | SodiumException $e) {
      echo $e->getMessage();
    }

    $entriesToMigrate = [];
    $entriesToIgnore  = [];

    $systemOCs = ['fdWorkstation', 'fdServer', 'fdTerminal', 'fdPrinter', 'fdPhone', 'fdMobilePhone', 'device'];

    $list = $this->ldap->search(
      $this->base, '(&(|(objectClass=' . implode(')(objectClass=', $systemOCs) . '))(|(ipHostNumber=*)(macAddress=*)))'
    );
    $list->assert();

    foreach ($list as $dn => $entry) {
      $list2 = $this->ldap->search($dn, '(objectClass=fdNetworkInterface)', [], 'one');
      $list2->assert();
      if ($list2->count() == 0) {
        $macs = $entry['macAddress'] ?? [];
        if (count($macs) > 1) {
          $entriesToIgnore[$dn] = $entry;
          continue;
        }
        $entriesToMigrate[$dn] = $entry;
      }
    }

    if (count($entriesToMigrate) > 0) {
      echo 'The following systems are missing an interface node and can be migrated automatically:' . "\n";
      foreach ($entriesToMigrate as $dn => $entry) {
        $macs = $entry['macAddress'] ?? [];
        $ips  = $entry['ipHostNumber'];
        echo $dn;
        if (count($macs) > 0) {
          echo ' with MAC ' . implode(', ', $macs);
        } else {
          echo ' with no MAC';
        }
        if (count($ips) > 0) {
          echo ' and IP ' . implode(', ', $ips) . "\n";
        } else {
          echo ' and no IP' . "\n";
        }
      }
      echo "\n";

      if ($this->askYnQuestion('Migrate these systems by adding an interface node')) {
        $interface_cn = $this->askUserInput('Please enter the name for interfaces created by this migration', 'eth0');
        $count        = 0;
        foreach ($entriesToMigrate as $dn => $entry) {
          $macs      = $entry['macAddress'] ?? [];
          $ips       = $entry['ipHostNumber'];
          $interface = [
            'cn'          => $interface_cn,
            'objectClass' => 'fdNetworkInterface',
          ];

          if (count($macs) > 0) {
            $interface['macAddress'] = $macs;
          }

          if (count($ips) > 0) {
            $interface['ipHostNumber'] = $ips;
          }

          $interface_add = $this->ldap->add("cn=$interface_cn," . $dn, $interface);
          $interface_add->assert();
          $count++;
        }
        echo $count . " entries migrated\n";
      }
    }

    if (count($entriesToIgnore) > 0) {
      echo 'The following systems are missing interfaces nodes but cannot be migrated because they have several MAC addresses:' . "\n";
      foreach ($entriesToIgnore as $dn => $entry) {
        $macs = $entry['macAddress'];
        $ips  = $entry['ipHostNumber'];
        echo $dn;
        if (count($macs) > 0) {
          echo ' with MAC ' . implode(', ', $macs);
        } else {
          echo ' with no MAC';
        }
        if (count($ips) > 0) {
          echo ' and IP ' . implode(', ', $ips) . "\n";
        } else {
          echo ' and no IP' . "\n";
        }
      }
      echo "\n";
      echo 'Please edit them by hand in FusionDirectory to add interfaces' . "\n";
    }

    if ((count($entriesToMigrate) == 0) && (count($entriesToIgnore) == 0)) {
      echo "\n" . 'No systems are missing interfaces, nothing to do' . "\n";
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

    $filterAttrs = '(|' . implode('', array_map(
        function ($attribute) {
          $at = Ldap\Schema::parseDefinition($attribute);
          return '(' . $at['NAME'] . '=*)';
        },
        $attributes
      )) . ')';

    $list = $this->ldap->search($this->base, $filterAttrs, ['*']);
    $list->assert();

    if ($list->count() > 0) {
      foreach ($list as $dn => $entry) {
        echo 'dn:' . $dn . "\n";
        echo 'changetype:modify' . "\n";
        foreach ($attributes as $attribute) {
          $at = Ldap\Schema::parseDefinition($attribute);
          if (isset($entry[$at['NAME']])) {
            echo 'delete:' . $at['NAME'] . "\n-\n";
          }
        }
        echo "\n";
      }
    } else {
      echo '# There are no entries in the LDAP using obsolete attributes' . "\n";
    }

    $filterClasses = '(|' . implode('', array_map(
        function ($class) {
          $oc = Ldap\Schema::parseDefinition($class);
          return '(objectClass=' . $oc['NAME'] . ')';
        },
        $classes
      )) . ')';

    $list = $this->ldap->search($this->base, $filterClasses, ['dn']);
    $list->assert();

    if ($list->count() > 0) {
      echo "# WARNING: There are entries in the LDAP using obsolete classes, you need to edit them manually\n";
    } else {
      echo "# There are no entries in the LDAP using obsolete classes\n";
    }
  }

  /**
   * Migrate fdSnapshotBase from full DN to relative OU
   * @throws Exception|\SodiumException
   */
  protected function cmdMigrateSnapshotBase (): void
  {
    try {
      $this->readFusionDirectoryConfigurationFileAndConnectToLdap();
    } catch (Exception | \SodiumException $e) {
      echo $e->getMessage();
      return;
    }

    $configdn = 'cn=config,ou=fusiondirectory,' . $this->base;

    try {
      $list = $this->ldap->search(
        $configdn,
        '(fdSnapshotBase=*)',
        ['fdSnapshotBase']
      );
      $list->assert();
    } catch (Exception $e) {
      echo 'No fdSnapshotBase found in configuration: ' . $e->getMessage() . "\n";
      return;
    }

    if ($list->count() === 0) {
      echo "No fdSnapshotBase attribute found in configuration, nothing to migrate.\n";
      return;
    }

    foreach ($list as $dn => $entry) {
      $oldValue = $entry['fdSnapshotBase'][0] ?? '';
      if ($oldValue === '') {
        echo "fdSnapshotBase is empty, nothing to migrate.\n";
        continue;
      }

      // Already relative OU (no comma = relative)
      if (strpos($oldValue, ',') === FALSE) {
        echo "fdSnapshotBase is already in relative format: '$oldValue', nothing to migrate.\n";
        continue;
      }

      // Extract relative OU from full DN
      // e.g. "ou=snapshots,dc=test-fusiondirectory,dc=org" → "ou=snapshots"
      $relativeOU = explode(',', $oldValue)[0];

      echo "Migrating fdSnapshotBase from '$oldValue' to '$relativeOU'\n";

      if ($this->askYnQuestion('Do you want to migrate?')) {
        try {
          $result = $this->ldap->mod_replace($dn, ['fdSnapshotBase' => $relativeOU]);
          $result->assert();
          echo "fdSnapshotBase migrated successfully.\n";
        } catch (Exception $e) {
          echo 'Failed to migrate fdSnapshotBase: ' . $e->getMessage() . "\n";
        }
      } else {
        echo "Skipping migration.\n";
      }
    }
  }
}
