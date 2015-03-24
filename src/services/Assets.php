<?php
/**
 * @link http://buildwithcraft.com/
 * @copyright Copyright (c) 2013 Pixel & Tonic, Inc.
 * @license http://buildwithcraft.com/license
 */

namespace craft\app\services;

use Craft;
use craft\app\assetsourcetypes\BaseAssetSourceType;
use craft\app\assetsourcetypes\Temp;
use craft\app\db\Query;
use craft\app\elements\db\AssetQuery;
use craft\app\enums\AssetConflictResolution;
use craft\app\errors\Exception;
use craft\app\events\AssetEvent;
use craft\app\helpers\AssetsHelper;
use craft\app\helpers\DbHelper;
use craft\app\helpers\ImageHelper;
use craft\app\helpers\IOHelper;
use craft\app\helpers\StringHelper;
use craft\app\helpers\UrlHelper;
use craft\app\elements\Asset;
use craft\app\models\AssetFolder as AssetFolderModel;
use craft\app\models\AssetOperationResponse as AssetOperationResponseModel;
use craft\app\models\FolderCriteria as FolderCriteriaModel;
use craft\app\elements\User;
use craft\app\records\AssetFile as AssetFileRecord;
use craft\app\records\AssetFolder as AssetFolderRecord;
use craft\app\tasks\GeneratePendingTransforms;
use yii\base\Component;


/**
 * Class Assets service.
 *
 * An instance of the Assets service is globally accessible in Craft via [[Application::assets `Craft::$app->assets`]].
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 3.0
 */
class Assets extends Component
{
	// Constants
	// =========================================================================

	/**
     * @event AssetEvent The event that is triggered before an asset is uploaded.
     *
     * You may set [[AssetEvent::performAction]] to `false` to prevent the asset from getting uploaded.
     */
    const EVENT_BEFORE_UPLOAD_ASSET = 'beforeUploadAsset';

	/**
     * @event AssetEvent The event that is triggered before an asset is saved.
     *
     * You may set [[AssetEvent::performAction]] to `false` to prevent the asset from getting saved.
     */
    const EVENT_BEFORE_SAVE_ASSET = 'beforeSaveAsset';

	/**
     * @event AssetEvent The event that is triggered after an asset is saved.
     */
    const EVENT_AFTER_SAVE_ASSET = 'afterSaveAsset';

	/**
     * @event AssetEvent The event that is triggered before an asset is deleted.
     */
    const EVENT_BEFORE_DELETE_ASSET = 'beforeDeleteAsset';

	/**
     * @event AssetEvent The event that is triggered after an asset is deleted.
     */
    const EVENT_AFTER_DELETE_ASSET = 'afterDeleteAsset';

	/**
     * @event AssetEvent The event that is triggered before an asset’s file is replaced.
     *
     * You may set [[AssetEvent::performAction]] to `false` to prevent the file from getting replaced.
     */
    const EVENT_BEFORE_REPLACE_FILE = 'beforeReplaceFile';

	/**
     * @event AssetEvent The event that is triggered after an asset’s file is replaced.
     */
    const EVENT_AFTER_REPLACE_FILE = 'afterReplaceFile';

	// Properties
	// =========================================================================

	/**
	 * @var
	 */
	private $_foldersById;

	/**
	 * A flag that designates that a file merge is in progress and name uniqueness
	 * should not be enforced.
	 *
	 * @var bool
	 */
	private $_mergeInProgress = false;

	// Public Methods
	// =========================================================================

	/**
	 * Returns all top-level files in a source.
	 *
	 * @param int         $sourceId
	 * @param string|null $indexBy
	 *
	 * @return array
	 */
	public function getFilesBySourceId($sourceId, $indexBy = null)
	{
		return Asset::find()
			->sourceId($sourceId)
			->indexBy($indexBy)
			->all();
	}

	/**
	 * Returns a file by its ID.
	 *
	 * @param             $fileId
	 * @param string|null $localeId
	 *
	 * @return Asset|null
	 */
	public function getFileById($fileId, $localeId = null)
	{
		return Craft::$app->elements->getElementById($fileId, Asset::className(), $localeId);
	}

	/**
	 * Finds the first file that matches the given criteria.
	 *
	 * @param mixed $criteria
	 *
	 * @return Asset|null
	 */
	public function findFile($criteria = null)
	{
		if ($criteria instanceof AssetQuery)
		{
			$query = $criteria;
		}
		else
		{
			$query = Asset::find()->configure($criteria);
		}

		if (is_string($query->filename))
		{
			// Backslash-escape any commas in a given string.
			$query->filename = DbHelper::escapeParam($query->filename);
		}

		return $query->one();
	}

