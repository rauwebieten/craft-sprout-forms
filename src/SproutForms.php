<?php

namespace barrelstrength\sproutforms;

use barrelstrength\sproutbase\app\email\services\Email;
use barrelstrength\sproutbase\app\reports\models\DataSource;
use barrelstrength\sproutbase\app\email\services\NotificationEmailEvents;
use barrelstrength\sproutbase\app\reports\services\DataSources;
use barrelstrength\sproutbase\SproutBase;
use barrelstrength\sproutforms\integrations\sproutemail\emailtemplates\basic\BasicSproutFormsNotification;
use barrelstrength\sproutforms\integrations\sproutemail\events\notificationevents\SaveEntryEvent;
use barrelstrength\sproutforms\integrations\sproutimport\elements\Form as FormElementImporter;
use barrelstrength\sproutforms\integrations\sproutimport\elements\Entry as EntryElementImporter;
use barrelstrength\sproutbase\base\BaseSproutTrait;
use barrelstrength\sproutbase\app\email\events\NotificationEmailEvent;
use barrelstrength\sproutforms\fields\Forms as FormsField;
use barrelstrength\sproutforms\fields\Entries as FormEntriesField;
use barrelstrength\sproutforms\widgets\RecentEntries;
use barrelstrength\sproutforms\events\OnBeforeSaveEntryEvent;
use barrelstrength\sproutforms\captchas\DuplicateCaptcha;
use barrelstrength\sproutforms\captchas\HoneypotCaptcha;
use barrelstrength\sproutforms\captchas\JavascriptCaptcha;
use barrelstrength\sproutforms\formtemplates\BasicTemplates;
use barrelstrength\sproutforms\formtemplates\AccessibleTemplates;
use barrelstrength\sproutforms\integrations\sproutimport\themes\BasicFieldsTheme;
use barrelstrength\sproutforms\integrations\sproutimport\themes\SpecialFieldsTheme;
use barrelstrength\sproutforms\integrations\sproutreports\datasources\EntriesDataSource;
use barrelstrength\sproutforms\services\App;
use barrelstrength\sproutforms\services\Entries;
use barrelstrength\sproutforms\services\Forms;
use barrelstrength\sproutbase\app\import\services\Importers;
use barrelstrength\sproutbase\app\import\services\Themes;
use Craft;
use craft\base\Plugin;

use craft\db\Connection;
use yii\db\Migration;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\helpers\UrlHelper;
use craft\services\Fields;
use craft\web\UrlManager;
use craft\services\UserPermissions;
use yii\base\Event;
use craft\web\twig\variables\CraftVariable;
use barrelstrength\sproutbase\SproutBaseHelper;
use barrelstrength\sproutforms\models\Settings;
use barrelstrength\sproutforms\web\twig\variables\SproutFormsVariable;
use barrelstrength\sproutforms\events\RegisterFieldsEvent;
use barrelstrength\sproutforms\services\Fields as SproutFormsFields;
use barrelstrength\sproutforms\events\OnBeforePopulateEntryEvent;
use barrelstrength\sproutforms\controllers\EntriesController;
use barrelstrength\sproutforms\elements\Entry as EntryElement;
use craft\services\Dashboard;

class SproutForms extends Plugin
{
    use BaseSproutTrait;

    /**
     * Enable use of SproutForms::$app-> in place of Craft::$app->
     *
     * @var App
     */
    public static $app;

    /**
     * Identify our plugin for BaseSproutTrait
     *
     * @var string
     */
    public static $pluginHandle = 'sprout-forms';

    /**
     * @var bool
     */
    public $hasCpSection = true;

    /**
     * @var bool
     */
    public $hasCpSettings = true;

    /**
     * @var string
     */
    public $schemaVersion = '3.0.4';

    /**
     * @var string
     */
    public $minVersionRequired = '2.5.1';

