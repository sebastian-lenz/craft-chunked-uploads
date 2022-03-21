<?php

namespace lenz\craft\chunkedUploads;

use Craft;
use craft\base\Model;
use craft\helpers\ArrayHelper;
use craft\models\VolumeFolder;
use Throwable;
use yii\base\InvalidConfigException;
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
  public function behaviors(): array {
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
  public function getFolderOptions(int $id): array {
    $uri = $this->toUri($id);
    return $uri && array_key_exists($uri, $this->folders)
      ? $this->folders[$uri]
      : [];
  }

  /**
   * @return VolumeFolder[]
   */
  public function getFolderTree(): array {
    return Craft::$app->assets->getFolderTreeByVolumeIds(
      Craft::$app->getVolumes()->getAllVolumeIds()
    );
  }

  /**
   * @param int $folderId
   * @return array
   * @noinspection PhpUnused (Used in settings template)
   */
  public function getMaxImageDimension(int $folderId): array {
    $folder = Craft::$app->getAssets()->getFolderById($folderId);
    while ($folder) {
      $uri = $this->toUri($folder);
      if (array_key_exists($uri, $this->folders)) {
        $options = $this->folders[$uri];
        return [
          $options['maxImageWidth'] ?? null,
          $options['maxImageHeight'] ?? null,
        ];
      }

      $folder = $folder->getParent();
    }

    return [null, null];
  }

  /**
   * @return array
   * @noinspection PhpUnused (Used in settings template)
   */
  public function getMaxUploadSizes(): array {
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
  public function rules(): array {
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
    $result = [];
    if (!is_array($folders)) {
      $folders = [];
    }

    foreach ($folders as $id => $options) {
      $hasOptions = false;
      foreach ($options as $key => $value) {
        if (is_numeric($value)) {
          $hasOptions = true;
          $options[$key] = intval($value);
        } else {
          $options[$key] = null;
        }
      }

      if ($hasOptions) {
        $uri = $this->toUri($id, true);
        if (!is_null($uri)) {
          $result[$uri] = $options;
        }
      }
    }

    $this->folders = $result;
  }

  /**
   * @inheritDoc
   * @noinspection PhpMissingReturnTypeInspection
   */
  public function toArray(array $fields = [], array $expand = [], $recursive = true) {
    $result = parent::toArray($fields, $expand, $recursive);
    if (array_key_exists('folders', $result)) {
      $result['folders'] = (object)$result['folders'];
    }

    return $result;
  }


  // Protected methods
  // -----------------

  /**
   * @param VolumeFolder[] $folders
   * @param int $maxSize
   * @param array $result
   */
  protected function getMaxUploadSizesRecursive(array $folders, int $maxSize, array &$result) {
    foreach ($folders as $folder) {
      $folderMaxSize = $maxSize;
      $uri = $this->toUri($folder);
      if (
        array_key_exists($uri, $this->folders) &&
        array_key_exists('maxUploadSize', $this->folders[$uri]) &&
        is_numeric($this->folders[$uri]['maxUploadSize'])
      ) {
        $folderMaxSize = $this->folders[$uri]['maxUploadSize'];
      }

      $result[$folder->id] = $folderMaxSize;

      $children = $folder->getChildren();
      if (count($children) > 0) {
        $this->getMaxUploadSizesRecursive($children, $folderMaxSize, $result);
      }
    }
  }

  /**
   * @param int|VolumeFolder $folderOrId
   * @param bool $forceUri
   * @return string|null
   */
  protected function toUri($folderOrId, bool $forceUri = false): ?string {
    static $useUris;
    if (!isset($useUris)) {
      $useUris = ArrayHelper::isAssociative($this->folders);
    }

    $folder = $folderOrId instanceof VolumeFolder
      ? $folderOrId
      : Craft::$app->assets->getFolderById($folderOrId);

    if (!($folder instanceof VolumeFolder)) {
      return null;
    }

    return $useUris || $forceUri
      ? $this->createUri($folder)
      : $folder->id;
  }

  /**
   * @param VolumeFolder $folder
   * @return string|null
   */
  protected function createUri(VolumeFolder $folder): ?string {
    try {
      $result = $folder->getVolume()->uid;
    } catch (Throwable $error) {
      return null;
    }

    return $result . '/' . trim(is_null($folder->path) ? '' : $folder->path, '/');
  }
}
