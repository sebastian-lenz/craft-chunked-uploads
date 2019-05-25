<?php

namespace lenz\craft\chunkedUploads;

use Craft;
use craft\base\Model;
use craft\models\VolumeFolder;
use yii\behaviors\AttributeTypecastBehavior;

/**
 * Class Settings
 */
class Settings extends Model
{
  /**
   * @var int
   */
  public $chunkSize = 1;

  /**
   * @var array
   */
  public $folders = [];

  /**
   * @var int
   */
  public $maxUploadSize = 0;


  /**
   * @inheritDoc
   */
  public function behaviors() {
    return [
      'typecast' => [
        'class' => AttributeTypecastBehavior::class,
      ],
    ];
  }

  /**
   * @param int $id
   * @return array
   */
  public function getFolderOptions($id) {
    return array_key_exists($id, $this->folders)
      ? $this->folders[$id]
      : [];
  }

  /**
   * @return VolumeFolder[]
   */
  public function getFolderTree() {
    return Craft::$app->assets->getFolderTreeByVolumeIds(
      Craft::$app->getVolumes()->getAllVolumeIds()
    );
  }

  /**
   * @param int $folderId
   * @return array
   */
  public function getMaxImageDimension($folderId) {
    $folder = Craft::$app->getAssets()->getFolderById($folderId);
    while ($folder) {
      if (array_key_exists($folder->id, $this->folders)) {
        $options = $this->folders[$folder->id];
        return [
          isset($options['maxImageWidth']) ? $options['maxImageWidth'] : null,
          isset($options['maxImageHeight']) ? $options['maxImageHeight'] : null,
        ];
      }

      $folder = $folder->getParent();
    }

    return [null, null];
  }

  /**
   * @return array
   */
  public function getMaxUploadSizes() {
    $result = [];
    $this->getMaxUploadSizesRecursive(
      $this->getFolderTree(),
      $this->maxUploadSize,
      $result
    );

    return $result;
  }

  /**
   * @return array
   */
  public function rules() {
    return [
      [['chunkSize'], 'integer', 'min' => 1],
      [['maxUploadSize'], 'integer', 'min' => 0],
      [['chunkSize', 'maxUploadSize'], 'required'],
    ];
  }

  /**
   * @param array $values
   * @param bool $safeOnly
   */
  public function setAttributes($values, $safeOnly = true) {
    if (isset($values['folderOptions'])) {
      $this->setFolderOptions($values['folderOptions']);
      unset($values['folderOptions']);
    }

    parent::setAttributes($values, $safeOnly);
  }

  /**
   * @param mixed $folders
   */
  public function setFolderOptions($folders) {
    if (!is_array($folders)) {
      $folders = [];
    }

    foreach ($folders as $id => &$options) {
      $hasOptions = false;
      foreach ($options as $key => $value) {
        if (is_numeric($value)) {
          $hasOptions = true;
          $options[$key] = intval($value);
        } else {
          $options[$key] = null;
        }
      }

      if (!$hasOptions) {
        unset($folders[$id]);
      }
    }

    $this->folders = $folders;
  }


  // Protected methods
  // -----------------

  /**
   * @param VolumeFolder[] $folders
   * @param int $maxSize
   * @param array $result
   */
  protected function getMaxUploadSizesRecursive($folders, $maxSize, &$result) {
    foreach ($folders as $folder) {
      $folderMaxSize = $maxSize;
      if (
        array_key_exists($folder->id, $this->folders) &&
        array_key_exists('maxUploadSize', $this->folders[$folder->id]) &&
        is_numeric($this->folders[$folder->id]['maxUploadSize'])
      ) {
        $folderMaxSize = $this->folders[$folder->id]['maxUploadSize'];
      }

      $result[$folder->id] = $folderMaxSize;

      $children = $folder->getChildren();
      if (count($children) > 0) {
        $this->getMaxUploadSizesRecursive($children, $folderMaxSize, $result);
      }
    }
  }
}