    /**
     * @throws \yii\base\InvalidConfigException
     */
    public function init()
    {
        parent::init();

        $this->setComponents([
            'app' => App::class
        ]);

        self::$app = $this->get('app');

        SproutBaseHelper::registerModule();

        Event::on(UrlManager::class, UrlManager::EVENT_REGISTER_CP_URL_RULES, function(RegisterUrlRulesEvent $event) {
            $event->rules = array_merge($event->rules, $this->getCpUrlRules());
        });

        Event::on(SproutFormsFields::class, SproutFormsFields::EVENT_REGISTER_FIELDS, function(RegisterFieldsEvent $event) {
            $fieldsByGroup = SproutForms::$app->fields->getRegisteredFieldsByGroup();

            foreach ($fieldsByGroup as $group) {
                foreach ($group as $field) {
                    $event->fields[] = new $field;
                }
            }
        });

        // Register DataSources for sproutReports plugin integration
        Event::on(DataSources::class, DataSources::EVENT_REGISTER_DATA_SOURCES, function(RegisterComponentTypesEvent $event) {
            $event->types[] = EntriesDataSource::class;
        });

        Event::on(Dashboard::class, Dashboard::EVENT_REGISTER_WIDGET_TYPES, function(RegisterComponentTypesEvent $event) {
            $event->types[] = RecentEntries::class;
        });

        $this->setComponents([
            'sproutforms' => SproutFormsVariable::class
        ]);

        Event::on(CraftVariable::class, CraftVariable::EVENT_INIT, function(Event $event) {
            $variable = $event->sender;
            $variable->set('sproutForms', SproutFormsVariable::class);
        });

        Event::on(Fields::class, Fields::EVENT_REGISTER_FIELD_TYPES, function(RegisterComponentTypesEvent $event) {
            $event->types[] = FormsField::class;
            $event->types[] = FormEntriesField::class;
        });

        Event::on(UserPermissions::class, UserPermissions::EVENT_REGISTER_PERMISSIONS, function(RegisterUserPermissionsEvent $event) {
            $event->permissions['Sprout Forms'] = $this->getUserPermissions();
        });

        Event::on(EntriesController::class, EntriesController::EVENT_BEFORE_POPULATE, function(OnBeforePopulateEntryEvent $event) {
            self::$app->entries->handleUnobfuscateEmailAddresses($event->form);
        });

        Event::on(NotificationEmailEvents::class, NotificationEmailEvents::EVENT_REGISTER_EMAIL_EVENT_TYPES, function(NotificationEmailEvent $event) {
            $event->events[] = SaveEntryEvent::class;
        });

        Event::on(Forms::class, Forms::EVENT_REGISTER_CAPTCHAS, function(Event $event) {
            $event->types[] = DuplicateCaptcha::class;
            $event->types[] = JavascriptCaptcha::class;
            $event->types[] = HoneypotCaptcha::class;
        });

        Event::on(Entries::class, EntryElement::EVENT_BEFORE_SAVE, function(OnBeforeSaveEntryEvent $event) {
            if (Craft::$app->getRequest()->getIsSiteRequest()) {
                $captchas = SproutForms::$app->forms->getAllEnabledCaptchas();

                foreach ($captchas as $captcha) {
                    $captcha->verifySubmission($event);
                }
            }
        });

        Craft::$app->view->hook('sproutForms.modifyForm', function(&$context) {
            $captchas = SproutForms::$app->forms->getAllEnabledCaptchas();
            $captchaHtml = '';

            foreach ($captchas as $captcha) {
                $captchaHtml .= $captcha->getCaptchaHtml();
            }

            return $captchaHtml;
        });

        Event::on(Forms::class, Forms::EVENT_REGISTER_FORM_TEMPLATES, function(RegisterComponentTypesEvent $event) {
            $event->types[] = BasicTemplates::class;
            $event->types[] = AccessibleTemplates::class;
        });

        // Register Sprout Email Templates
        Event::on(Email::class, Email::EVENT_REGISTER_EMAIL_TEMPLATES, function(RegisterComponentTypesEvent $event) {
            $event->types[] = BasicSproutFormsNotification::class;
        });

        Event::on(Importers::class, Importers::EVENT_REGISTER_IMPORTER_TYPES, function(RegisterComponentTypesEvent $event) {
            $event->types[] = FormElementImporter::class;
            $event->types[] = EntryElementImporter::class;
//            $event->types[] = FormsFieldImporter::class;
//            $event->types[] = EntriesFieldImporter::class;
        });

        Event::on(Themes::class, Themes::EVENT_REGISTER_THEME_TYPES, function(RegisterComponentTypesEvent $event) {
            $event->types[] = BasicFieldsTheme::class;
            $event->types[] = SpecialFieldsTheme::class;
        });
    }

    /**
     * @return Settings|\craft\base\Model|null
     */
    protected function createSettingsModel()
    {
        return new Settings();
    }

    /**
     * Redirect to Sprout Forms settings
     *
     * @return $this|mixed|\yii\web\Response
     */
    public function getSettingsResponse()
    {
        $url = UrlHelper::cpUrl('sprout-forms/settings');

        return Craft::$app->getResponse()->redirect($url);
    }

