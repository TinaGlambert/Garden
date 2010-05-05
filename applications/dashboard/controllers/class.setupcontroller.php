<?php if (!defined('APPLICATION')) exit();
/*
Copyright 2008, 2009 Vanilla Forums Inc.
This file is part of Garden.
Garden is free software: you can redistribute it and/or modify it under the terms of the GNU General Public License as published by the Free Software Foundation, either version 3 of the License, or (at your option) any later version.
Garden is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for more details.
You should have received a copy of the GNU General Public License along with Garden.  If not, see <http://www.gnu.org/licenses/>.
Contact Vanilla Forums Inc. at support [at] vanillaforums [dot] com
*/

/**
 * Dashboard Setup Controller
 */
class SetupController extends DashboardController {
   
   public $Uses = array('Form', 'Database');
   
	const UsernameError = 'Username can only contain letters, numbers, underscores, and must be between 3 and 20 characters long.';
	
   public function Initialize() {
      $this->Head = new HeadModule($this);
      $this->AddCssFile('setup.css');
   }
   
   /**
    * The summary of all settings available. The menu items displayed here are
    * collected from each application's application controller and all plugin's
    * definitions.
    */
   public function Index() {
      $this->ApplicationFolder = 'dashboard';
      $this->MasterView = 'setup';
      // Fatal error if Garden has already been installed.
      $Config = Gdn::Factory(Gdn::AliasConfig);
      
      $Installed = Gdn::Config('Garden.Installed') ? TRUE : FALSE;
      if ($Installed)
         trigger_error(ErrorMessage('Vanilla has already been installed.', 'SetupController', 'Index'));
      
      if (!$this->_CheckPrerequisites()) {
         $this->View = 'prerequisites';
      } else {
         $this->View = 'configure';
         $ApplicationManager = new Gdn_ApplicationManager();
         $AvailableApplications = $ApplicationManager->AvailableApplications();
         
         // Need to go through all of the setups for each application. Garden,
         if ($this->Configure() && $this->Form->IsPostBack()) {
            // Step through the available applications, enabling each of them
            $AppNames = array_keys($AvailableApplications);
            try {
               foreach ($AvailableApplications as $AppName => $AppInfo) {
                  if (strtolower($AppName) != 'dashboard') {
                     $Validation = new Gdn_Validation();
                     $ApplicationManager->RegisterPermissions($AppName, $Validation);
                     $ApplicationManager->EnableApplication($AppName, $Validation);
                  }
               }
            } catch (Exception $ex) {
               $this->Form->AddError(strip_tags($ex->getMessage()));
            }
            if ($this->Form->ErrorCount() == 0) {
               // Save a variable so that the application knows it has been installed.
               // Now that the application is installed, select a more user friendly error page.
               SaveToConfig(array(
                  'Garden.Installed' => TRUE,
                  'Garden.Errors.MasterView' => 'error.master.php'
               ));
               
               // Go to the dashboard
               Redirect('/settings');
            }
         }
      }
      $this->Render();
   }
   
