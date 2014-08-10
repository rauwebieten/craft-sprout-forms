<?php
namespace Craft;

/**
 * Forms controller
 */
class SproutForms_FormsController extends BaseController
{
	/**
	 * Saves a form
	 */
	public function actionSaveForm()
	{
		$this->requirePostRequest();

		$form = new SproutForms_FormModel();

		// Shared attributes
		$form->id         = craft()->request->getPost('id');
		$form->groupId    = craft()->request->getPost('groupId');
		$form->name       = craft()->request->getPost('name');
		$form->handle     = craft()->request->getPost('handle');
		$form->titleFormat = craft()->request->getPost('titleFormat');
		$form->redirectUri     = craft()->request->getPost('redirectUri');
		$form->submitAction    = craft()->request->getPost('submitAction');
		$form->submitButtonText     = craft()->request->getPost('submitButtonText');
		$form->notificationRecipients     = craft()->request->getPost('notificationRecipients');
		$form->notificationSubject     = craft()->request->getPost('notificationSubject');
		$form->notificationSenderName     = craft()->request->getPost('notificationSenderName');
		$form->notificationSenderEmail     = craft()->request->getPost('notificationSenderEmail');
		$form->notificationReplyToEmail     = craft()->request->getPost('notificationReplyToEmail');

		// Save it
		if (craft()->sproutForms_forms->saveForm($form))
		{
			craft()->userSession->setNotice(Craft::t('Form saved.'));
			$this->redirectToPostedUrl($form);
		}
		else
		{
			craft()->userSession->setError(Craft::t('Couldn’t save form.'));
		}

		// Send the calendar back to the template
		craft()->urlManager->setRouteVariables(array(
			'form' => $form
		));
	}

	/**
	 * Edit a form.
	 *
	 * @param array $variables
	 * @throws HttpException
	 * @throws Exception
	 */
	public function actionEditFormTemplate(array $variables = array())
	{
		$variables['brandNewForm'] = false;
		
		$variables['groups'] = craft()->sproutForms_groups->getAllFormGroups();
		$variables['groupId'] = "";

		if (!empty($variables['formId']))
		{
			if (empty($variables['form']))
			{
				$variables['form'] = craft()->sproutForms_forms->getFormById($variables['formId']);

				$variables['fields'] = $variables['form']->getFieldLayout()->getFields();

				$variables['groupId'] = $variables['form']->groupId;
				
				if (!$variables['form'])
				{
					throw new HttpException(404);
				}
			}

			$variables['title'] = $variables['form']->name;
		}
		else
		{
			if (empty($variables['form']))
			{
				$variables['form'] = new SproutForms_FormModel();
				$variables['brandNewForm'] = true;
			}

			$variables['title'] = Craft::t('Create a new form');
		}

		$this->renderTemplate('sproutforms/forms/_edit', $variables);
	}

	/**
	 * Deletes a form.
	 * 
	 * @return void
	 */
	public function actionDeleteForm()
	{	
		$this->requirePostRequest();
		
		// Get the Form these fields are related to
		$formId = craft()->request->getRequiredPost('id');
		$form = craft()->sproutForms_forms->getFormById($formId);
		
		// @TODO - handle errors
		$success = craft()->sproutForms_forms->deleteForm($form);

		$this->redirectToPostedUrl($form);
	}

}
