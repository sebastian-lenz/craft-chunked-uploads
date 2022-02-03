<?php

namespace lenz\craft\chunkedUploads;

use Craft;
use craft\awss3\S3Client;
use craft\awss3\Volume as S3Volume;
use craft\base\Model;
use craft\base\VolumeInterface;
use craft\controllers\AssetsController;
use craft\fields\Assets as AssetsField;
use craft\models\VolumeFolder;
use craft\web\assets\fileupload\FileUploadAsset;
use craft\web\Request;
use craft\web\UploadedFile;
use Exception;
use InvalidArgumentException;
use lenz\craft\chunkedUploads\assets\FileUploadPatch;
use lenz\craft\chunkedUploads\handlers\BucketChunkHandler;
use lenz\craft\chunkedUploads\handlers\LocalChunkHandler;
use yii\base\Event;
use yii\base\InvalidConfigException;
use yii\base\Response;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
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
      $chunk = $this->createHandler($request, $upload);
      $res = $chunk->process();

      // If the chunk handler returns a response, return it.
      if ($res instanceof Response) {
        $res->send();
        exit;
      }

      // If incomplete, just die - we don't want the assets controller
      // interfering - yet.
      if ($res === false) {
        exit;
      }

      // Upload finished - continue the asset creation process.
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
  protected function createHandler(Request $request, UploadedFile $upload)
  {
    $folder = self::getFolder($request);
    $volume = $folder->getVolume();

    self::checkFolderPermissions($volume, $folder);

    // AWS multipart uploads.
    if (
      class_exists(S3Client::class)
      and class_exists(S3Volume::class)
      and ($volume instanceof S3Volume)
    ) {
      return new BucketChunkHandler([
        'request' => $request,
        'upload' => $upload,
        'folder' => $folder,
      ]);
    }

    // Local file chunked uploads.
    return new LocalChunkHandler([
      'request' => $request,
      'upload' => $upload,
    ]);
  }


  /**
   * Abbreviated from AssetsController::requireVolumePermissionByFolder()
   *
   * @param VolumeFolder $folder
   * @return void
   * @throws ForbiddenHttpException
   */
  protected static function checkFolderPermissions(VolumeInterface $volume, VolumeFolder $folder)
  {
    if (!$folder->volumeId) {
      $userTemporaryFolder = Craft::$app->getAssets()->getUserTemporaryUploadFolder();

      // Skip permission check only if it's the user's temporary folder
      if ($userTemporaryFolder->id == $folder->id) {
        return;
      }
    }

    if (!Craft::$app->getUser()->checkPermission('saveAssetInVolume: ' . $volume->uid)) {
      throw new ForbiddenHttpException('User is not permitted to perform this action');
    }
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