	/**
	 * Gets the total number of files that match a given criteria.
	 *
	 * @param mixed $criteria
	 *
	 * @return int
	 */
	public function getTotalFiles($criteria = null)
	{
		if ($criteria instanceof AssetQuery)
		{
			$query = $criteria;
		}
		else
		{
			$query = Asset::find()->configure($criteria);
		}

		return $query->count();
	}

	/**
	 * Saves the record for an asset.
	 *
	 * @param Asset $file
	 *
	 * @throws Exception
	 * @throws \Exception
	 * @return bool
	 */
	public function storeFile(Asset $file)
	{
		$isNewFile = !$file->id;

		if (!$isNewFile)
		{
			$fileRecord = AssetFileRecord::findOne($file->id);

			if (!$fileRecord)
			{
				throw new Exception(Craft::t('app', 'No asset exists with the ID “{id}”.', ['id' => $file->id]));
			}
		}
		else
		{
			$fileRecord = new AssetFileRecord();
		}

		$fileRecord->sourceId     = $file->sourceId;
		$fileRecord->folderId     = $file->folderId;
		$fileRecord->filename     = $file->filename;
		$fileRecord->kind         = $file->kind;
		$fileRecord->size         = $file->size;
		$fileRecord->width        = $file->width;
		$fileRecord->height       = $file->height;
		$fileRecord->dateModified = $file->dateModified;

		$fileRecord->validate();
		$file->addErrors($fileRecord->getErrors());

		if ($file->hasErrors())
		{
			return false;
		}

		if ($isNewFile && !$file->getContent()->title)
		{
			// Give it a default title based on the file name
			$file->getContent()->title = str_replace('_', ' ', IOHelper::getFilename($file->filename, false));
		}

		$transaction = Craft::$app->getDb()->getTransaction() === null ? Craft::$app->getDb()->beginTransaction() : null;

		try
		{
			// Fire a 'beforeSaveAsset' event
			$event = new AssetEvent([
				'asset' => $file
			]);

			$this->trigger(static::EVENT_BEFORE_SAVE_ASSET, $event);

			// Is the event giving us the go-ahead?
			if ($event->performAction)
			{
				// Save the element
				$success = Craft::$app->elements->saveElement($file, false);

				// If it didn't work, rollback the transaction in case something changed in onBeforeSaveAsset
				if (!$success)
				{
					if ($transaction !== null)
					{
						$transaction->rollback();
					}

					return false;
				}

				// Now that we have an element ID, save it on the other stuff
				if ($isNewFile)
				{
					$fileRecord->id = $file->id;
				}

				// Save the file row
				$fileRecord->save(false);
			}
			else
			{
				$success = false;
			}

			// Commit the transaction regardless of whether we saved the asset, in case something changed
			// in onBeforeSaveAsset
			if ($transaction !== null)
			{
				$transaction->commit();
			}
		}
		catch (\Exception $e)
		{
			if ($transaction !== null)
			{
				$transaction->rollback();
			}

			throw $e;
		}

		if ($success)
		{
			// Fire an 'afterSaveAsset' event
			$this->trigger(static::EVENT_AFTER_SAVE_ASSET, new AssetEvent([
				'asset' => $file
			]));
		}

		return $success;
	}

	//  Folders
	// -------------------------------------------

	/**
	 * Store a folder by model and return the id.
	 *
	 * @param AssetFolderModel $folder
	 *
	 * @return mixed
	 */
	public function storeFolder(AssetFolderModel $folder)
	{
		if (empty($folder->id))
		{
			$record = new AssetFolderRecord();
		}
		else
		{
			$record = AssetFolderRecord::findOne($folder->id);
		}

		$record->parentId = $folder->parentId;
		$record->sourceId = $folder->sourceId;
		$record->name = $folder->name;
		$record->path = $folder->path;
		$record->save();

		return $record->id;
	}

	/**
	 * Get the folder tree for Assets by source ids
	 *
	 * @param $allowedSourceIds
	 *
	 * @return array
	 */
	public function getFolderTreeBySourceIds($allowedSourceIds)
	{
		if (empty($allowedSourceIds))
		{
			return [];
		}

		$folders = $this->findFolders(['sourceId' => $allowedSourceIds, 'order' => 'path']);
		$tree = $this->_getFolderTreeByFolders($folders);

		$sort = [];

		foreach ($tree as $topFolder)
		{
			$sort[] = Craft::$app->assetSources->getSourceById($topFolder->sourceId)->sortOrder;
		}

		array_multisort($sort, $tree);

		return $tree;
	}

