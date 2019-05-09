<?php

namespace barrelstrength\sproutforms\controllers;

use barrelstrength\sproutforms\base\ElementIntegration;
use barrelstrength\sproutforms\integrationtypes\EntryElementIntegration;
use barrelstrength\sproutforms\records\Integration as IntegrationRecord;
use Craft;

use craft\web\Controller as BaseController;
use barrelstrength\sproutforms\SproutForms;

class IntegrationsController extends BaseController
{
    /**
     * This action allows to load the modal field template.
     *
     * @return \yii\web\Response
     * @throws \Twig\Error\LoaderError
     * @throws \Twig\Error\RuntimeError
     * @throws \Twig\Error\SyntaxError
     * @throws \yii\web\BadRequestHttpException
     */
    public function actionModalIntegration()
    {
        $this->requireAcceptsJson();
        $formId = Craft::$app->getRequest()->getBodyParam('formId');
        $form = SproutForms::$app->forms->getFormById($formId);

        return $this->asJson(SproutForms::$app->integrations->getModalIntegrationTemplate($form));
    }

    /**
     * This action allows create a default integration given a type.
     *
     * @return \yii\web\Response
     * @throws \Throwable
     * @throws \yii\web\BadRequestHttpException
     */
    public function actionCreateIntegration()
    {
        $this->requireAcceptsJson();

        $request = Craft::$app->getRequest();
        $type = $request->getBodyParam('type');
        $formId = $request->getBodyParam('formId');
        $form = SproutForms::$app->forms->getFormById($formId);

        if ($type && $form) {
            $integration = SproutForms::$app->integrations->createIntegration($type, $form);

            if ($integration) {
                return $this->returnJson(true, $integration);
            }
        }

        return $this->returnJson(false, null);
    }

    /**
     * This action allows enable or disable an integration
     *
     * @return \yii\web\Response
     * @throws \Throwable
     * @throws \yii\web\BadRequestHttpException
     */
    public function actionEnableIntegration()
    {
        $this->requireAcceptsJson();

        $request = Craft::$app->getRequest();
        $integrationId = $request->getBodyParam('integrationId');
        $enabled = $request->getBodyParam('enabled');
        $enabled = $enabled == 1 ? true : false;
        $formId = $request->getBodyParam('formId');
        $form = SproutForms::$app->forms->getFormById($formId);

        if ($integrationId == 'saveData' && $form) {
            $form->saveData = $enabled;

            if (SproutForms::$app->forms->saveForm($form)) {
                return $this->asJson([
                    'success' => true
                ]);
            }
        }else{
            $pieces = explode('-', $integrationId);

            if (count($pieces) == 3){
                $integration = SproutForms::$app->integrations->getFormIntegrationById($pieces[2]);
                if ($integration){
                    $integration->enabled = $enabled;
                    if ($integration->save()){
                        return $this->returnJson(true, $integration);
                    }
                }
            }
        }

        return  $this->asJson([
            'success' => false
        ]);
    }

    /**
     * Save an Integration
     *
     * @return \yii\web\Response
     * @throws \yii\web\BadRequestHttpException
     */
    public function actionSaveIntegration()
    {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();

        $type = $request->getRequiredBodyParam('type');
        $integrationId = $request->getBodyParam('integrationId');
        $enabled = $request->getBodyParam('enabled');
        $name = $request->getBodyParam('name');
        $settings = $request->getBodyParam('types.'.$type);
        $integration = SproutForms::$app->integrations->getFormIntegrationById($integrationId);

        $integration->enabled = $enabled;
        $integration->settings = json_encode($settings);
        $integration->name = $name ?? $integration->name;
        $result = $integration->save();

        if (!$result) {
            Craft::error('Integration does not validate.', __METHOD__);
        }

        Craft::info('Integration Saved', __METHOD__);

        return $this->returnJson($result, $integration);
    }

    /**
     * Edits an existing integration.
     *
     * @return \yii\web\Response
     * @throws \Twig\Error\LoaderError
     * @throws \Twig\Error\RuntimeError
     * @throws \Twig\Error\SyntaxError
     * @throws \yii\web\BadRequestHttpException
     */
    public function actionEditIntegration()
    {
        $this->requireAcceptsJson();
        $request = Craft::$app->getRequest();

        $id = $request->getBodyParam('integrationId');
        $formId = $request->getBodyParam('formId');
        $form = SproutForms::$app->forms->getFormById($formId);

        $integration = IntegrationRecord::findOne($id);

        if ($integration === null) {
            $message = Craft::t('sprout-forms', 'The integration requested to edit no longer exists.');
            Craft::error($message, __METHOD__);

            return $this->asJson([
                'success' => false,
                'error' => $message,
            ]);
        }

        return $this->asJson([
            'success' => true,
            'errors' => $integration->getErrors(),
            'integration' => [
                'id' => $integration->id,
                'name' => $integration->name
            ],
            'template' => SproutForms::$app->integrations->getModalIntegrationTemplate($form, $integration),
        ]);
    }

    /**
     * @return \yii\web\Response
     * @throws \Throwable
     * @throws \yii\web\BadRequestHttpException
     */
    public function actionDeleteIntegration()
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $integrationId = Craft::$app->request->getRequiredBodyParam('integrationId');
        $integration = IntegrationRecord::findOne($integrationId);

        $response = $integration->delete();

