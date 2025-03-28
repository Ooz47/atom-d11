<?php

declare(strict_types=1);

namespace Drupal\image_effects\Plugin\ImageToolkit\Operation\gd;

use Drupal\Core\ImageToolkit\Attribute\ImageToolkitOperation;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\image_effects\Plugin\ImageToolkit\Operation\ReplaceImageTrait;
use Drupal\system\Plugin\ImageToolkit\Operation\gd\GDImageToolkitOperationBase;

/**
 * Defines GD2 image replace operation.
 */
#[ImageToolkitOperation(
  id: 'image_effects_gd_replace_image',
  toolkit: 'gd',
  operation: 'replace_image',
  label: new TranslatableMarkup('Replace image'),
  description: new TranslatableMarkup('Replace the current image with another one.'),
)]
class ReplaceImage extends GDImageToolkitOperationBase {

  use ReplaceImageTrait;

  /**
   * {@inheritdoc}
   */
  protected function execute(array $arguments) {
    // Prepare the new image.
    $data = [
      'width' => $arguments['replacement_image']->getWidth(),
      'height' => $arguments['replacement_image']->getHeight(),
      'extension' => image_type_to_extension($arguments['replacement_image']->getToolkit()->getType(), FALSE),
      'transparent_color' => $arguments['replacement_image']->getToolkit()->getTransparentColor(),
      'is_temp' => FALSE,
    ];
    if (!$this->getToolkit()->apply('create_new', $data)) {
      return FALSE;
    }

    // Overlay replacement image.
    $data = [
      'watermark_image' => $arguments['replacement_image'],
      'x_offset' => 0,
      'y_offset' => 0,
    ];
    return $this->getToolkit()->apply('watermark', $data);
  }

}
