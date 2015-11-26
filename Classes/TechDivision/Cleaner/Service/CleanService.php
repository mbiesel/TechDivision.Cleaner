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
     * Get default file storage
     */
    public function initializeObject() {
        $this->storage = $this->resourceManager->getStorage("defaultPersistentResourcesStorage");
    }

    /**
     * Remove all orphan resource files and database entries
     *
     * @param $orphans Array
     * @return void
     */
    public function removeOrphanResources($orphans) {
		$orphanPersistentResources = $orphans['orphanPersistentResources'];
		$orphanResources = $orphans['orphanResources'];
		$orphanAssets = $orphans['orphanAssets'];


		/** @var Resource $resource */
		foreach($orphanPersistentResources as $resource) {
			$this->removeResourceAndThumbnailsByResource($resource);

			// delete from file system
			$this->storage->deleteResource($resource);
		}

		/** @var Resource $orphanResource */
		foreach ($orphanResources as $orphanResource) {
			$this->removeResourceAndThumbnailsByResource($orphanResource);
		}

		/** @var Asset $asset */
		foreach ($orphanAssets as $asset) {
			$this->assetRepository->remove($asset);
		}

		$this->consoleOutput->outputLine("SUCCESS");
		$this->consoleOutput->outputLine('%s persistent resources were deleted from filesystem, %s resource and %s asset entries has been removed from database.',
			array(count($orphanPersistentResources), count($orphanResources), count($orphanAssets)));
	}

	protected function removeResourceAndThumbnailsByResource($resource) {
		// delete from database
		$this->deleteThumbnailsByResource($resource);

		$resource->disableLifecycleEvents();
		$this->persistenceManager->remove($resource);
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