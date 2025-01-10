<?php

namespace Drupal\simple_media_bulk_upload;

use Drupal\Core\Form\FormStateInterface;

/**
 * Form helper for handling bulk upload of media items.
 */
class BulkUploadFormHelper {

  /**
   * Add redirect to next entity in the queue for bulk creation.
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $formState
   *   The form state object for the media form.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Drupal\Core\Entity\EntityMalformedException
   */
  public static function processSubmissionRedirectForBulkUpload(array &$form, FormStateInterface $formState): void {
    $existingQueryParams = \Drupal::request()->query->all();
    if (array_key_exists('bulk_upload_ids', $existingQueryParams)) {
      // If there are more entities to create, redirect to the edit form for the
      // next one in line.
      $queue = $existingQueryParams['bulk_upload_ids'];

      if (is_array($queue)) {
        $remaining = count($queue);
        \Drupal::messenger()->addMessage(\Drupal::translation()->formatPlural(
          $remaining,
          'Editing last remaining item from the bulk upload.',
          '@count remaining items to edit from the bulk upload.',
          ['@count' => $remaining]
        ));

        $id = array_shift($queue);

        // Add the remaining queue as a query param in the redirect, so the next
        // form submission gets the list.
        $queryParams = [];
        if (!empty($queue)) {
          $queryParams['bulk_upload_ids'] = $queue;
        }
        $redirect = \Drupal::entityTypeManager()
          ->getStorage('media')
          ->load($id)
          ->toUrl('edit-form', [
            'query' => $queryParams,
          ]);
        $formState->setRedirectUrl($redirect);
      }
      else {
        \Drupal::messenger()->addMessage(t('All items from the bulk upload have been updated.'));
      }
    }
  }

}
