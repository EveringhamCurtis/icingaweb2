<?php
// {{{ICINGA_LICENSE_HEADER}}}
// {{{ICINGA_LICENSE_HEADER}}}

namespace Icinga\Application;

use PDOException;
use Icinga\Form\Setup\ModulePage;
use Icinga\Form\Setup\WelcomePage;
use Icinga\Form\Setup\SummaryPage;
use Icinga\Form\Setup\DbResourcePage;
use Icinga\Form\Setup\PreferencesPage;
use Icinga\Form\Setup\AuthBackendPage;
use Icinga\Form\Setup\AdminAccountPage;
use Icinga\Form\Setup\LdapDiscoveryPage;
use Icinga\Form\Setup\LdapDiscoveryConfirmPage;
use Icinga\Form\Setup\LdapResourcePage;
use Icinga\Form\Setup\RequirementsPage;
use Icinga\Form\Setup\GeneralConfigPage;
use Icinga\Form\Setup\AuthenticationPage;
use Icinga\Form\Setup\DatabaseCreationPage;
use Icinga\Installation\DatabaseStep;
use Icinga\Installation\GeneralConfigStep;
use Icinga\Installation\ResourceStep;
use Icinga\Installation\AuthenticationStep;
use Icinga\Web\Form;
use Icinga\Web\Wizard;
use Icinga\Web\Request;
use Icinga\Web\Setup\DbTool;
use Icinga\Web\Setup\Installer;
use Icinga\Web\Setup\MakeDirStep;
use Icinga\Web\Setup\SetupWizard;
use Icinga\Web\Setup\Requirements;

/**
 * Icinga Web 2 Setup Wizard
 */
class WebSetup extends Wizard implements SetupWizard
{
    /**
     * The privileges required by Icinga Web 2 to setup the database
     *
     * @var array
     */
    protected $databaseSetupPrivileges = array(
        'CREATE',
        'ALTER',
        'REFERENCES',
        'CREATE USER', // MySQL
        'CREATEROLE' // PostgreSQL
    );

    /**
     * The privileges required by Icinga Web 2 to operate the database
     *
     * @var array
     */
    protected $databaseUsagePrivileges = array(
        'SELECT',
        'INSERT',
        'UPDATE',
        'DELETE',
        'EXECUTE',
        'TEMPORARY', // PostgreSql
        'CREATE TEMPORARY TABLES' // MySQL
    );

    /**
     * The database tables operated by Icinga Web 2
     *
     * @var array
     */
    protected $databaseTables = array(
        'icingaweb_group',
        'icingaweb_group_membership',
        'icingaweb_user',
        'icingaweb_user_preference'
    );

    /**
     * @see Wizard::init()
     */
    protected function init()
    {
        $this->addPage(new WelcomePage());
        $this->addPage(new RequirementsPage());
        $this->addPage(new AuthenticationPage());
        $this->addPage(new PreferencesPage());
        $this->addPage(new DbResourcePage());
        $this->addPage(new LdapDiscoveryPage());
        $this->addPage(new LdapDiscoveryConfirmPage());
        $this->addPage(new LdapResourcePage());
        $this->addPage(new AuthBackendPage());
        $this->addPage(new AdminAccountPage());
        $this->addPage(new GeneralConfigPage());
        $this->addPage(new DatabaseCreationPage());
        $this->addPage(new ModulePage());
        $this->addPage(new SummaryPage());
    }

