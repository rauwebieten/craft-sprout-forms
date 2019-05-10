<?php

namespace barrelstrength\sproutforms\migrations;

use barrelstrength\sproutforms\integrationtypes\CustomEndpoint;
use barrelstrength\sproutforms\records\Integration as IntegrationRecord;
use craft\db\Migration;
use craft\db\Query;

/**
 * m190410_000000_add_payload_forwarding_to_integration migration.
 */
class m190410_000000_add_payload_forwarding_to_integration extends Migration
{
    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        $forms = (new Query())
            ->select(['id', 'submitAction'])
            ->from(['{{%sproutforms_forms}}'])
            ->where('[[submitAction]] is not null')
            ->all();

        $type = CustomEndpoint::class;

        foreach ($forms as $form) {
            $integrationRecord = new IntegrationRecord();
            $integrationRecord->type = $type;
            $integrationRecord->formId = $form['id'];
            /** @var CustomEndpoint $integrationApi */
            $integrationApi = $integrationRecord->getIntegrationApi();
            $settings = [];

            if ($form['submitAction']) {
                $settings['submitAction'] = $form['submitAction'];
                $formFields = $integrationApi->getFormFieldsAsMappingOptions();
                $fieldMapping = [];
                foreach ($formFields as $formField) {
                    $fieldMapping[] = [
                        'sproutFormField' => $formField['value'],
                        'integrationField' => ''
                    ];
                }

                $integrationRecord->name = $integrationApi->getName();
                $integrationRecord->settings = json_encode($settings);
                $integrationRecord->save();
            }
        }

        if ($this->db->columnExists('{{%sproutforms_forms}}', 'submitAction')) {
            $this->dropColumn('{{%sproutforms_forms}}', 'submitAction');
        }

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        echo "m190410_000000_add_payload_forwarding_to_integration cannot be reverted.\n";
        return false;
    }
}