	/**
	 * Get the users Folder model.
	 *
	 * @param User $userModel
	 *
	 * @throws Exception
	 * @return AssetFolderModel|null
	 */
	public function getUserFolder(User $userModel = null)
	{
		$sourceTopFolder = Craft::$app->assets->findFolder(['sourceId' => ':empty:', 'parentId' => ':empty:']);

		// Super unlikely, but would be very awkward if this happened without any contingency plans in place.
		if (!$sourceTopFolder)
		{
			$sourceTopFolder = new AssetFolderModel();
			$sourceTopFolder->name = Temp::sourceName;
			$sourceTopFolder->id = $this->storeFolder($sourceTopFolder);
		}

		if ($userModel)
		{
			$folderName = 'user_'.$userModel->id;
		}
		else
		{
			// A little obfuscation never hurt anyone
			$folderName = 'user_'.sha1(Craft::$app->getSession()->getSessionID());
		}

		$folderCriteria = new FolderCriteriaModel([
			'name' => $folderName,
			'parentId' => $sourceTopFolder->id
		]);

		$folder = $this->findFolder($folderCriteria);

		if (!$folder)
		{
			$folder = new AssetFolderModel();
			$folder->parentId = $sourceTopFolder->id;
			$folder->name = $folderName;
			$folder->id = $this->storeFolder($folder);
		}

		return $folder;
	}

	/**
	 * Get the folder tree for Assets by a folder id.
	 *
	 * @param $folderId
	 *
	 * @return array
	 */
	public function getFolderTreeByFolderId($folderId)
	{
		$folder = $this->getFolderById($folderId);

		if (is_null($folder))
		{
			return [];
		}

		return $this->_getFolderTreeByFolders([$folder]);
	}

	/**
	 * Create a folder by it's parent id and a folder name.
	 *
	 * @param $parentId
	 * @param $folderName
	 *
	 * @return AssetOperationResponseModel
	 */
	public function createFolder($parentId, $folderName)
	{
		try
		{
			$parentFolder = $this->getFolderById($parentId);

			if (empty($parentFolder))
			{
				throw new Exception(Craft::t('app', 'Can’t find the parent folder!'));
			}

			$source = Craft::$app->assetSources->getSourceTypeById($parentFolder->sourceId);
			$response = $source->createFolder($parentFolder, $folderName);
		}
		catch (Exception $exception)
		{
			$response = new AssetOperationResponseModel();
			$response->setError($exception->getMessage());
		}

		return $response;
	}

	/**
	 * Rename a folder by it's folder and a new name.
	 *
	 * @param $folderId
	 * @param $newName
	 *
	 * @throws Exception
	 * @return AssetOperationResponseModel
	 */
	public function renameFolder($folderId, $newName)
	{
		try
		{
			$folder = $this->getFolderById($folderId);

			if (empty($folder))
			{
				throw new Exception(Craft::t('app', 'Can’t find the folder to rename!'));
			}

			$source = Craft::$app->assetSources->getSourceTypeById($folder->sourceId);
			$response = $source->renameFolder($folder, AssetsHelper::cleanAssetName($newName, false));
		}
		catch (Exception $exception)
		{
			$response = new AssetOperationResponseModel();
			$response->setError($exception->getMessage());
		}

		return $response;
	}

	/**
	 * Move a folder.
	 *
	 * @param $folderId
	 * @param $newParentId
	 * @param $action
	 *
	 * @return AssetOperationResponseModel
	 */
	public function moveFolder($folderId, $newParentId, $action)
	{
		$folder = $this->getFolderById($folderId);
		$newParentFolder = $this->getFolderById($newParentId);

		try
		{
			if (!($folder && $newParentFolder))
			{
				$response = new AssetOperationResponseModel();
				$response->setError(Craft::t('app', 'Error moving folder - either source or target folders cannot be found'));
			}
			else
			{
				$newSourceType = Craft::$app->assetSources->getSourceTypeById($newParentFolder->sourceId);
				$response = $newSourceType->moveFolder($folder, $newParentFolder, !empty($action));
			}
		}
		catch (Exception $exception)
		{
			$response = new AssetOperationResponseModel();
			$response->setError($exception->getMessage());
		}

		return $response;
	}