   /**
    * Allows the configuration of basic setup information in Garden. This
    * should not be functional after the application has been set up.
    */
   public function Configure($RedirectUrl = '') {
      $Config = Gdn::Factory(Gdn::AliasConfig);
      
      // Create a model to save configuration settings
      $Validation = new Gdn_Validation();
      $ConfigurationModel = new Gdn_ConfigurationModel($Validation);
      $ConfigurationModel->SetField(array('Garden.Locale', 'Garden.Title', 'Garden.RewriteUrls', 'Garden.WebRoot', 'Garden.Cookie.Salt', 'Garden.Cookie.Domain', 'Database.Name', 'Database.Host', 'Database.User', 'Database.Password'));
      
      // Set the models on the forms.
      $this->Form->SetModel($ConfigurationModel);
      
      // Load the locales for the locale dropdown
      // $Locale = Gdn::Locale();
      // $AvailableLocales = $Locale->GetAvailableLocaleSources();
      // $this->LocaleData = array_combine($AvailableLocales, $AvailableLocales);
      
      // If seeing the form for the first time...
      if (!$this->Form->IsPostback()) {
         // Force the webroot using our best guesstimates
         $ConfigurationModel->Data['Database.Host'] = 'localhost';
         $this->Form->SetData($ConfigurationModel->Data);
      } else {         
         // Define some validation rules for the fields being saved
         $ConfigurationModel->Validation->ApplyRule('Database.Name', 'Required', 'You must specify the name of the database in which you want to set up Vanilla.');
			
         // Let's make some user-friendly custom errors for database problems
         $DatabaseHost = $this->Form->GetFormValue('Database.Host', '~~Invalid~~');
         $DatabaseName = $this->Form->GetFormValue('Database.Name', '~~Invalid~~');
         $DatabaseUser = $this->Form->GetFormValue('Database.User', '~~Invalid~~');
         $DatabasePassword = $this->Form->GetFormValue('Database.Password', '~~Invalid~~');
         $ConnectionString = GetConnectionString($DatabaseName, $DatabaseHost);
         try {
            $Connection = new PDO(
               $ConnectionString,
               $DatabaseUser,
               $DatabasePassword
            );
         } catch (PDOException $Exception) {
            switch ($Exception->getCode()) {
               case 1044:
                  $this->Form->AddError(T('The database user you specified does not have permission to access the database. The database reported: <code>%s</code>'), strip_tags($Exception->getMessage()));
                  break;
               case 1045:
                  $this->Form->AddError(T('Failed to connect to the database with the username and password you entered. Did you mistype them? The database reported: <code>%s</code>'), strip_tags($Exception->getMessage()));
                  break;
               case 1049:
                  $this->Form->AddError(T('It appears as though the database you specified does not exist yet. Have you created it yet? Did you mistype the name? The database reported: <code>%s</code>'), strip_tags($Exception->getMessage()));
                  break;
               case 2005:
                  $this->Form->AddError(T("Are you sure you've entered the correct database host name? Maybe you mistyped it? The database reported: <code>%s</code>"), strip_tags($Exception->getMessage()));
                  break;
               default:
                  $this->Form->AddError(sprintf(T('ValidateConnection'), strip_tags($Exception->getMessage())));
               break;
            }
         }
			
         $ConfigurationModel->Validation->ApplyRule('Garden.Title', 'Required');
         
         $ConfigurationFormValues = $this->Form->FormValues();
         if ($ConfigurationModel->Validate($ConfigurationFormValues) !== TRUE || $this->Form->ErrorCount() > 0) {
            // Apply the validation results to the form(s)
            $this->Form->SetValidationResults($ConfigurationModel->ValidationResults());
         } else {
            $Host = array_shift(explode(':',Gdn::Request()->RequestHost()));
            $Domain = Gdn::Request()->Domain();

            // Set up cookies now so that the user can be signed in.
            $ConfigurationFormValues['Garden.Cookie.Salt'] = RandomString(10);
            $ConfigurationFormValues['Garden.Cookie.Domain'] = strpos($Host, '.') === FALSE ? '' : $Host; // Don't assign the domain if it is a non .com domain as that will break cookies.
            $ConfigurationModel->Save($ConfigurationFormValues);
            
            // If changing locale, redefine locale sources:
            $NewLocale = 'en-CA'; // $this->Form->GetFormValue('Garden.Locale', FALSE);
            if ($NewLocale !== FALSE && Gdn::Config('Garden.Locale') != $NewLocale) {
               $ApplicationManager = new Gdn_ApplicationManager();
               $PluginManager = Gdn::Factory('PluginManager');
               $Locale = Gdn::Locale();
               $Locale->Set($NewLocale, $ApplicationManager->EnabledApplicationFolders(), $PluginManager->EnabledPluginFolders(), TRUE);
            }
            
            // Set the instantiated config object's db params and make the database use them (otherwise it will use the default values from conf/config-defaults.php).
            $Config->Set('Database.Host', $ConfigurationFormValues['Database.Host']);
            $Config->Set('Database.Name', $ConfigurationFormValues['Database.Name']);
            $Config->Set('Database.User', $ConfigurationFormValues['Database.User']);
            $Config->Set('Database.Password', $ConfigurationFormValues['Database.Password']);
            $Config->ClearSaveData();
            
            Gdn::FactoryInstall(Gdn::AliasDatabase, 'Gdn_Database', PATH_LIBRARY.DS.'database'.DS.'class.database.php', Gdn::FactorySingleton, array(Gdn::Config('Database')));
            
            // Install db structure & basic data.
            $Database = Gdn::Database();
            $Drop = FALSE; // Gdn::Config('Garden.Version') === FALSE ? TRUE : FALSE;
            $Explicit = FALSE;
            try {
               include(PATH_APPLICATIONS . DS . 'dashboard' . DS . 'settings' . DS . 'structure.php');
            } catch (Exception $ex) {
               $this->Form->AddError(strip_tags($ex->getMessage()));
            }
         
            if ($this->Form->ErrorCount() > 0)
               return FALSE;

            // Create the administrative user
            $UserModel = Gdn::UserModel();
            $UserModel->DefineSchema();
            $UserModel->Validation->ApplyRule('Name', 'Username', self::UsernameError);
            $UserModel->Validation->ApplyRule('Name', 'Required', T('You must specify an admin username.'));
            $UserModel->Validation->ApplyRule('Password', 'Required', T('You must specify an admin password.'));
            $UserModel->Validation->ApplyRule('Password', 'Match');
            
            if (!$UserModel->SaveAdminUser($ConfigurationFormValues)) {
               $this->Form->SetValidationResults($UserModel->ValidationResults());
            } else {
               // The user has been created successfully, so sign in now
               $Authenticator = Gdn::Authenticator();
               $AuthUserID = $Authenticator->Authenticate(array(
                  'Email' => $this->Form->GetValue('Email'),
                  'Password' => $this->Form->GetValue('Password'),
                  'RememberMe' => TRUE)
               );
            }
            
            if ($this->Form->ErrorCount() > 0)
               return FALSE;
            
            // Assign some extra settings to the configuration file if everything succeeded.
            $ApplicationInfo = array();
            include(CombinePaths(array(PATH_APPLICATIONS . DS . 'dashboard' . DS . 'settings' . DS . 'about.php')));
            
            // Detect rewrite abilities
            try {
               $Query = Gdn::Request()->Domain().Gdn::Request()->WebRoot()."entry";
               $Results = ProxyHead($Query);
               $CanRewrite = FALSE;
               if (in_array(ArrayValue('StatusCode',$Results,404), array(200,302)) && ArrayValue('X-Garden-Version',$Results,'None') != 'None') {
                  $CanRewrite = TRUE;
               }
            } catch (Exception $e) {
               // cURL and fsockopen arent supported... guess?
               $CanRewrite = (function_exists('apache_get_modules') && in_array('mod_rewrite', apache_get_modules())) ? TRUE : FALSE;
            }
      
            SaveToConfig(array(
               'Garden.Version' => ArrayValue('Version', GetValue('Dashboard', $ApplicationInfo, array()), 'Undefined'),
               'Garden.WebRoot' => Gdn_Url::WebRoot(),
               'Garden.RewriteUrls' => $CanRewrite,
               'Garden.Domain' => $Domain,
               'Garden.CanProcessImages' => function_exists('gd_info'),
               'EnabledPlugins.GettingStarted' => 'GettingStarted', // Make sure the getting started plugin is enabled
               'EnabledPlugins.HTMLPurifier' => 'HtmlPurifier' // Make sure html purifier is enabled so html has a default way of being safely parsed
            ));
         }
      }
      return $this->Form->ErrorCount() == 0 ? TRUE : FALSE;
   }
   
