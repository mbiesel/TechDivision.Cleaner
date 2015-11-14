<?php
namespace TechDivision\Cleaner\Service;

/**
 * NOTICE OF LICENSE
 *
 * This source file is subject to version 3 of the GPL license,
 * that is bundled with this package in the file LICENSE, and is
 * available online at http://www.gnu.org/licenses/gpl.txt
 *
 * @author    Marcus Biesel <m.biesel@techdivision.com>
 * @copyright 2015 TechDivision GmbH <info@techdivision.com>
 * @license   http://www.gnu.org/licenses/gpl.txt GNU General Public License, version 3 (GPL-3.0)
 */

use TYPO3\Flow\Annotations as Flow;
use TYPO3\Flow\Utility\TypeHandling;
use TYPO3\Media\Domain\Model\Asset;
use \TYPO3\Media\Domain\Model\Image;
use TYPO3\Flow\Persistence\QueryResultInterface;
use \TYPO3\Flow\Resource\Resource;

class CleanService {

    /**
     * Resource repository
     *
     * @var \TYPO3\Flow\Resource\ResourceRepository
     * @Flow\Inject
     */
    protected $resourceRepository;

    /**
     * Thumbnail repository
     *
     * @var \TYPO3\Media\Domain\Repository\ThumbnailRepository
     * @Flow\Inject
     */
    protected $thumbnailRepository;

    /**
     * Node data repository
     *
     * @var \TYPO3\TYPO3CR\Domain\Repository\NodeDataRepository
     * @Flow\Inject
     */
    protected $nodeDataRepository;

    /**
     * Asset repository
     *
     * @var \TYPO3\Media\Domain\Repository\AssetRepository
     * @Flow\Inject
     */
    protected $assetRepository;

    /**
     * Resource repository
     *
     * @var \TYPO3\Flow\Resource\ResourceManager
     * @Flow\Inject
     */
    protected $resourceManager;

    /**
     * Persistence manager
     *
     * @Flow\Inject
     * @var \TYPO3\Flow\Persistence\PersistenceManagerInterface
     */
    protected $persistenceManager;

    /**
     * All resources
     *
     * @var Resource
     */
    protected $resources;

    /**
     * All orphan resources
     *
     * @var Array<Resource>
     */
    protected $orphanResources = array();

    /**
     * All orphan assets
     *
     * @var Array<Asset>
     */
    protected $orphanAssets = array();

	/**
     * All orphan assets
     *
     * @var Array<Resource>
     */
    protected $orphanResourceEntries = array();

    /**
     * File storage
     *
     * @var \TYPO3\Flow\Resource\Storage\StorageInterface
     */
    protected $storage;

    /**
     * Console output
     *
     * @Flow\Inject
     * @var \TYPO3\Flow\Cli\ConsoleOutput
     */
    protected $consoleOutput;


    /**
     * Initialize clean service
     *
     * Fetch all resources and initialize the file storage
     */
    public function initializeObject() {
        $this->storage = $this->resourceManager->getStorage("defaultPersistentResourcesStorage");
    }

    /**
     * Remove all orphan resource files and database entries
     *
     * @return void
     */
    public function removeOrphanResources() {
	    $groupedResources = $this->groupAllResourcesBySha1();

        $this->findOrphanResources($groupedResources);

		$this->deleteOrphanResources();

    }