	/**
	 * Deletes a folder by its ID.
	 *
	 * @param int $folderId
	 *
	 * @throws Exception
	 * @return AssetOperationResponseModel
	 */
	public function deleteFolderById($folderId)
	{
		try
		{
			$folder = $this->getFolderById($folderId);

			if (empty($folder))
			{
				throw new Exception(Craft::t('app', 'Can’t find the folder!'));
			}

			$source = Craft::$app->assetSources->getSourceTypeById($folder->sourceId);
			$response = $source->deleteFolder($folder);

		}
		catch (Exception $exception)
		{
			$response = new AssetOperationResponseModel();
			$response->setError($exception->getMessage());
		}

		return $response;
	}

	/**
	 * Returns a folder by its ID.
	 *
	 * @param int $folderId
	 *
	 * @return AssetFolderModel|null
	 */
	public function getFolderById($folderId)
	{
		if (!isset($this->_foldersById) || !array_key_exists($folderId, $this->_foldersById))
		{
			$result = $this->_createFolderQuery()
				->where('id = :id', [':id' => $folderId])
				->one();

			if ($result)
			{
				$folder = new AssetFolderModel($result);
			}
			else
			{
				$folder = null;
			}

			$this->_foldersById[$folderId] = $folder;
		}

		return $this->_foldersById[$folderId];
	}

	/**
	 * Finds folders that match a given criteria.
	 *
	 * @param mixed $criteria
	 *
	 * @return array
	 */
	public function findFolders($criteria = null)
	{
		if (!($criteria instanceof FolderCriteriaModel))
		{
			$criteria = new FolderCriteriaModel($criteria);
		}

		$query = (new Query())
			->select('f.*')
			->from('{{%assetfolders}} f');

		$this->_applyFolderConditions($query, $criteria);

		if ($criteria->order)
		{
			$query->orderBy($criteria->order);
		}

		if ($criteria->offset)
		{
			$query->offset($criteria->offset);
		}

		if ($criteria->limit)
		{
			$query->limit($criteria->limit);
		}

		$results = $query->all();
		$folders = [];

		foreach ($results as $result)
		{
			$folder = AssetFolderModel::create($result);
			$this->_foldersById[$folder->id] = $folder;
			$folders[] = $folder;
		}

		return $folders;
	}

	/**
	 * Returns all of the folders that are descendants of a given folder.
	 *
	 * @param AssetFolderModel $parentFolder
	 *
	 * @return array
	 */
	public function getAllDescendantFolders(AssetFolderModel $parentFolder)
	{
		$query = (new Query())
			->select('f.*')
			->from('{{%assetfolders}} f')
			->where(['like', 'path', $parentFolder->path.'%', false])
			->andWhere('sourceId = :sourceId', [':sourceId' => $parentFolder->sourceId]);

		$results = $query->all();
		$descendantFolders = [];

		foreach ($results as $result)
		{
			$folder = AssetFolderModel::create($result);
			$this->_foldersById[$folder->id] = $folder;
			$descendantFolders[$folder->id] = $folder;
		}

		return $descendantFolders;
	}

	/**
	 * Finds the first folder that matches a given criteria.
	 *
	 * @param mixed $criteria
	 *
	 * @return AssetFolderModel|null
	 */
	public function findFolder($criteria = null)
	{
		if (!($criteria instanceof FolderCriteriaModel))
		{
			$criteria = new FolderCriteriaModel($criteria);
		}

		$criteria->limit = 1;
		$folder = $this->findFolders($criteria);

		if (is_array($folder) && !empty($folder))
		{
			return array_pop($folder);
		}

		return null;
	}

	/**
	 * Gets the total number of folders that match a given criteria.
	 *
	 * @param mixed $criteria
	 *
	 * @return int
	 */
	public function getTotalFolders($criteria)
	{
		if (!($criteria instanceof FolderCriteriaModel))
		{
			$criteria = new FolderCriteriaModel($criteria);
		}

		$query = (new Query())
			->select('count(id)')
			->from('{{%assetfolders}} f');

		$this->_applyFolderConditions($query, $criteria);

		return (int) $query->scalar();
	}

	// File and folder managing
	// -------------------------------------------------------------------------

