<?php

namespace lenz\craft\chunkedUploads\assets;

use craft\helpers\Json;
use craft\web\assets\fileupload\FileUploadAsset;
use lenz\craft\chunkedUploads\Plugin;
use yii\web\AssetBundle;

/**
 * Class FileUploadPatch
 */
class FileUploadPatch extends AssetBundle
{
  /**
   * @inheritdoc
   */
  public function init(): void {
    $settings = Plugin::getInstance()->getSettings();

    $this->sourcePath = __DIR__ . '/resources';
    $this->depends    = [FileUploadAsset::class];
    $this->js         = ['fileupload.patch.js'];
    $this->jsOptions  = [
      'data-settings' => Json::encode([
        'chunkSize' => $settings->chunkSize,
        'maxSizes'  => $settings->getMaxUploadSizes(),
      ]),
    ];

    parent::init();
  }
}