    /**
     * @return array|null
     * @throws \yii\db\Exception
     */
    public function getCpNavItem()
    {
        $parent = parent::getCpNavItem();

        // Allow user to override plugin name in sidebar
        if ($this->getSettings()->pluginNameOverride) {
            $parent['label'] = $this->getSettings()->pluginNameOverride;
        }

        if (Craft::$app->getUser()->checkPermission('manageSproutFormsForms')) {
            $parent['subnav']['forms'] = [
                'label' => Craft::t('sprout-forms', 'Forms'),
                'url' => 'sprout-forms/forms'
            ];
        }

        if (Craft::$app->getUser()->checkPermission('viewSproutFormsEntries')) {
            $parent['subnav']['entries'] = [
                'label' => Craft::t('sprout-forms', 'Entries'),
                'url' => 'sprout-forms/entries'
            ];
        }

        $parent['subnav']['notifications'] = [
            'label' => Craft::t('sprout-forms', 'Notifications'),
            'url' => 'sprout-forms/notifications'
        ];

        $entriesDataSource = SproutBase::$app->dataSources->getDataSourceByType(EntriesDataSource::class);

        // If we don't find a dataSource we need to generate our Sprout Forms Data Source record and then query for it again.
        if (!$entriesDataSource) {
            SproutBase::$app->dataSources->getAllDataSources();
            $entriesDataSource = SproutBase::$app->dataSources->getDataSourceByType(EntriesDataSource::class);
        }

        $parent['subnav']['reports'] = [
            'label' => Craft::t('sprout-forms', 'Reports'),
            'url' => 'sprout-forms/reports/'.$entriesDataSource->dataSourceId
        ];

        if (Craft::$app->getUser()->checkPermission('editSproutFormsSettings')) {
            $parent['subnav']['settings'] = [
                'label' => Craft::t('sprout-forms', 'Settings'),
                'url' => 'sprout-forms/settings'
            ];
        }

        return $parent;
    }

    /**
     * @return array
     */
    private function getCpUrlRules()
    {
        return [
            'sprout-forms/forms/new' =>
                'sprout-forms/forms/edit-form-template',

            'sprout-forms/forms/edit/<formId:\d+>' =>
                'sprout-forms/forms/edit-form-template',

            'sprout-forms/entries/edit/<entryId:\d+>' =>
                'sprout-forms/entries/edit-entry',

            'sprout-forms/settings/(general|advanced)' =>
                'sprout-forms/settings/settings-index-template',

            'sprout-forms/settings/entry-statuses/new' =>
                'sprout-forms/entry-statuses/edit',

            'sprout-forms/settings/entry-statuses/<entryStatusId:\d+>' =>
                'sprout-forms/entry-statuses/edit',

            'sprout-forms/forms/<groupId:\d+>' =>
                'sprout-forms/forms',

            'sprout-forms/reports/<dataSourceId>/new' =>
                'sprout-base/reports/edit-report',
            'sprout-forms/reports/<dataSourceId>/edit/<reportId>' =>
                'sprout-base/reports/edit-report',
            'sprout-forms/reports/view/<reportId>' =>
                'sprout-base/reports/results-index',
            'sprout-forms/reports/<dataSourceId>' =>
                'sprout-base/reports/index',

            'sprout-forms/notifications' => [
                'template' => 'sprout-base-email/notifications/index',
                'params' => [
                    'hideSidebar' => true
                ]
            ],

            'sprout-forms/settings/notifications/edit/<emailId:\d+|new>' =>
                'sprout-base/notifications/edit-notification-email-settings-template',
            'sprout-forms/notifications/edit/<emailId:\d+|new>' => [
                'route' => 'sprout-base/notifications/edit-notification-email-template',
                'params' => [
                    'defaultEmailTemplate' => BasicSproutFormsNotification::class
                ]
            ],
            'sprout-forms/preview/notification/<emailId:\d+>' => [
                'template' => 'sprout-base-email/notifications/_special/preview'
            ],

            'sprout-forms/settings' =>
                'sprout-base/settings/edit-settings',
            'sprout-forms/settings/<settingsSectionHandle:.*>' =>
                'sprout-base/settings/edit-settings'
        ];
    }

    /**
     * @return array
     */
    public function getUserPermissions()
    {
        return [
            'manageSproutFormsForms' => [
                'label' => Craft::t('sprout-forms', 'Manage Forms')
            ],
            'viewSproutFormsEntries' => [
                'label' => Craft::t('sprout-forms', 'View Form Entries'),
                'nested' => [
                    'editSproutFormsEntries' => [
                        'label' => Craft::t('sprout-forms', 'Edit Form Entries')
                    ]
                ]
            ],
            'editSproutFormsSettings' => [
                'label' => Craft::t('sprout-forms', 'Edit Settings')
            ]
        ];
    }

    /**
     * @throws \yii\db\Exception
     */
    protected function afterInstall()
    {
        // Add sprout reports data source integration
        $dataSourceClass = EntriesDataSource::class;

        $dataSourceModel = new DataSource();
        $dataSourceModel->type = $dataSourceClass;
        $dataSourceModel->allowNew = 1;
        // Set all pre-built class to sprout-reports $pluginHandle
        $dataSourceModel->pluginHandle = 'sprout-forms';

        SproutBase::$app->dataSources->saveDataSource($dataSourceModel);
    }

    /**
     * @return bool
     * @throws \Exception
     * @throws \Throwable
     */
    public function beforeUninstall(): bool
    {
        $forms = SproutForms::$app->forms->getAllForms();

        foreach ($forms as $form) {
            SproutForms::$app->forms->deleteForm($form);
        }

        return true;
    }
}

