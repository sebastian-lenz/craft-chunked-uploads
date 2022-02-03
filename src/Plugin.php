<?php

namespace lenz\craft\chunkedUploads;

use Craft;
use craft\awss3\S3Client;
use craft\awss3\Volume as S3Volume;
use craft\base\Model;
use craft\controllers\AssetsController;
use craft\fields\Assets as AssetsField;
use craft\models\VolumeFolder;
use craft\web\assets\fileupload\FileUploadAsset;
use craft\web\Request;
use craft\web\UploadedFile;
use Exception;
use InvalidArgumentException;
use lenz\craft\chunkedUploads\assets\FileUploadPatch;
use yii\base\Event;
use yii\base\InvalidConfigException;
use yii\web\BadRequestHttpException;
use yii\web\View;

/**
 * Class Plugin
 * @method Settings getSettings()
 */
class Plugin extends \craft\base\Plugin
{
  /**
   * @inheritDoc
   */
  public $hasCpSettings = true;


  /**
   * Plugin init.
   *
   * @param $id
   * @param null $parent
   * @param array $config
   */
  public function init() {
    parent::init();

    Event::on(AssetsController::class, AssetsController::EVENT_BEFORE_ACTION, [$this, 'onBeforeAction']);
    Event::on(View::class, View::EVENT_END_BODY, [$this, 'onViewEndBody']);
  }

  /**
   * @throws Exception
   */
  public function onBeforeAction() {
    $request = Craft::$app->getRequest();

    if (
      $request->getIsPost() &&
      $request->getHeaders()->has('content-disposition') &&
      $request->getHeaders()->has('content-range') &&
      ($upload = UploadedFile::getInstanceByName('assets-upload'))
    ) {
      $chunk = $this->createChunk($request, $upload);
      if (!$chunk->process()) die;
    }
  }

  /**
   * @param Event $event
   * @throws InvalidConfigException
   */
  public function onViewEndBody(Event $event) {
    /** @var View $view */
    $view = $event->sender;
    if (array_key_exists(FileUploadAsset::class, $view->assetBundles)) {
      $view->registerAssetBundle(FileUploadPatch::class);
    }
  }

  /**
   * @inheritDoc
   * @throws Exception
   */
  protected function settingsHtml() {
    return Craft::$app->view->renderTemplate(
      'chunked-uploads/_settings.twig',
      [
        'settings' => $this->getSettings(),
      ]
    );
  }


  // Protected methods
  // -----------------

  /**
   * @return Model|null
   */
  protected function createSettingsModel() {
    return new Settings();
  }


  /**
   * @param Request $request
   * @return BaseChunkHandler
   * @throws BadRequestHttpException
   */
  protected function createChunk(Request $request, UploadedFile $upload)
  {
    if (class_exists(S3Client::class) and class_exists(S3Volume::class)) {
      $folder = self::getFolder($request);
      $volume = $folder->getVolume();

      if ($volume instanceof S3Volume) {
        return new BucketChunkHandler([
          'request' => $request,
          'upload' => $upload,
          'volume' => $volume,
        ]);
      }
    }

    return new LocalChunkHandler([
      'request' => $request,
      'upload' => $upload,
    ]);
  }


  /**
   *
   * @return VolumeFolder
   * @throws InvalidConfigException
   * @throws InvalidArgumentException
   */
  protected static function getFolder(Request $request)
  {
    $folderId = $request->getBodyParam('folderId');
    $fieldId = $request->getBodyParam('fieldId');

    if (!$folderId && !$fieldId) {
      throw new BadRequestHttpException('No target destination provided for uploading');
    }

    if (empty($folderId)) {
      $field = Craft::$app->getFields()->getFieldById((int)$fieldId);

      if (!($field instanceof AssetsField)) {
        throw new BadRequestHttpException('The field provided is not an Assets field');
      }

      if ($elementId = $request->getBodyParam('elementId')) {
        $siteId = $request->getBodyParam('siteId') ?: null;
        $element = Craft::$app->getElements()->getElementById($elementId, null, $siteId);
      } else {
        $element = null;
      }

      $folderId = $field->resolveDynamicPathToFolderId($element);
    }

    if (empty($folderId)) {
      throw new BadRequestHttpException('The target destination provided for uploading is not valid');
    }

    $folder = Craft::$app->getAssets()->findFolder(['id' => $folderId]);

    if (!$folder) {
      throw new BadRequestHttpException('The target folder provided for uploading is not valid');
    }

    return $folder;
  }
}