    /**
     * @see Wizard::setupPage()
     */
    public function setupPage(Form $page, Request $request)
    {
        if ($page->getName() === 'setup_requirements') {
            $page->setRequirements($this->getRequirements());
        } elseif ($page->getName() === 'setup_preferences_type') {
            $authData = $this->getPageData('setup_authentication_type');
            if ($authData['type'] === 'db') {
                $page->create()->showDatabaseNote();
            }
        } elseif ($page->getName() === 'setup_authentication_backend') {
            $authData = $this->getPageData('setup_authentication_type');
            if ($authData['type'] === 'db') {
                $page->setResourceConfig($this->getPageData('setup_db_resource'));
            } elseif ($authData['type'] === 'ldap') {
                $page->setResourceConfig($this->getPageData('setup_ldap_resource'));

                $suggestions = $this->getPageData('setup_ldap_discovery_confirm');
                if (isset($suggestions['backend'])) {
                    $page->populate($suggestions['backend']);
                }
            }
        } elseif ($page->getName() === 'setup_ldap_discovery_confirm') {
            $page->setResourceConfig($this->getPageData('setup_ldap_discovery'));
        } elseif ($page->getName() === 'setup_admin_account') {
            $page->setBackendConfig($this->getPageData('setup_authentication_backend'));
            $authData = $this->getPageData('setup_authentication_type');
            if ($authData['type'] === 'db') {
                $page->setResourceConfig($this->getPageData('setup_db_resource'));
            } elseif ($authData['type'] === 'ldap') {
                $page->setResourceConfig($this->getPageData('setup_ldap_resource'));
            }
        } elseif ($page->getName() === 'setup_database_creation') {
            $page->setDatabaseSetupPrivileges($this->databaseSetupPrivileges);
            $page->setDatabaseUsagePrivileges($this->databaseUsagePrivileges);
            $page->setResourceConfig($this->getPageData('setup_db_resource'));
        } elseif ($page->getName() === 'setup_summary') {
            $page->setSubjectTitle('Icinga Web 2');
            $page->setSummary($this->getInstaller()->getSummary());
        } elseif ($page->getName() === 'setup_db_resource') {
            $ldapData = $this->getPageData('setup_ldap_resource');
            if ($ldapData !== null && $request->getPost('name') === $ldapData['name']) {
                $page->addError(t('The given resource name must be unique and is already in use by the LDAP resource'));
            }
        } elseif ($page->getName() === 'setup_ldap_resource') {
            $dbData = $this->getPageData('setup_db_resource');
            if ($dbData !== null && $request->getPost('name') === $dbData['name']) {
                $page->addError(
                    t('The given resource name must be unique and is already in use by the database resource')
                );
            }

            $suggestion = $this->getPageData('setup_ldap_discovery_confirm');
            if (isset($suggestion['resource'])) {
                $page->populate($suggestion['resource']);
            }
        } elseif ($page->getName() === 'setup_authentication_type' && $this->getDirection() === static::FORWARD) {
            $authData = $this->getPageData($page->getName());
            if ($authData !== null && $request->getPost('type') !== $authData['type']) {
                // Drop any existing page data in case the authentication type has changed,
                // otherwise it will conflict with other forms that depend on this one
                $pageData = & $this->getPageData();
                unset($pageData['setup_admin_account']);
                unset($pageData['setup_authentication_backend']);
            }
        } elseif ($page->getName() === 'setup_modules') {
            $page->setPageData($this->getPageData());
            $page->handleRequest($request);
        }
    }