   private function _CheckPrerequisites() {
      // Make sure we are running at least PHP 5.1
      if (version_compare(phpversion(), ENVIRONMENT_PHP_VERSION) < 0)
         $this->Form->AddError(sprintf(T('You are running PHP version %1$s. Vanilla requires PHP %2$s or greater. You must upgrade PHP before you can continue.'), phpversion(), ENVIRONMENT_PHP_VERSION));

      // Make sure PDO is available
      if (!class_exists('PDO'))
         $this->Form->AddError(T('You must have the PDO module enabled in PHP in order for Vanilla to connect to your database.'));

      if (!defined('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY'))
         $this->Form->AddError(T('You must have the MySQL driver for PDO enabled in order for Vanilla to connect to your database.'));

      // Make sure that the correct filesystem permissions are in place
		$PermissionProblem = FALSE;
		$PermissionHelp = ' <p>Using your ftp client, or via command line, make sure that the following permissions are set for your vanilla installation:</p>
<pre>chmod -R 777 '.CombinePaths(array(PATH_ROOT, 'conf')).'
chmod -R 777 '.CombinePaths(array(PATH_ROOT, 'cache')).'
chmod -R 777 '.CombinePaths(array(PATH_ROOT, 'uploads')).'</pre>';
      
      // Make sure the config folder is writeable
      if (!is_readable(PATH_CONF) || !IsWritable(PATH_CONF)) {
         $this->Form->AddError(T('Your configuration folder does not have the correct permissions. PHP needs to be able to read and write to this folder.'));
			$PermissionProblem = TRUE;
      } else {
         $ConfigFile = PATH_CONF . DS . 'config.php';
         if (!file_exists($ConfigFile))
            file_put_contents($ConfigFile, '');
         
         // Make sure the config file is writeable
         if (!is_readable($ConfigFile) || !IsWritable($ConfigFile)) {
            $this->Form->AddError(sprintf(T('Your configuration file does not have the correct permissions. PHP needs to be able to read and write to this file: <code>%s</code>'), $ConfigFile));
				$PermissionProblem = TRUE;
         }
      }
      
      $UploadsFolder = PATH_ROOT . DS . 'uploads';
      if (!is_readable($UploadsFolder) || !IsWritable($UploadsFolder)) {
         $this->Form->AddError(sprintf(T('Your uploads folder does not have the correct permissions. PHP needs to be able to read and write to this folder: <code>%s</code>'), $UploadsFolder));
         $PermissionProblem = TRUE;
      }

      // Make sure the cache folder is writeable
      if (!is_readable(PATH_CACHE) || !IsWritable(PATH_CACHE)) {
         $this->Form->AddError(sprintf(T('Your cache folder does not have the correct permissions. PHP needs to be able to read and write to this folder and all the files within: <code>%s</code>'), PATH_CACHE));
         $PermissionProblem = TRUE;
      } else {
         if (!file_exists(PATH_CACHE.DS.'HtmlPurifier')) mkdir(PATH_CACHE.DS.'HtmlPurifier');
         if (!file_exists(PATH_CACHE.DS.'Smarty')) mkdir(PATH_CACHE.DS.'Smarty');
         if (!file_exists(PATH_CACHE.DS.'Smarty'.DS.'cache')) mkdir(PATH_CACHE.DS.'Smarty'.DS.'cache');
         if (!file_exists(PATH_CACHE.DS.'Smarty'.DS.'compile')) mkdir(PATH_CACHE.DS.'Smarty'.DS.'compile');
      }
		
		if ($PermissionProblem)
			$this->Form->AddError($PermissionHelp);
			
      return $this->Form->ErrorCount() == 0 ? TRUE : FALSE;
   }
   
    public function First() {
      // Start the session.
      Gdn::Session()->Start(Gdn::Authenticator());
   
      $this->Permission('Garden.First'); // This permission doesn't exist, so only users with Admin == '1' will succeed.
      
      // Enable all of the plugins.
      $PluginManager = Gdn::Factory('PluginManager');
      foreach($PluginManager->EnabledPlugins as $PluginName => $PluginFolder) {
         $PluginManager->EnablePlugin($PluginName, NULL, TRUE);
      }
      
      Redirect('/settings');
   }
}