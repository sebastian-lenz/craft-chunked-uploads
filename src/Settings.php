<?php

namespace lenz\craft\chunkedUploads;

use Craft;
use craft\base\Model;
use craft\helpers\ArrayHelper;
use craft\models\VolumeFolder;
use Throwable;
use yii\behaviors\AttributeTypecastBehavior;

/**
 * Class Settings
 */
class Settings extends Model
{
  /**
   * @var int
   */
  public int $chunkSize = 1;

  /**
   * @var array
   */
  public array $folders = [];

  /**
   * @var int
   */
  public int $maxUploadSize = 0;

  /**
   * @var bool
   */
  private bool $_useUris;

  /**
   * @var int[]
   */
  private array $_visibleFolderIds;

  /**
   * List of known folder options
   */
  const FOLDER_OPTIONS = ['maxUploadSize', 'maxImageWidth', 'maxImageHeight'];


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
   * @param VolumeFolder[] $folders
   * @return VolumeFolder[]
   * @noinspection PhpUnused (Used in settings template)
   */
  public function filterVisibleChildren(array $folders): array {
    $visibleIds = $this->getVisibleFolderIds();
    return array_filter($folders, function(VolumeFolder $folder) use ($visibleIds) {
      return in_array($folder->id, $visibleIds);
    });
  }

  /**
   * @param int $id
   * @return array
   * @noinspection PhpUnused (Template method)
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
  public function setAttributes($values, $safeOnly = true): void {
    if (isset($values['folderOptions'])) {
      $this->setFolderOptions($values['folderOptions']);
      unset($values['folderOptions']);
    }

    parent::setAttributes($values, $safeOnly);
  }

  /**
   * @param mixed $folders
   */
  public function setFolderOptions(mixed $folders): void {
    $result = [];
    if (!is_array($folders)) {
      $folders = [];
    }

    foreach ($folders as $id => $options) {
      $uri = $this->toUri($id, true);
      if (is_null($uri)) {
        continue;
      }

      $folderOptions = $this->toFolderOptions($options);
      if (!empty($folderOptions)) {
        $result[$uri] = $folderOptions;
      }

      $create = $this->toCreateOptions($uri, $options);
      if (!empty($create)) {
        $result[$create['uri']] = $create['options'];
      }
    }

    $this->_useUris = true;
    $this->folders = $result;
  }

  /**
   * @inheritDoc
   */
  public function toArray(array $fields = [], array $expand = [], $recursive = true): array {
    $result = parent::toArray($fields, $expand, $recursive);
    if (array_key_exists('folders', $result)) {
      $result['folders'] = (object)$result['folders'];
    }

    return $result;
  }


  // Protected methods
  // -----------------

  /**
   * @param VolumeFolder $folder
   * @return string|null
   */
  protected function createUri(VolumeFolder $folder): ?string {
    try {
      $result = $folder->getVolume()->uid;
    } catch (Throwable) {
      return null;
    }

    return $result . '/' . trim(is_null($folder->path) ? '' : $folder->path, '/');
  }

  /**
   * @param string $uriOrId
   * @param bool $forceUri
   * @return VolumeFolder|null
   */
  protected function fromUri(string $uriOrId, bool $forceUri = false): ?VolumeFolder {
    if (!$this->useUris() && !$forceUri) {
      return Craft::$app->assets->getFolderById($uriOrId);
    }

    $parts = explode('/', $uriOrId);
    $volume = Craft::$app->volumes->getVolumeByUid(array_shift($parts));
    $folder = $volume ? Craft::$app->assets->getRootFolderByVolumeId($volume->id) : null;

    while ($folder && count($parts)) {
      $part = array_shift($parts);
      $folder = ArrayHelper::firstWhere($folder->getChildren(), function(VolumeFolder $child) use ($part) {
        return $child->name === $part;
      });
    }

    return $folder;
  }

  /**
   * @return int[]
   */
  protected function getVisibleFolderIds(): array {
    if (isset($this->_visibleFolderIds)) {
      return $this->_visibleFolderIds;
    }

    $result = [];
    foreach (array_keys($this->folders) as $uriOrId) {
      $folder = $this->fromUri($uriOrId);
      while ($folder) {
        if (in_array($folder->id, $result)) break;
        $result[] = $folder->id;
        $folder = $folder->getParent();
      }
    }

    $this->_visibleFolderIds = $result;
    return $result;
  }

  /**
   * @param VolumeFolder[] $folders
   * @param int $maxSize
   * @param array $result
   */
  protected function getMaxUploadSizesRecursive(array $folders, int $maxSize, array &$result): void {
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
   * @param string $uri
   * @param array $options
   * @return array|null
   */
  protected function toCreateOptions(string $uri, array $options): ?array {
    if (!array_key_exists('create', $options) || empty($options['create']['path'])) {
      return null;
    }

    $folderOptions = $this->toFolderOptions($options['create']);
    $segments = explode('/', $uri);
    $uri = reset($segments) . '/' . trim($options['create']['path'], '/');
    $folder = self::fromUri($uri, true);

    return is_null($folder) || is_null($folderOptions)
      ? null
      : ['uri' => $uri, 'options' => $folderOptions];
  }

  /**
   * @param array $options
   * @return array|null
   */
  protected function toFolderOptions(array $options): ?array {
    $result = [];
    $hasResult = false;

    foreach ($options as $key => $value) {
      if (!in_array($key, self::FOLDER_OPTIONS)) {
        continue;
      }

      $hasResult = $hasResult || is_numeric($value);
      $result[$key] = is_numeric($value)
        ? intval($value)
        : null;
    }

    return $hasResult ? $result : null;
  }

  /**
   * @param int|VolumeFolder $folderOrId
   * @param bool $forceUri
   * @return string|null
   */
  protected function toUri(VolumeFolder|int $folderOrId, bool $forceUri = false): ?string {
    $folder = $folderOrId instanceof VolumeFolder
      ? $folderOrId
      : Craft::$app->assets->getFolderById($folderOrId);

    if (!($folder instanceof VolumeFolder)) {
      return null;
    }

    return $this->useUris() || $forceUri
      ? $this->createUri($folder)
      : $folder->id;
  }

  /**
   * @return bool
   */
  protected function useUris(): bool {
    if (!isset($this->_useUris)) {
      $this->_useUris = ArrayHelper::isAssociative($this->folders);
    }

    return $this->_useUris;
  }
}