	/**
	 * @param int    $folderId     The Id of the folder the file is being uploaded to.
	 * @param string $userResponse User response regarding filename conflict.
	 * @param int    $theNewFileId The new file ID that has triggered the conflict.
	 * @param string $filename     The filename that is in the conflict.
	 *
	 * @return AssetOperationResponseModel
	 */
	public function uploadFile($folderId, $userResponse = '', $theNewFileId = 0, $filename = '')
	{
		try
		{
			// handle a user's conflict resolution response
			if (!empty($userResponse))
			{
				return $this->_resolveUploadConflict($userResponse, $theNewFileId, $filename);
			}

			$folder = $this->getFolderById($folderId);
			$source = Craft::$app->assetSources->getSourceTypeById($folder->sourceId);

			return $source->uploadFile($folder);
		}
		catch (Exception $exception)
		{
			$response = new AssetOperationResponseModel();
			$response->setError(Craft::t('app', 'Error uploading the file: {error}', ['error' => $exception->getMessage()]));

			return $response;
		}
	}

	/**
	 * Saves a file into an asset folder.
	 *
	 * This can be used to store newly-uploaded files:
	 *
	 * ```php
	 * $uploadedFile = UploadedFile::getInstanceByName('photo');
	 * $folderId = 10;
	 *
	 * $response = Craft::$app->assets->insertFileByLocalPath(
	 *     $uploadedFile->tempName,
	 *     $uploadedFile->name,
	 *     $folderId,
	 *     AssetConflictResolution::KeepBoth
	 * );
	 *
	 * if ($response->isSuccess())
	 * {
	 *     $fileId = $response->getDataItem('fileId');
	 *     // ...
	 * }
	 * ```
	 *
	 * @param string $localPath          The local path to the file.
	 * @param string $filename           The name that the file should be given when saved in the asset folder.
	 * @param int    $folderId           The ID of the folder that the file should be saved into.
	 * @param string $conflictResolution What action should be taken in the event of a filename conflict, if any
	 *                                   (`AssetConflictResolution::KeepBoth`, `AssetConflictResolution::Replace`,
	 *                                   or `AssetConflictResolution::Cancel).
	 *
	 * @return AssetOperationResponseModel
	 */
	public function insertFileByLocalPath($localPath, $filename, $folderId, $conflictResolution = null)
	{
		$folder = $this->getFolderById($folderId);

		if (!$folder)
		{
			return false;
		}

		$filename = AssetsHelper::cleanAssetName($filename);
		$source = Craft::$app->assetSources->getSourceTypeById($folder->sourceId);

		$response = $source->insertFileByPath($localPath, $folder, $filename);

		if ($response->isConflict() && $conflictResolution)
		{
			$theNewFileId = $response->getDataItem('fileId');
			$response = $this->_resolveUploadConflict($conflictResolution, $theNewFileId, $filename);
		}

		return $response;
	}

	/**
	 * Returns true, if a file is in the process os being merged.
	 *
	 * @return bool
	 */
	public function isMergeInProgress()
	{
		return $this->_mergeInProgress;
	}

	/**
	 * Delete a list of files by an array of ids (or a single id).
	 *
	 * @param array $fileIds
	 * @param bool $deleteFile Should the file be deleted along the record. Defaults to true.
	 *
	 * @return AssetOperationResponseModel
	 */
	public function deleteFiles($fileIds, $deleteFile = true)
	{
		if (!is_array($fileIds))
		{
			$fileIds = [$fileIds];
		}

		$response = new AssetOperationResponseModel();

		try
		{
			foreach ($fileIds as $fileId)
			{
				$file = $this->getFileById($fileId);
				$source = Craft::$app->assetSources->getSourceTypeById($file->sourceId);

				// Fire a 'beforeDeleteAsset' event
				$this->trigger(static::EVENT_BEFORE_DELETE_ASSET, new AssetEvent([
					'asset' => $file
				]));

				if ($deleteFile)
				{
					$source->deleteFile($file);
				}

				Craft::$app->elements->deleteElementById($fileId);

				// Fire an 'afterDeleteAsset' event
				$this->trigger(static::EVENT_AFTER_DELETE_ASSET, new AssetEvent([
					'asset' => $file
				]));
			}

			$response->setSuccess();
		}
		catch (Exception $exception)
		{
			$response->setError($exception->getMessage());
		}

		return $response;
	}