        return $this->asJson([
            'success' => $response
        ]);
    }

    /**
     * @return \yii\web\Response
     * @throws \yii\web\BadRequestHttpException
     */
    public function actionGetFormFields()
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $entryTypeId = Craft::$app->request->getRequiredBodyParam('entryTypeId');
        $integrationId = Craft::$app->request->getRequiredBodyParam('integrationId');

        $fieldOptionsByRow = $this->getFieldsAsOptionsByRow($entryTypeId, $integrationId);
        return $this->asJson([
            'success' => 'true',
            'fieldOptionsByRow' => $fieldOptionsByRow
        ]);
    }

    /**
     * @return \yii\web\Response
     * @throws \yii\web\BadRequestHttpException
     */
    public function actionGetElementEntryFields()
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $entryTypeId = Craft::$app->request->getRequiredBodyParam('entryTypeId');
        $integrationId = Craft::$app->request->getRequiredBodyParam('integrationId');
        $integrationRecord = IntegrationRecord::findOne($integrationId);
        /** @var EntryElementIntegration $integration */
        $integration = $integrationRecord->getIntegrationApi();

        $entryFields = $integration->getElementFieldsAsOptions($entryTypeId);

        return $this->asJson([
            'success' => 'true',
            'fieldOptionsByRow' => $entryFields
        ]);
    }

    /**
     * @param      $entryTypeId
     * @param null $integrationId
     *
     * @return array
     */
    private function getFieldsAsOptionsByRow($entryTypeId, $integrationId)
    {
        $integrationRecord = IntegrationRecord::findOne($integrationId);

        /** @var ElementIntegration $integration */
        $integration = $integrationRecord->getIntegrationApi();
        $targetElementFields = $integration->getElementFieldsAsOptions($entryTypeId);
        $fieldsMapped = $integration->fieldsMapped;
        $integrationSectionId = $integration->entryTypeId ?? null;
        $firstRow = [
            'label' => 'None',
            'value' => ''
        ];
        $sourceFormFields = $integration->getFormFieldsAsOptions(true);
        array_unshift($sourceFormFields, $firstRow);

        $rowPosition = 0;

        $allTargetElementFieldOptions = [];

        foreach ($targetElementFields as $targetElementField) {
            $compatibleFields = $this->getCompatibleFields($sourceFormFields, $targetElementField);
            $integrationValue = $targetElementField['value'] ?? $targetElementField->handle;
            // We have rows stored and are for the same sectionType
            if ($fieldsMapped && ($integrationSectionId == $entryTypeId)) {
                if (isset($fieldsMapped[$rowPosition])) {
                    foreach ($compatibleFields as $key => $option) {
                        if (isset($option['optgroup'])) {
                            continue;
                        }

                        if ($option['value'] == $fieldsMapped[$rowPosition]['sproutFormField'] &&
                            $fieldsMapped[$rowPosition]['integrationField'] == $integrationValue) {
                            $compatibleFields[$key]['selected'] = true;
                        }
                    }
                }
            }

            $allTargetElementFieldOptions[$rowPosition] = $compatibleFields;

            $rowPosition++;
        }

        $allTargetElementFieldOptions = $this->removeUnnecessaryOptgroups($allTargetElementFieldOptions);

        return $allTargetElementFieldOptions;
    }

    /**
     * @param $allTargetElementFieldOptions
     *
     * @return array
     */
    private function removeUnnecessaryOptgroups($allTargetElementFieldOptions)
    {
        $aux = [];
        // Removes optgroups with no fields
        foreach ($allTargetElementFieldOptions as $rowIndex => $targetElementFieldOptions) {
            foreach ($targetElementFieldOptions as $key => $dropdownOption) {
                if (isset($dropdownOption['optgroup'])) {

                    if (isset($targetElementFieldOptions[$key + 1])) {
                        if (isset($targetElementFieldOptions[$key + 1]['value'])) {
                            $aux[$rowIndex][] = $targetElementFieldOptions[$key];
                        }
                    }
                } else {
                    $aux[$rowIndex][] = $dropdownOption;
                }
            }
        }

        return $aux;
    }

    /**
     * @param array $formFields
     * @param       $entryField
     *
     * @return array
     */
    private function getCompatibleFields(array $formFields, $entryField)
    {
        $finalOptions = [];
        $groupFields = [];

        foreach ($formFields as $pos => $field) {
            if (isset($field['optgroup'])) {
                $finalOptions[] = $field;
                continue;
            }
            $compatibleFields = $field['compatibleCraftFields'] ?? '*';
            // Check default attributes
            if (isset($entryField['class'])) {
                if (is_array($compatibleFields)) {
                    if (!in_array($entryField['class'], $compatibleFields)) {
                        $field = null;
                    }
                }

                if ($field) {
                    $groupFields[] = $field;
                    $finalOptions[] = $field;
                }
            }
        }

        return $finalOptions;
    }

    /**
     * @param bool $success
     * @param      $integrationRecord IntegrationRecord
     *
     * @return \yii\web\Response
     */
    private function returnJson(bool $success, $integrationRecord)
    {
        // @todo how we should return errors to the edit integration modal? template response is disabled for now
        return $this->asJson([
            'success' => $success,
            'errors' => $integrationRecord ? $integrationRecord->getErrors() : null,
            'integration' => [
                'id' => $integrationRecord->id,
                'name' => $integrationRecord->name ?? null,
                'enabled' => $integrationRecord->enabled
            ],
            //'template' => $success ? false : SproutForms::$app->integrations->getModalIntegrationTemplate($form, $integration),
        ]);
    }
}