    /**
     * @see Wizard::getNewPage()
     */
    protected function getNewPage($requestedPage, Form $originPage)
    {
        $skip = false;
        $newPage = parent::getNewPage($requestedPage, $originPage);
        if ($newPage->getName() === 'setup_db_resource') {
            $prefData = $this->getPageData('setup_preferences_type');
            $authData = $this->getPageData('setup_authentication_type');
            $skip = $prefData['type'] !== 'db' && $authData['type'] !== 'db';
        } elseif ($newPage->getname() === 'setup_ldap_discovery') {
            $authData = $this->getPageData('setup_authentication_type');
            $skip = $authData['type'] !== 'ldap';
        } elseif ($newPage->getName() === 'setup_ldap_discovery_confirm') {
            $skip = false === $this->hasPageData('setup_ldap_discovery');
        } elseif ($newPage->getName() === 'setup_ldap_resource') {
            $authData = $this->getPageData('setup_authentication_type');
            $skip = $authData['type'] !== 'ldap';
        } elseif ($newPage->getName() === 'setup_database_creation') {
            if (($config = $this->getPageData('setup_db_resource')) !== null && ! $config['skip_validation']) {
                $db = new DbTool($config);

                try {
                    $db->connectToDb(); // Are we able to login on the database?
                    if (array_search(key($this->databaseTables), $db->listTables()) === false) {
                        // In case the database schema does not yet exist the user
                        // needs the privileges to create and setup the database
                        $skip = $db->checkPrivileges($this->databaseSetupPrivileges, $this->databaseTables);
                    } else {
                        // In case the database schema exists the user needs the required privileges
                        // to operate the database, if those are missing we ask for another user
                        $skip = $db->checkPrivileges($this->databaseUsagePrivileges, $this->databaseTables);
                    }
                } catch (PDOException $_) {
                    try {
                        $db->connectToHost(); // Are we able to login on the server?
                        // It is not possible to reliably determine whether a database exists or not if a user can't
                        // log in to the database, so we just require the user to be able to create the database
                        $skip = $db->checkPrivileges($this->databaseSetupPrivileges, $this->databaseTables);
                    } catch (PDOException $_) {
                        // We are NOT able to login on the server..
                    }
                }
            } else {
                $skip = true;
            }
        }

        if ($skip) {
            if ($this->hasPageData($newPage->getName())) {
                $pageData = & $this->getPageData();
                unset($pageData[$newPage->getName()]);
            }

            $pages = $this->getPages();
            if ($this->getDirection() === static::FORWARD) {
                $nextPage = $pages[array_search($newPage, $pages, true) + 1];
                $newPage = $this->getNewPage($nextPage->getName(), $newPage);
            } else { // $this->getDirection() === static::BACKWARD
                $previousPage = $pages[array_search($newPage, $pages, true) - 1];
                $newPage = $this->getNewPage($previousPage->getName(), $newPage);
            }
        }

        return $newPage;
    }

    /**
     * @see Wizard::addButtons()
     */
    protected function addButtons(Form $page)
    {
        parent::addButtons($page);

        $pages = $this->getPages();
        $index = array_search($page, $pages, true);
        if ($index === 0) {
            $page->getElement(static::BTN_NEXT)->setLabel(t('Start', 'setup.welcome.btn.next'));
        } elseif ($index === count($pages) - 1) {
            $page->getElement(static::BTN_NEXT)->setLabel(t('Install Icinga Web 2', 'setup.summary.btn.finish'));
        }
    }

    /**
     * @see Wizard::clearSession()
     */
    public function clearSession()
    {
        parent::clearSession();
        $this->getPage('setup_modules')->clearSession();

        $tokenPath = Config::resolvePath('setup.token');
        if (file_exists($tokenPath)) {
            @unlink($tokenPath);
        }
    }