	/**
	 * Move or rename files.
	 *
	 * @param        $fileIds
	 * @param        $folderId
	 * @param string $filename If this is a rename operation or not.
	 * @param array  $actions  Actions to take in case of a conflict.
	 *
	 * @throws Exception
	 * @return bool|AssetOperationResponseModel
	 */
	public function moveFiles($fileIds, $folderId, $filename = '', $actions = [])
	{
		if ($filename && is_array($fileIds) && count($fileIds) > 1)
		{
			throw new Exception(Craft::t('app', 'It’s not possible to rename multiple files!'));
		}

		if (!is_array($fileIds))
		{
			$fileIds = [$fileIds];
		}

		if (!is_array($actions))
		{
			$actions = [$actions];
		}

		$results = [];

		$response = new AssetOperationResponseModel();

		$folder = $this->getFolderById($folderId);
		$newSourceType = Craft::$app->assetSources->getSourceTypeById($folder->sourceId);

		// Does the source folder exist?
		$parent = $folder->getParent();

		if ($parent && $folder->parentId && !$newSourceType->folderExists(($parent ? $parent->path : ''), $folder->name))
		{
			$response->setError(Craft::t('app', 'The target folder does not exist!'));
		}
		else
		{
			foreach ($fileIds as $i => $fileId)
			{
				$file = $this->getFileById($fileId);

				// If this is not a rename operation, then the filename remains the original
				if (count($fileIds) > 1 || empty($filename))
				{
					$filename = $file->filename;
				}

				// If the new file does not have an extension, give it the old file extension.
				if (!IOHelper::getExtension($filename))
				{
					$filename .= '.'.$file->getExtension();
				}

				$filename = AssetsHelper::cleanAssetName($filename);

				if ($folderId == $file->folderId && ($filename == $file->filename))
				{
					$response = new AssetOperationResponseModel();
					$response->setSuccess();
					$results[] = $response;
				}

				$originalSourceType = Craft::$app->assetSources->getSourceTypeById($file->sourceId);

				if ($originalSourceType && $newSourceType)
				{
					if (!$response = $newSourceType->moveFileInsideSource($originalSourceType, $file, $folder, $filename, $actions[$i]))
					{
						$response = $this->_moveFileBetweenSources($originalSourceType, $newSourceType, $file, $folder, $actions[$i]);
					}
				}
				else
				{
					$response->setError(Craft::t('app', 'There was an error moving the file {file}.', ['file' => $file->filename]));
				}
			}
		}

		return $response;
	}


	/**
	 * @param Asset  $file
	 * @param string $filename
	 * @param string $action The action to take in case of a conflict.
	 *
	 * @return bool|AssetOperationResponseModel
	 */
	public function renameFile(Asset $file, $filename, $action = '')
	{
		$response = $this->moveFiles([$file->id], $file->folderId, $filename, $action);

		// Set the new filename, if rename was successful
		if ($response->isSuccess())
		{
			$file->filename = $response->getDataItem('newFilename');
		}

		return $response;
	}

	/**
	 * Delete a folder record by id.
	 *
	 * @param $folderId
	 *
	 * @return bool
	 */
	public function deleteFolderRecord($folderId)
	{
		return (bool) AssetFolderRecord::deleteAll('id = :folderId', [':folderId' => $folderId]);
	}

	/**
	 * Get URL for a file.
	 *
	 * @param Asset  $file
	 * @param string $transform
	 *
	 * @return string
	 */
	public function getUrlForFile(Asset $file, $transform = null)
	{
		if (!$transform || !ImageHelper::isImageManipulatable(IOHelper::getExtension($file->filename)))
		{
			$sourceType = Craft::$app->assetSources->getSourceTypeById($file->sourceId);

			return AssetsHelper::generateUrl($sourceType, $file);
		}

		// Get the transform index model
		$index = Craft::$app->assetTransforms->getTransformIndex($file, $transform);

		// Does the file actually exist?
		if ($index->fileExists)
		{
			return Craft::$app->assetTransforms->getUrlForTransformByTransformIndex($index);
		}
		else
		{
			if (Craft::$app->config->get('generateTransformsBeforePageLoad'))
			{
				// Mark the transform as in progress
				$index->inProgress = true;
				Craft::$app->assetTransforms->storeTransformIndexData($index);

				// Generate the transform
				Craft::$app->assetTransforms->generateTransform($index);

				// Update the index
				$index->fileExists = true;
				Craft::$app->assetTransforms->storeTransformIndexData($index);

				// Return the transform URL
				return Craft::$app->assetTransforms->getUrlForTransformByTransformIndex($index);
			}
			else
			{
				// Queue up a new Generate Pending Transforms task, if there isn't one already
				if (!Craft::$app->tasks->areTasksPending(GeneratePendingTransforms::className()))
				{
					Craft::$app->tasks->queueTask(GeneratePendingTransforms::className());
				}

				// Return the temporary transform URL
				return UrlHelper::getResourceUrl('transforms/'.$index->id);
			}
		}
	}

