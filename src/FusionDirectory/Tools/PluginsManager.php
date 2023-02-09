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

  public function __construct ()
  {
    parent::__construct();

    $this->options  = [
      'check-plugin-exist'  => [
        'help'        => 'Checking if plugins exists',
        'command'     => 'checkPluginExistence',
      ],
      'install-plugin'  => [
        'help'        => 'Installing FusionDirectory Plugins',
        'command'     => 'installPlugin',
      ],
      'only-register'  => [
        'help'        => 'Only register inside LDAP',
        'command'     => 'onlyRegister',
      ],
      'list-plugins'  => [
        'help'        => 'List installed FusionDirectory plugins',
        'command'     => 'listPlugins',
      ],
      'help'          => [
        'help'        => 'Show this help',
      ],
    ];
  
    $this->vars = [
      'fd_home'          => '/var/www/fusiondirectory',
      'fd_cache'         => '/var/cache/fusiondirectory',
      'fd_config_dir'    => '/etc/fusiondirectory',
      'fd_smarty_path'   => '/usr/share/php/smarty3/Smarty.class.php',
      'fd_spool_dir'     => '/var/spool/fusiondirectory',
      'ldap_conf'        => '/etc/ldap/ldap.conf',
      'config_file'      => 'fusiondirectory.conf',
      'secrets_file'     => 'fusiondirectory.secrets',
      'locale_dir'       => 'locale',
      'class_cache'      => 'class.cache',
      'locale_cache_dir' => 'locale',
      'tmp_dir'          => 'tmp',
      'fai_log_dir'      => 'fai',
      'template_dir'     => 'template'
    ];

    $this->pluginmanagementmapping = [
      'cn'                              => 'information:name',
      'description'                     => 'information:description',
      'fdPluginInfoVersion'             => 'information:version',
      'fdPluginInfoAuthors'             => 'information:authors',
      'fdPluginInfoStatus'              => 'information:status',
      'fdPluginInfoScreenshotUrl'       => 'information:screenshotUrl',
      'fdPluginInfoLogoUrl'             => 'information:logoUrl',
      'fdPluginInfoTags'                => 'information:tags',
      'fdPluginInfoLicence'             => 'information:license',
      'fdPluginInfoOrigin'              => 'information:origin',
      'fdPluginSupportProvider'         => 'support:provider',
      'fdPluginSupportHomeUrl'          => 'support:homeUrl',
      'fdPluginSupportTicketUrl'        => 'support:ticketUrl',
      'fdPluginSupportDiscussionUrl'    => 'support:discussionUrl',
      'fdPluginSupportDownloadUrl'      => 'support:downloadUrl',
      'fdPluginSupportSchemaUrl'        => 'support:schemaUrl',
      'fdPluginSupportContractUrl'      => 'support:contractUrl',
      'fdPluginReqFdVersion'            => 'requirement:fdVersion',
      'fdPluginReqPhpVersion'           => 'requirement:phpVersion',
      'fdPluginReqPlugins'              => 'requirement:plugins',
      'fdPluginContentPhpClass'         => 'content:phpClassList',
      'fdPluginContentLdapObject'       => 'content:ldapObjectList',
      'fdPluginContentLdapAttributes'   => 'content:ldapAttributeList',
      'fdPluginContentFileList'         => 'content:fileList',
    ];
    
    $this->configrdn       = "cn=config,ou=fusiondirectory";
    $this->configpluginrdn = "ou=plugins";
    $this->plugin_types    = ['addons', 'admin', 'personal'];
    $this->plugin_name     = NULL;
    $this->plugins_archive = NULL;

    $this->plugin_register_only = 0;
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

  function createBranch($ldap, $base, $ou) {
    preg_match('m/^ou=([^,]*),?$/', $ou) || die "Can’t create branch of unknown type $ou\n";
    $branchAdd = $ldap->add( "$ou,$base",
      'attr' => [
        'ou'  => $fake/*check:$1*/,
        'objectClass' =>  'organizationalUnit'
        ]
    );

    $branchAdd->code && die "! failed to add LDAP's $ou,$base branch: ".$branchAdd->error."\n";
  }

  function branchExists($ldap, $branch) {
    // search for branch
    $branchMesg = $ldap->search ('base' => $branch, 'filter' => '(objectClass=*)', 'scope' => 'base');
    if ($branchMesg->code == 32) {
      return 0;
    }
    $branchMesg->code && die $branchMesg->error;

    $entries = $branchMesg->entries;
    return (/*check*/isset(($entries[0])));
  }

  // function that add plugin record
  function addPluginRecord() {
    // initiate the LDAP connexion
    $hashLdapParam = getLdapConnexion();
    $pathfile=$fake/*check:$*/[0];
    $pluginInfo = loadFile($pathfile);

    // LDAP's connection's parameters
    $base = $hashLdapParam['base'];
    $ldap = $hashLdapParam['ldap'];

    if (!branchExists($ldap, "$configpluginrdn,ou=fusiondirectory,$base")) {
      createBranch($ldap, "ou=fusiondirectory,".$base, $configpluginrdn);
      print "Create plugin branch";
    }

    $obj=['objectClass' => ['top','fdPlugin']];

    foreach (array_keys($pluginmanagementmapping) as $k) {
      $section = preg_split('/:/', $pluginmanagementmapping[$k]);
      if (/*check*/isset($pluginInfo[$section[0]][$section[1]])){
        $obj[$k] = $pluginInfo[$section[0]][$section[1]];
      }
    }

    $dn = "cn=".$pluginInfo['information']['name'].",".$configpluginrdn.",ou=fusiondirectory,".$base;

    $options = $obj;

    if (branchExists($ldap, $dn)) {
      print "Plugin record exist : ".$dn."\nDeleting it !\n";
      deletePluginRecord($dn);
    }


    $mesg = $ldap->add( $dn, 'attr' => /*check:\*/$options );
    print "Create plugin record\n";
    if ($mesg->code) {
      print $dn.": ".$mesg->error." (".$mesg->code.")\n";
    }
  }

  // function that delete plugin record
  function deletePluginRecord() {
    // initiate the LDAP connexion
    $pluginDn=$fake/*check:$*/[0];
    $hashLdapParam = getLdapConnexion();

    // LDAP's connection's parameters
    $base = $hashLdapParam['base'];
    $ldap = $hashLdapParam['ldap'];

    if (!branchExists($ldap,$pluginDn)) {
      exit;
    }else{
      $mesg = $ldap->unset($pluginDn);

      if ($mesg->code) {
        print $pluginDn.": ".$mesg->error."\n";
      }
    }
  }

  // function that check if plugin is inserted ldap tree
  function checkPluginExistence() {

    // check if plugin is set on CLI
    $pluginName = $fake/*check:$*/[0];

    // initiate the LDAP connexion
    $hashLdapParam = getLdapConnexion();

    // LDAP's connection's parameters
    $base = $hashLdapParam['base'];
    $ldap = $hashLdapParam['ldap'];

    // Search for plugin
    $mesg = $ldap->search(
      'base' => "$configpluginrdn,ou=fusiondirectory,$base",
      'filter' => "(&(objectClass=fdPlugin)(cn=".$pluginName."))",
      'attrs' => ['cn','description']
    );
    $mesg->code && die $mesg->error;
    $entries = $mesg->entries;

  //  print($mesg->code."  ".$mesg->error." ".ref($mesg->code));
    if ($mesg->count == 1){
      print("Plugin ".$pluginName." is declared\n");
      return(1);
    }else{
      print("Plugin ".$pluginName." is NOT declared\n");
      return(0);
    }
  }

  function checkPluginNameIsSet() {
      if ($pluginName === ""){
        print "--plugin-name is not set as the first parameter. ".$fake/*check:$*/[0]."\n";
        exit(-1);
    }
  }

  function listPlugins() {
    // initiate the LDAP connexion
    $hashLdapParam = getLdapConnexion();

    // LDAP's connection's parameters
    $base = $hashLdapParam['base'];
    $ldap = $hashLdapParam['ldap'];

    $pluginattrs=['cn','description','fdPluginInfoAuthors','fdPluginInfoVersion','fdPluginSupportHomeUrl','fdPluginInfoStatus','fdPluginSupportProvider','fdPluginInfoOrigin'];

    // Search for DHCP configurations
    $mesg = $ldap->search(
      'base' => "$configpluginrdn,ou=fusiondirectory,$base",
      'filter' => "(objectClass=fdPlugin)",
      'attrs' => /*check:\*/$pluginattrs
    );
    $mesg->code && die $mesg->error;

    $entries = $mesg->entries;
    print "There are ".$mesg->count." Plugins configurations in the LDAP\n";

    foreach ($entries as $entry) {
      print " Plugin :".$entry->getValue('cn')."\n";
      foreach ($pluginattrs as $val) {
        $section = preg_split('/:/', $pluginmanagementmapping[$val]);
        $value="N/A";
        if (/*check*/isset(($entry->getValue($val)))){
            $value = $entry->getValue($val);
        }
        print "   - ".$section[1]."\t: ".$value."\n";
      }
    }
}