    /**
     * @see SetupWizard::getInstaller()
     */
    public function getInstaller()
    {
        $pageData = $this->getPageData();
        $installer = new Installer();

        if (isset($pageData['setup_db_resource'])
            && ! $pageData['setup_db_resource']['skip_validation']
            && (false === isset($pageData['setup_database_creation'])
                || ! $pageData['setup_database_creation']['skip_validation']
            )
        ) {
            $installer->addStep(
                new DatabaseStep(array(
                    'tables'            => $this->databaseTables,
                    'privileges'        => $this->databaseUsagePrivileges,
                    'resourceConfig'    => $pageData['setup_db_resource'],
                    'adminName'         => isset($pageData['setup_database_creation']['username'])
                        ? $pageData['setup_database_creation']['username']
                        : null,
                    'adminPassword'     => isset($pageData['setup_database_creation']['password'])
                        ? $pageData['setup_database_creation']['password']
                        : null
                ))
            );
        }

        $installer->addStep(
            new GeneralConfigStep(array(
                'generalConfig'         => $pageData['setup_general_config'],
                'preferencesType'       => $pageData['setup_preferences_type']['type'],
                'preferencesResource'   => isset($pageData['setup_db_resource']['name'])
                    ? $pageData['setup_db_resource']['name']
                    : null,
                'fileMode'              => $pageData['setup_general_config']['global_filemode']
            ))
        );

        $adminAccountType = $pageData['setup_admin_account']['user_type'];
        $adminAccountData = array('username' => $pageData['setup_admin_account'][$adminAccountType]);
        if ($adminAccountType === 'new_user' && ! $pageData['setup_db_resource']['skip_validation']
            && (false === isset($pageData['setup_database_creation'])
                || ! $pageData['setup_database_creation']['skip_validation']
            )
        ) {
            $adminAccountData['resourceConfig'] = $pageData['setup_db_resource'];
            $adminAccountData['password'] = $pageData['setup_admin_account']['new_user_password'];
        }
        $authType = $pageData['setup_authentication_type']['type'];
        $installer->addStep(
            new AuthenticationStep(array(
                'adminAccountData'  => $adminAccountData,
                'fileMode'          => $pageData['setup_general_config']['global_filemode'],
                'backendConfig'     => $pageData['setup_authentication_backend'],
                'resourceName'      => $authType === 'db' ? $pageData['setup_db_resource']['name'] : (
                    $authType === 'ldap' ? $pageData['setup_ldap_resource']['name'] : null
                )
            ))
        );

        if (isset($pageData['setup_db_resource']) || isset($pageData['setup_ldap_resource'])) {
            $installer->addStep(
                new ResourceStep(array(
                    'fileMode'              => $pageData['setup_general_config']['global_filemode'],
                    'dbResourceConfig'      => isset($pageData['setup_db_resource'])
                        ? array_diff_key($pageData['setup_db_resource'], array('skip_validation' => null))
                        : null,
                    'ldapResourceConfig'    => isset($pageData['setup_ldap_resource'])
                        ? array_diff_key($pageData['setup_ldap_resource'], array('skip_validation' => null))
                        : null
                ))
            );
        }

        $configDir = $this->getConfigDir();
        $installer->addStep(
            new MakeDirStep(
                array(
                    $configDir . '/modules',
                    $configDir . '/preferences',
                    $configDir . '/enabledModules'
                ),
                $pageData['setup_general_config']['global_filemode']
            )
        );

        foreach ($this->getPage('setup_modules')->setPageData($this->getPageData())->getWizards() as $wizard) {
            if ($wizard->isFinished()) {
                $installer->addSteps($wizard->getInstaller()->getSteps());
            }
        }

        return $installer;
    }