	/**
	 * Return true if user has permission to perform the action on the folder.
	 *
	 * @param $folderId
	 * @param $action
	 *
	 * @return bool
	 */
	public function canUserPerformAction($folderId, $action)
	{
		try
		{
			$this->checkPermissionByFolderIds($folderId, $action);
			return true;
		}
		catch (Exception $exception)
		{
			return false;
		}
	}

	/**
	 * Check for a permission on a source by a folder id or an array of folder ids.
	 *
	 * @param $folderIds
	 * @param $permission
	 *
	 * @throws Exception
	 * @return null
	 */
	public function checkPermissionByFolderIds($folderIds, $permission)
	{
		if (!is_array($folderIds))
		{
			$folderIds = [$folderIds];
		}

		foreach ($folderIds as $folderId)
		{
			$folderModel = $this->getFolderById($folderId);

			if (!$folderModel)
			{
				throw new Exception(Craft::t('app', 'That folder does not seem to exist anymore. Re-index the Assets source and try again.'));
			}

			if (
				!Craft::$app->getUser()->checkPermission($permission.':'.$folderModel->sourceId)
				&&
				!Craft::$app->getSession()->checkAuthorization($permission.':'.$folderModel->id))
			{
				throw new Exception(Craft::t('app', 'You don’t have the required permissions for this operation.'));
			}
		}
	}

	/**
	 * Check for a permission on a source by a file id or an array of file ids.
	 *
	 * @param $fileIds
	 * @param $permission
	 *
	 * @throws Exception
	 * @return null
	 */
	public function checkPermissionByFileIds($fileIds, $permission)
	{
		if (!is_array($fileIds))
		{
			$fileIds = [$fileIds];
		}

		foreach ($fileIds as $fileId)
		{
			$file = $this->getFileById($fileId);

			if (!$file)
			{
				throw new Exception(Craft::t('app', 'That file does not seem to exist anymore. Re-index the Assets source and try again.'));
			}

			if (!Craft::$app->getUser()->checkPermission($permission.':'.$file->sourceId))
			{
				throw new Exception(Craft::t('app', 'You don’t have the required permissions for this operation.'));
			}
		}
	}

	// Private Methods
	// =========================================================================

	/**
	 * Returns a Query object prepped for retrieving assets.
	 *
	 * @return Query
	 */
	private function _createFolderQuery()
	{
		return (new Query())
			->select(['id', 'parentId', 'sourceId', 'name', 'path'])
			->from('{{%assetfolders}}');
	}

	/**
	 * Return the folder tree form a list of folders.
	 *
	 * @param $folders
	 *
	 * @return array
	 */
	private function _getFolderTreeByFolders($folders)
	{
		$tree = [];
		$referenceStore = [];

		foreach ($folders as $folder)
		{
			if ($folder->parentId && isset($referenceStore[$folder->parentId]))
			{
				$referenceStore[$folder->parentId]->addChild($folder);
			}
			else
			{
				$tree[] = $folder;
			}

			$referenceStore[$folder->id] = $folder;
		}

		$sort = [];

		foreach ($tree as $topFolder)
		{
			$sort[] = Craft::$app->assetSources->getSourceById($topFolder->sourceId)->sortOrder;
		}

		array_multisort($sort, $tree);

		return $tree;
	}

	/**
	 * Applies WHERE conditions to a Query query for folders.
	 *
	 * @param Query               $query
	 * @param FolderCriteriaModel $criteria
	 *
	 * @return null
	 */
	private function _applyFolderConditions($query, FolderCriteriaModel $criteria)
	{
		$whereConditions = [];
		$whereParams     = [];

		if ($criteria->id)
		{
			$whereConditions[] = DbHelper::parseParam('f.id', $criteria->id, $whereParams);
		}

		if ($criteria->sourceId)
		{
			$whereConditions[] = DbHelper::parseParam('f.sourceId', $criteria->sourceId, $whereParams);
		}

		if ($criteria->parentId)
		{
			$whereConditions[] = DbHelper::parseParam('f.parentId', $criteria->parentId, $whereParams);
		}

		if ($criteria->name)
		{
			$whereConditions[] = DbHelper::parseParam('f.name', $criteria->name, $whereParams);
		}

		if (!is_null($criteria->path))
		{
			// This folder has a comma in it.
			if (StringHelper::contains($criteria->path, ','))
			{
				// Escape the comma.
				$condition = DbHelper::parseParam('f.path', str_replace(',', '\,', $criteria->path), $whereParams);
				$lastKey = key(array_slice($whereParams, -1, 1, true));

				// Now un-escape it.
				$whereParams[$lastKey] = str_replace('\,', ',', $whereParams[$lastKey]);
			}
			else
			{
				$condition = DbHelper::parseParam('f.path', $criteria->path, $whereParams);
			}

			$whereConditions[] = $condition;
		}

		if (count($whereConditions) == 1)
		{
			$query->where($whereConditions[0], $whereParams);
		}
		else
		{
			array_unshift($whereConditions, 'and');
			$query->where($whereConditions, $whereParams);
		}
	}

