<?php

namespace Drupal\simple_media_bulk_upload\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Config form for the Simple Media Bulk Upload module.
 */
class BulkUploadConfigForm extends ConfigFormBase {

  /**
   * {@inheritDoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['max_files'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum number of files to allow being uploaded at once'),
      '#default_value' => $this->config('simple_media_bulk_upload.settings')->get('max_files'),
      '#min' => 0,
      '#description' => $this->t('Set to 0 for no limit.'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritDoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this
      ->config('simple_media_bulk_upload.settings')
      ->set('max_files', $form_state->getValue('max_files'))
      ->save();

    parent::submitForm($form, $form_state);
  }

  /**
   * {@inheritDoc}
   */
  public function getFormId(): string {
    return 'simple_media_bulk_upload_config';
  }

  /**
   * {@inheritDoc}
   */
  protected function getEditableConfigNames(): array {
    return ['simple_media_bulk_upload.settings'];
  }

}
