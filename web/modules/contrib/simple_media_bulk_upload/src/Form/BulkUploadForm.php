<?php

namespace Drupal\simple_media_bulk_upload\Form;

use Drupal\Component\Utility\Environment;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\Exception\FileException;
use Drupal\Core\File\Exception\InvalidStreamWrapperException;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\ByteSizeMarkup;
use Drupal\file\FileInterface;
use Drupal\file\FileRepositoryInterface;
use Drupal\file\Plugin\Field\FieldType\FileItem;
use Drupal\media\MediaInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * A form for uploading multiple media assets at once.
 */
class BulkUploadForm extends FormBase {

  /**
   * Construct a new BulkUploadForm.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\file\FileRepositoryInterface $fileRepository
   *   The file repository.
   * @param \Drupal\Core\File\FileSystemInterface $fileSystem
   *   The file system.
   *
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected FileRepositoryInterface $fileRepository,
    protected FileSystemInterface $fileSystem,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('file.repository'),
      $container->get('file_system')
    );
  }

  /**
   * Page title callback for the bulk upload form route.
   */
  public function getRouteTitle(Request $request) {
    $title = 'Bulk upload';

    // Add the media type to the page title if one was pre-selected via a
    // query parameter. This helps the user understand exactly what they are
    // bulk uploading.
    $mediaTypeID = $request->query->get('media_type');
    if ($mediaTypeID) {
      $mediaType = $this->entityTypeManager->getStorage('media_type')->load($mediaTypeID);
      if ($mediaType) {
        $mediaAccessControlHandler = $this->entityTypeManager->getAccessControlHandler('media');
        if ($mediaAccessControlHandler->createAccess($mediaTypeID)) {
          // Ideally we'd have access to a plural version of the media type, but
          // we don't. So just use a colon and indicate the type.
          $title .= ': ' . $mediaType->label();
        }
      }
    }
    return $title;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'simple_media_bulk_upload_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $allowedMediaTypes = $this->getAllowedMediaTypes();
    $allowedMediaTypeOptions = [];
    foreach ($allowedMediaTypes as $mediaType) {
      /** @var \Drupal\media\MediaTypeInterface $mediaType */
      $allowedMediaTypeOptions[$mediaType->id()] = $mediaType->label();
    }

    if (empty($allowedMediaTypeOptions)) {
      throw new AccessDeniedHttpException('There are no media types you have access to create.');
    }

    // Allow passing in a specific media type to use as a query parameter.
    $queryMediaType = $this->getRequest()->query->get('media_type');
    if ($queryMediaType && array_key_exists($queryMediaType, $allowedMediaTypeOptions)) {
      $form['media_type'] = [
        '#type' => 'value',
        '#value' => $queryMediaType,
      ];
      // Set the value right away so as it's checked below when building the
      // bulk upload widget.
      $form_state->setValue('media_type', $queryMediaType);
    }
    else {
      $form['media_type'] = [
        '#type' => 'radios',
        '#title' => $this->t('Media type'),
        '#options' => $allowedMediaTypeOptions,
        '#ajax' => [
          'callback' => '::ajaxCallback',
          'wrapper' => 'upload-container',
        ],
      ];
    }

    $form['upload_container'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'upload-container'],
    ];