	/**
	 * Flag a file merge in progress.
	 *
	 * @return null
	 */
	private function _startMergeProcess()
	{
		$this->_mergeInProgress = true;
	}

	/**
	 * Flag a file merge no longer in progress.
	 *
	 * @return null
	 */
	private function _finishMergeProcess()
	{
		$this->_mergeInProgress = false;
	}

	/**
	 * Merge a conflicting uploaded file.
	 *
	 * @param string $conflictResolution  User response to conflict.
	 * @param int    $theNewFileId        The id of the new file that is conflicting.
	 * @param string $filename            The filename that is in the conflict.
	 *
	 * @return AssetOperationResponseModel
	 */
	private function _mergeUploadedFiles($conflictResolution, $theNewFileId, $filename)
	{

		$theNewFile = $this->getFileById($theNewFileId);
		$folder = $theNewFile->getFolder();
		$source = Craft::$app->assetSources->getSourceTypeById($folder->sourceId);

		$fileId = null;

		switch ($conflictResolution)
		{
			case AssetConflictResolution::Replace:
			{
				// Replace the actual file
				$targetFile = $this->findFile([
					'folderId' => $folder->id,
					'filename' => $filename
				]);

				// If the file doesn't exist in the index, but just in the source,
				// quick-index it, so we have a File Model to work with.
				if (!$targetFile)
				{
					$targetFile = new Asset();
					$targetFile->sourceId = $folder->sourceId;
					$targetFile->folderId = $folder->id;
					$targetFile->filename = $filename;
					$targetFile->kind = IOHelper::getFileKind(IOHelper::getExtension($filename));
					$this->storeFile($targetFile);
				}

				$source->replaceFile($targetFile, $theNewFile);
				$fileId = $targetFile->id;
			}
			// Falling through to delete the file
			case AssetConflictResolution::Cancel:
			{
				$this->deleteFiles($theNewFileId);
				break;
			}
			default:
			{
				$fileId = $theNewFileId;
				break;
			}
		}

		$response = new AssetOperationResponseModel();
		$response->setSuccess();

		if ($fileId)
		{
			$response->setDataItem('fileId', $fileId);
			$response->setDataItem('filename', $theNewFile->filename);
		}

		return $response;
	}

	/**
	 * Move a file between sources.
	 *
	 * @param BaseAssetSourceType $originatingSource
	 * @param BaseAssetSourceType $targetSource
	 * @param Asset               $file
	 * @param AssetFolderModel    $folder
	 * @param string              $action
	 *
	 * @return AssetOperationResponseModel
	 */
	private function _moveFileBetweenSources(BaseAssetSourceType $originatingSource, BaseAssetSourceType $targetSource, Asset $file, AssetFolderModel $folder, $action = '')
	{
		$localCopy = $originatingSource->getLocalCopy($file);

		// File model will be updated in the process, but we need the old data in order to finalize the transfer.
		$oldFileModel = clone $file;

		$response = $targetSource->transferFileIntoSource($localCopy, $folder, $file, $action);

		if ($response->isSuccess())
		{
			// Use the previous data to clean up
			Craft::$app->assetTransforms->deleteAllTransformData($oldFileModel);
			$originatingSource->finalizeTransfer($oldFileModel);
		}

		IOHelper::deleteFile($localCopy);

		return $response;
	}

	/**
	 * Do an upload conflict resolution with merging.
	 *
	 * @param string $conflictResolution User response to conflict.
	 * @param int    $theNewFileId       The id of the new file that is conflicting.
	 * @param string $filename           Filename of the conflicting file.
	 *
	 * @return AssetOperationResponseModel
	 */
	private function _resolveUploadConflict($conflictResolution, $theNewFileId, $filename)
	{
		$this->_startMergeProcess();
		$response =  $this->_mergeUploadedFiles($conflictResolution, $theNewFileId, $filename);
		$this->_finishMergeProcess();

		return $response;
	}
}
