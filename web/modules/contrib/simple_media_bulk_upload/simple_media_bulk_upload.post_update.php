<?php

/**
 * @file
 * Simple Media Bulk Upload post updates file.
 */

/**
 * Add setting for max files.
 */
function simple_media_bulk_upload_post_update_add_max_files(): void {
  \Drupal::configFactory()->getEditable('simple_media_bulk_upload.settings')->set('max_files', 0)->save();
}