    // Selected media type comes only when the user has selected one and
    // triggered the ajax form rebuild.
    $selectedMediaType = $form_state->getValue('media_type');
    if ($selectedMediaType) {
      $sourceFieldSettings = $this->getSourceFieldSettings($selectedMediaType);
      $maxSize = !empty($sourceFieldSettings['max_filesize']) ? $sourceFieldSettings['max_filesize'] : ByteSizeMarkup::create(Environment::getUploadMaxSize());
      $form['upload_container']['dropzone'] = [
        '#type' => 'dropzonejs',
        '#dropzone_description' => $this->t('Drag files here to upload them'),
        '#extensions' => $sourceFieldSettings['file_extensions'],
        '#max_filesize' => $sourceFieldSettings['max_filesize'],
      ];
      $variables = [
        '@max_size' => $maxSize,
        '@extensions' => $sourceFieldSettings['file_extensions'],
      ];
      $form['upload_container']['dropzone']['#description'] = $this->t('Each file can be up to @max_size in size. The following file extensions are accepted: @extensions', $variables);

      $config = $this->config('simple_media_bulk_upload.settings');
      if ($config->get('max_files') > 0) {
        $form['upload_container']['max_files_msg'] = [
          '#markup' => $this->t('Up to @num files can be uploaded at once', [
            '@num' => $config->get('max_files'),
          ]),
          '#prefix' => '<p>',
          '#suffix' => '</p>',
          '#weight' => -10,
        ];
      }

      $form['upload_container']['submit'] = [
        '#type' => 'submit',
        '#value' => $this->t('Continue'),
      ];
    }

