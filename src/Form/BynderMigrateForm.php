<?php

namespace Drupal\bynder_migrate\Form;

use Drupal\bynder\BynderApiInterface;
use Drupal\bynder\Exception\UnableToConnectException;
use Drupal\bynder\Plugin\Field\FieldType\BynderMetadataItem;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
use Drupal\entity_usage\EntityUsageInterface;
use Drupal\media\Entity\Media;
use Drupal\media\MediaInterface;
use GuzzleHttp\Exception\RequestException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Bynder migration form.
 */
class BynderMigrateForm extends FormBase {

  /**
   * @var \Drupal\entity_usage\EntityUsageInterface|null
   */
  protected ?EntityUsageInterface $entityUsage = NULL;

  /**
   * Constructs a BynderMediaUsage class instance.
   *
   * @param \Drupal\bynder\BynderApiInterface $bynder
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   * @param \Drupal\Core\StreamWrapper\StreamWrapperManagerInterface $streamWrapperManager
   * @param \Drupal\Core\File\FileSystemInterface $fileSystem
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   * @param \Symfony\Component\HttpFoundation\RequestStack $requestStack
   */
  public function __construct(
    protected BynderApiInterface $bynder,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected StreamWrapperManagerInterface $streamWrapperManager,
    protected FileSystemInterface $fileSystem,
    protected ModuleHandlerInterface $moduleHandler,
    RequestStack $requestStack,
  ) {
    $this->requestStack = $requestStack;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $object = new static(
      $container->get('bynder_api'),
      $container->get('entity_type.manager'),
      $container->get('stream_wrapper_manager'),
      $container->get('file_system'),
      $container->get('module_handler'),
      $container->get('request_stack'),
    );

    if ($container->has('entity_usage.usage')) {
      $object->setEntityUsage($container->get('entity_usage.usage'));
    }
    return $object;
  }