	protected function deleteOrphanResources() {

		/** @var Resource $resource */
		foreach($this->orphanResources as $resource) {

			// delete from file system
			$this->storage->deleteResource($resource);

			$this->consoleOutput->outputLine('The resource with the sha1 key "%s" and the name "%s" was deleted from filesystem', array($resource->getSha1(), $resource->getFilename()));
		}

		$this->consoleOutput->outputLine('----------------------------------');

		$orphanResources = array_merge($this->orphanResources, $this->orphanResourceEntries);

		/** @var Resource $orphanResource */
		foreach ($orphanResources as $orphanResource) {
			$this->removeResourceAndThumbnailsByResource($orphanResource);
			$this->consoleOutput->outputLine('The resource with the sha1 key "%s" and the name "%s" was deleted from database', array($orphanResource->getSha1(), $orphanResource->getFilename()));
		}

		$this->consoleOutput->outputLine('----------------------------------');

		/** @var Asset $asset */
		foreach ($this->orphanAssets as $asset) {
			$this->assetRepository->remove($asset);
			$this->consoleOutput->outputLine('The asset with the identifier "%s" and the label "%s" was deleted from database', array($asset->getIdentifier(), $asset->getLabel()));
		}

		$this->consoleOutput->outputLine('----------------------------------');

		$this->consoleOutput->outputLine('%s resources were deleted from filesystem, %s resource and %s asset entries has been removed from database.', array(count($this->orphanResources), count($orphanResources), count($this->orphanAssets)));
	}

	protected function removeResourceAndThumbnailsByResource($resource) {
		// delete from database
		$this->deleteThumbnailsByResource($resource);

		$resource->disableLifecycleEvents();
		$this->persistenceManager->remove($resource);
	}

	protected function groupAllResourcesBySha1() {
		$groupedResources = array();
	    $resources = $this->resourceRepository->findAll();

		/** @var Resource $resource */
		foreach($resources as $resource) {
			$groupedResources[$resource->getSha1()][] = $resource;
		}

		return $groupedResources;
	}

    /**
     * Find orphan resources
     *
     * @param
     * @return void
     */
    protected function findOrphanResources($groupedResources) {
        foreach($groupedResources as $resourceGroup) {
	        $this->checkForOrphanResource($resourceGroup);
	    }
    }

	protected function checkForOrphanResource($resourcesWithSameSha1) {
		$orphanResourcesCounter = 0;

		/** @var Resource $resource */
		foreach($resourcesWithSameSha1 as $resource) {

			/** @var QueryResultInterface<Asset> $assets */
			$assets = $this->assetRepository->findByResource($resource);

			if(count($assets) === 1) {
				$asset = $this->searchForOrphanAsset($assets->getFirst());

				if($asset) {
					$this->orphanAssets[] = $asset;
					$this->orphanResourceEntries[] = $resource;
					$orphanResourcesCounter++;
				}
			} elseif (count($assets) === 0) {
				$orphanResourcesCounter++;
				$this->orphanResourceEntries[] = $resource;
			} else {
				$this->consoleOutput->outputLine('ATTENTION we have found %s assets for the same resource "%s" with the sha1 key %s',
					array(count($assets), $resource->getFilename(), $resource->getSha1()));
			}
		}

		// if all resources with the same sah1 hasn't any asset or the asset is orphan, we can delete it
		if(count($resourcesWithSameSha1) === $orphanResourcesCounter) {
			$this->orphanResources[] = $resource;
		}

	}

    /**
     * Search for an orphan asset at the node data repository
     * If a asset is unused return the asset, otherwise return false
     *
     * @param Asset $asset
     * @return bool|Asset
     */
    protected function searchForOrphanAsset(Asset $asset) {
        $relationMap = [];
        $relationMap[TypeHandling::getTypeForValue($asset)] = array($this->persistenceManager->getIdentifierByObject($asset));

        if ($asset instanceof Image) {
            foreach ($asset->getVariants() as $variant) {
                $type = TypeHandling::getTypeForValue($variant);
                if (!isset($relationMap[$type])) {
                    $relationMap[$type] = [];
                }
                $relationMap[$type][] = $this->persistenceManager->getIdentifierByObject($variant);
            }
        }

        $relatedNodes = $this->nodeDataRepository->findNodesByRelatedEntities($relationMap);

        // if it's needed return false, else return the unused asset
        if (count($relatedNodes) > 0) {
            return false;
        } else {
            return $asset;
        }
    }

    /**
     * Delete thumbnails by resource
     *
     * @param $resource Resource
     * @return void
     */
    protected function deleteThumbnailsByResource($resource) {
        $thumbnails = $this->thumbnailRepository->findByResource($resource);

        foreach($thumbnails as $thumbnail) {
            $this->thumbnailRepository->remove($thumbnail);
        }
    }
}