    return $form;
  }

  /**
   * AJAX callback for returning the upload container.
   *
   * The upload container element is the only thing that changes from the AJAX
   * submission being processed. It will replace the empty one that was
   * initially provided before a media type was selected.
   */
  public function ajaxCallback(array &$form, FormStateInterface $formState) {
    return $form['upload_container'];
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $bulkCreate = [];

    $uploads = $form_state->getValue(['dropzone', 'uploaded_files']);

    // Create media entities for each uploaded file.
    // After https://drupal.org/i/2940383 is resolved, this will get a lot
    // easier and we shoul dbe able to invoke a new centralized service for
    // uploading files that does all this dirty work for us.
    foreach ($uploads as $upload) {
      // Create a file entity for the temporary file.
      /** @var \Drupal\file\FileInterface $file */
      $file = $this->entityTypeManager->getStorage('file')->create([
        'uri' => $upload['path'],
        'uid' => $this->currentUser()->id(),
      ]);
      $file->setTemporary();
      $file->save();

      // Create the media entity and set the file as its source.
      // It's possible that this media entity has some required fields. We are
      // not filling them out (there's no way for us to do so as this is a bulk
      // upload), and won't validate the entity before saving for that reason.
      // The user has the opportunity to populate fields afterwards.
      $entity = $this->entityTypeManager
        ->getStorage('media')
        ->create([
          'bundle' => $form_state->getValue('media_type'),
        ]);
      $sourceField = $entity->getSource()->getSourceFieldDefinition($entity->get('bundle')->entity);
      $entity->set($sourceField->getName(), $file);

      // Figure out the final destination for the upload and move it there.
      $destination = '';
      $destination .= $this->prepareFileDestination($entity);
      if (substr($destination, -1) != '/') {
        $destination .= '/';
      }
      $destination .= $file->getFilename();

      $movedFile = $this->moveFileToPermanentDestination($file, $destination);
      if (!$movedFile) {
        $this->messenger()->addError($this->t('Error uploading file %name, contact a site administrator for additional details.', [
          '%name' => $file->getFilename(),
        ]));
        continue;
      }

      // Fix potential mismatch in the file URI and the filename property on
      // the file entity which can happen if the file was renamed during the
      // move.
      $movedFile->setFilename(basename($movedFile->getFileUri()));
      $movedFile->setPermanent();
      $movedFile->save();
      // The original temp file was cloned, so we need to reset it on the source
      // field.
      $entity->set($sourceField->getName(), $movedFile);
      $entity->save();

      // Push the ID to the stack, but if it's the first item, push the entity
      // instead because we need to use it right away for the initial redirect.
      $bulkCreate[] = $bulkCreate ? $entity->id() : $entity;
    }

    if (!empty($bulkCreate)) {
      $this->messenger()->addMessage($this->t('Successfully uploaded @count items. Use the form below to edit details for the first item, and continue until all items have been updated.', [
        '@count' => count($bulkCreate),
      ]));

      // Redirect user to the media edit form for the first media entity that
      // was saved. Pass along the IDs of the remaining entities as a
      // query parameter, allowing them to be used later.
      /** @var \Drupal\media\MediaInterface $entity */
      $redirect = array_shift($bulkCreate)->toUrl('edit-form', [
        'query' => [
          'bulk_upload_ids' => $bulkCreate,
        ],
      ]);
      $form_state->setRedirectUrl($redirect);
    }
  }

  /**
   * Move a file to a new destination.
   *
   * @param \Drupal\file\FileInterface $file
   *   The file entity to move.
   * @param string $destination
   *   The URI to move the file to.
   *
   * @return \Drupal\file\FileInterface|bool
   *   The moved file, or FALSE if it did not work.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  protected function moveFileToPermanentDestination(FileInterface $file, string $destination) {
    try {
      return $this->fileRepository->move($file, $destination);
    }
    catch (InvalidStreamWrapperException $e) {
      if (($realpath = $this->fileSystem->realpath($file->getFileUri())) !== FALSE) {
        $this->getLogger('file')->notice(
          'File %file (%realpath) could not be moved because the destination %destination is invalid. This may be caused by improper use of file_move() or a missing stream wrapper.',
          [
            '%file' => $file->getFileUri(),
            '%realpath' => $realpath,
            '%destination' => $destination,
          ]
        );
      }
      else {
        $this->getLogger('file')->notice(
          'File %file could not be moved because the destination %destination is invalid. This may be caused by improper use of file_move() or a missing stream wrapper.',
          [
            '%file' => $file->getFileUri(),
            '%destination' => $destination,
          ]
        );
      }
      $this->messenger()->addError($this->t('The specified file %file could not be moved because the destination is invalid. More information is available in the system log.', ['%file' => $file->getFileUri()]));
      return FALSE;
    }
    catch (FileException $e) {
      $this->getLogger('simple_media_bulk_upload')->error(
        'FileException when attempting to move temp uploaded file %file to permanent location: %e',
        [
          '%file' => $file->getFileUri(),
          '%e' => $e->getMessage(),
        ]
      );
      return FALSE;
    }
  }

  /**
   * Return the accepted file extensions for the provided media type.
   *
   * @param string $mediaTypeId
   *   The ID of the media type entity.
   *
   * @return array
   *   The field config settings for the source field.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  protected function getSourceFieldSettings(string $mediaTypeId): array {
    $mediaType = $this->entityTypeManager->getStorage('media_type')->load($mediaTypeId);
    $field = $mediaType->getSource()->getSourceFieldDefinition($mediaType);
    return $field->getSettings();
  }

  /**
   * Get list of Media Type IDs that have file-based source fields.
   *
   * @return array
   *   The list of media type IDs.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  protected function getAllowedMediaTypes(): array {
    $mediaTypes = $this->entityTypeManager
      ->getStorage('media_type')
      ->loadMultiple();
    $mediaAccessControlHandler = $this->entityTypeManager->getAccessControlHandler('media');
    return array_filter($mediaTypes, function ($mediaType) use ($mediaAccessControlHandler) {
      $mediaSourceField = $mediaType->getSource()->getSourceFieldDefinition($mediaType);
      // If the field is a FileItem or any of its descendants, we can consider
      // it a file field. This will automatically include things like image
      // fields, which extend file fields.
      $isFileBased = is_a($mediaSourceField->getItemDefinition()->getClass(), FileItem::class, TRUE);
      return $isFileBased && $mediaAccessControlHandler->createAccess($mediaType->id());
    });
  }

  /**
   * Prepares the destination directory for a file attached to a media entity.
   *
   * @param \Drupal\media\MediaInterface $entity
   *   The media entity.
   *
   * @return string
   *   The destination directory URI.
   */
  protected function prepareFileDestination(MediaInterface $entity): string {
    $sourceField = $entity->getSource()->getSourceFieldDefinition($entity->get('bundle')->entity);
    /** @var \Drupal\file\Plugin\Field\FieldType\FileItem $item */
    $item = $entity->get($sourceField->getName())->first();

    $destination = $item->getUploadLocation();
    $options = FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS;
    $this->fileSystem->prepareDirectory($destination, $options);

    return $destination;
  }

}