  public function setEntityUsage(EntityUsageInterface $entity_usage) {
    $this->entityUsage = $entity_usage;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'bynder_migrate_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, MediaInterface $media = NULL) {
    if ($media->getSource()->getPluginId() !== 'image') {
      return [
        '#type' => 'markup',
        '#markup' => $this->t('Only image migration is supported at the moment.'),
      ];
    }

    if (!$this->moduleHandler->moduleExists('entity_usage')) {
      $this->messenger()->addWarning('The entity_usage module is not installed. Media will only be uploaded, but not replaced in the host entities.');
    }

    if ($form_state->getValue('errors')) {
      $form['actions']['submit']['#access'] = FALSE;
      return $form;
    }

    // Require oAuth authorization if we don't have a valid access token yet.
    if (!$this->bynder->hasAccessToken() || ($this->requestStack->getCurrentRequest()->getMethod() == 'POST' && $this->requestStack->getCurrentRequest()->request->get('op') == 'Reload after submit' && $form_state->isProcessingInput() === NULL)) {
      // @todo Not sure if this is needed.
      $form_state->setValue('errors', TRUE);
      $form['message'] = [
        '#markup' => $this->t(
          'You need to <a href="#login" class="oauth-link">log into Bynder</a> before importing assets.'
        ),
        '#prefix' => '<p>',
        '#suffix' => '</p>',
      ];
      $form['reload'] = [
        '#type' => 'button',
        '#value' => 'Reload after submit',
        '#attached' => ['library' => ['bynder/oauth']],
        '#attributes' => ['class' => ['oauth-reload', 'visually-hidden']],
      ];
      return $form;
    }

    $brand_options = [];
    try {
      foreach ($this->bynder->getBrands() as $brand) {
        $brand_options[$brand['id']] = $brand['name'];
        foreach ($brand['subBrands'] as $sub_brand) {
          $brand_options[$sub_brand['id']] = '- ' . $sub_brand['name'];
        }
      }
    }
    catch (RequestException $e) {
      watchdog_exception('bynder', $e);
      (new UnableToConnectException())->displayMessage();
    }

    $form['confirm'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('Do you want to upload this media to Bynder?'),
    ];

    $form['brand'] = [
      '#type' => 'select',
      '#title' => $this->t('Brand'),
      '#required' => TRUE,
      '#options' => $brand_options,
    ];
    $form['media_id'] = [
      '#type' => 'value',
      '#value' => $media->id(),
    ];

    $form['upload'] = [
      '#type' => 'submit',
      '#value' => $this->t('Upload'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $media_id = $form_state->getValue('media_id');
    $media = $this->entityTypeManager->getStorage('media')->load($media_id);

    if (!$media) {
      $this->messenger()->addError('There was an error while uploading this media.');
      return;
    }

    $upload_result = $this->uploadToBynder($media, $form_state->getValue('brand'));

    if (!isset($upload_result['success']) || $upload_result['success'] !== TRUE) {
      $this->messenger()->addError('There was an error while uploading this media. (@error)', ['@error' => json_encode($upload_result)]);
      return;
    }

    $uuid = $upload_result['mediaid'];
    // @todo we probably need something else. The problem is that media info
    // seems not to be there right after the upload.

    $max_attempts = 5;
    $attempt = 0;
    $media_info = [];
    do {
      try {
        $attempt++;
        $media_info = $this->bynder->getMediaInfo($uuid);
      }
      catch (\Exception $e) {
        // Wait before retrying
        sleep(3);
      }
    } while ($attempt < $max_attempts);

    if (empty($media_info)) {
      $this->messenger()->addError('The file was uploaded, but there was an error while trying to get Bynder media info. As a result, local Bynder media was not created');
      return ;
    }

    // @todo Hardcoded for now.
    $bynder_media = Media::create([
      'bundle' => 'bynder',
      'field_bynder_id' => $uuid,
      BynderMetadataItem::METADATA_FIELD_NAME => $media_info,
    ]);
    $bynder_media->save();

    $this->messenger()->addStatus('Successfully uploaded media.');

    if ($this->entityUsage) {
      foreach ($this->entityUsage->listSources($media) as $source_type => $entity_usages) {
        $storage = $this->entityTypeManager->getStorage($source_type);
        foreach ($entity_usages as $entity_id => $entity_usage) {
          /** @var \Drupal\Core\Entity\ContentEntityInterface $entity */
          $entity = $storage->load($entity_id);

          // In case of inconsistent usage data skip.
          if (!$entity) {
            continue;
          }

          foreach ($entity_usage as $field_usage) {
            foreach ($entity->get($field_usage['field_name']) as $item) {
              // Check that that field exists and is of the correct type.
              if (!$item || $item->getPluginId() != 'field_item:entity_reference') {
                continue;
              }

              // We do not support translatable fields.
              if ($item->getFieldDefinition()->isTranslatable()) {
                continue;
              }

              if ($item->target_id == $media->id()) {
                $item->entity = $bynder_media;
                $save = $save ?? TRUE;
              }
            }
          }

          if (isset($save)) {
            $entity->save();
          }
        }
      }
    }

    $form_state->setRedirectUrl($bynder_media->toUrl());
  }

  /**
   * Uploads to Bynder.
   *
   * @param \Drupal\media\MediaInterface $media
   * @param $brand
   *
   * @return array|void
   */
  protected function uploadToBynder(MediaInterface $media, $brand) {
    try {
      $fid = $media->getSource()->getSourceFieldValue($media);
      /** @var \Drupal\file\FileInterface $file */
      $file = $this->entityTypeManager->getStorage('file')->load($fid);

      if (!$file) {
        throw new \Exception('File not found: ' . $fid);
      }

      $data = [
        'filePath' => $this->fileSystem->realpath($file->getFileUri()),
        'brandId' => $brand,
        'name' => $media->getName(),
      ];

//      if ($description) {
//        $data['description'] = $description;
//      }

//      if ($tags) {
//        $data['tags'] = implode(',', $tags);
//      }

//      if ($metaproperty_options) {
//        foreach ($metaproperty_options as $metaproperty => $options) {
//          $data['metaproperty.' . $metaproperty] = implode(',', $options);
//        }
//      }

//      if (isset($context['results']['accessRequestId'])) {
//        $data['accessRequestId'] = $context['results']['accessRequestId'];
//      }

      return $this->bynder->uploadFileAsync($data);
    }
    catch (\Exception $e) {
      $this->messenger()->addError('There was an error uploading the file');
    }

  }

}