    /**
     * @see SetupWizard::getRequirements()
     */
    public function getRequirements()
    {
        $requirements = new Requirements();

        $phpVersion = Platform::getPhpVersion();
        $requirements->addMandatory(
            t('PHP Version'),
            t(
                'Running Icinga Web 2 requires PHP version 5.3.2. Advanced features'
                . ' like the built-in web server require PHP version 5.4.'
            ),
            version_compare($phpVersion, '5.3.2', '>='),
            sprintf(t('You are running PHP version %s.'), $phpVersion)
        );

        $defaultTimezone = Platform::getPhpConfig('date.timezone');
        $requirements->addMandatory(
            t('Default Timezone'),
            t('It is required that a default timezone has been set using date.timezone in php.ini.'),
            $defaultTimezone,
            $defaultTimezone ? sprintf(t('Your default timezone is: %s'), $defaultTimezone) : (
                t('You did not define a default timezone.')
            )
        );

        $requirements->addOptional(
            t('Linux Platform'),
            t(
                'Icinga Web 2 is developed for and tested on Linux. While we cannot'
                . ' guarantee they will, other platforms may also perform as well.'
            ),
            Platform::isLinux(),
            sprintf(t('You are running PHP on a %s system.'), Platform::getOperatingSystemName())
        );

        $requirements->addOptional(
            t('PHP Module: JSON'),
            t('The JSON module for PHP is required for various export functionalities as well as APIs.'),
            Platform::extensionLoaded('json'),
            Platform::extensionLoaded('json') ? t('The PHP module JSON is available.') : (
                t('The PHP module JSON is missing.')
            )
        );

        $requirements->addOptional(
            t('PHP Module: LDAP'),
            t('If you\'d like to authenticate users using LDAP the corresponding PHP module is required'),
            Platform::extensionLoaded('ldap'),
            Platform::extensionLoaded('ldap') ? t('The PHP module LDAP is available') : (
                t('The PHP module LDAP is missing')
            )
        );

        $requirements->addOptional(
            t('PHP Module: INTL'),
            t(
                'If you want your users to benefit from language, timezone and date/time'
                . ' format negotiation, the INTL module for PHP is required.'
            ),
            Platform::extensionLoaded('intl'),
            Platform::extensionLoaded('intl') ? t('The PHP module INTL is available') : (
                t('The PHP module INTL is missing')
            )
        );

        // TODO(6172): Remove this requirement once we do not ship dompdf with Icinga Web 2 anymore
        $requirements->addOptional(
            t('PHP Module: DOM'),
            t('To be able to export views and reports to PDF, the DOM module for PHP is required.'),
            Platform::extensionLoaded('dom'),
            Platform::extensionLoaded('dom') ? t('The PHP module DOM is available') : (
                t('The PHP module DOM is missing')
            )
        );

        $requirements->addOptional(
            t('PHP Module: GD'),
            t(
                'In case you want icons and graphs being exported to PDF'
                . ' as well, you\'ll need the GD extension for PHP.'
            ),
            Platform::extensionLoaded('gd'),
            Platform::extensionLoaded('gd') ? t('The PHP module GD is available') : (
                t('The PHP module GD is missing')
            )
        );

        $requirements->addOptional(
            t('PHP Module: PDO-MySQL'),
            t('Is Icinga Web 2 supposed to access a MySQL database the PDO-MySQL module for PHP is required.'),
            Platform::extensionLoaded('mysql'),
            Platform::extensionLoaded('mysql') ? t('The PHP module PDO-MySQL is available.') : (
                t('The PHP module PDO-MySQL is missing.')
            )
        );

        $requirements->addOptional(
            t('PHP Module: PDO-PostgreSQL'),
            t(
                'Is Icinga Web 2 supposed to access a PostgreSQL database'
                . ' the PDO-PostgreSQL module for PHP is required.'
            ),
            Platform::extensionLoaded('pgsql'),
            Platform::extensionLoaded('pgsql') ? t('The PHP module PDO-PostgreSQL is available.') : (
                t('The PHP module PDO-PostgreSQL is missing.')
            )
        );

        // TODO(7464): Re-enable or remove this entirely once a decision has been made regarding shipping Zend with Iw2
        /*$mysqlAdapterFound = Platform::zendClassExists('Zend_Db_Adapter_Pdo_Mysql');
        $requirements->addOptional(
            t('Zend Database Adapter For MySQL'),
            t('The Zend database adapter for MySQL is required to access a MySQL database.'),
            $mysqlAdapterFound,
            $mysqlAdapterFound ? t('The Zend database adapter for MySQL is available.') : (
                t('The Zend database adapter for MySQL is missing.')
            )
        );

        $pgsqlAdapterFound = Platform::zendClassExists('Zend_Db_Adapter_Pdo_Pgsql');
        $requirements->addOptional(
            t('Zend Database Adapter For PostgreSQL'),
            t('The Zend database adapter for PostgreSQL is required to access a PostgreSQL database.'),
            $pgsqlAdapterFound,
            $pgsqlAdapterFound ? t('The Zend database adapter for PostgreSQL is available.') : (
                t('The Zend database adapter for PostgreSQL is missing.')
            )
        );*/

        $configDir = $this->getConfigDir();
        $requirements->addMandatory(
            t('Writable Config Directory'),
            t(
                'The Icinga Web 2 configuration directory defaults to "/etc/icingaweb", if' .
                ' not explicitly set in the environment variable "ICINGAWEB_CONFIGDIR".'
            ),
            is_writable($configDir),
            sprintf(
                is_writable($configDir) ? t('The current configuration directory is writable: %s') : (
                    t('The current configuration directory is not writable: %s')
                ),
                $configDir
            )
        );

        foreach ($this->getPage('setup_modules')->setPageData($this->getPageData())->getWizards() as $wizard) {
            $requirements->merge($wizard->getRequirements()->allOptional());
        }

        return $requirements;
    }

    /**
     * Return the configuration directory of Icinga Web 2
     *
     * @return  string
     */
    protected function getConfigDir()
    {
        if (array_key_exists('ICINGAWEB_CONFIGDIR', $_SERVER)) {
            $configDir = $_SERVER['ICINGAWEB_CONFIGDIR'];
        } else {
            $configDir = '/etc/icingaweb';
        }

        $canonical = realpath($configDir);
        return $canonical ? $canonical : $configDir;
    }
}
