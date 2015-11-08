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
use TYPO3\Flow\Resource\Resource;
use TYPO3\Flow\Utility\TypeHandling;
use TYPO3\Media\Domain\Model\Asset;
use \TYPO3\Media\Domain\Model\Image;
use TYPO3\Flow\Persistence\QueryResultInterface;

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
        $this->resources = $this->resourceRepository->findAll();
        $this->storage = $this->resourceManager->getStorage("defaultPersistentResourcesStorage");
    }

    /**
     * Remove all orphan resource files and database entries
     *
     * @return int
     */
    public function removeOrphanResources() {
        $this->findOrphanResources();

        foreach($this->orphanResources as $resource) {
            // delete from database
            $this->deleteThumbnailsByResource($resource);
            $this->resourceRepository->remove($resource);

            // delete from file system
            $this->storage->deleteResource($resource);
        }

        return count($this->orphanResources);
    }

    /**
     * Find orphan resources
     *
     * @return array
     */
    public function findOrphanResources() {
        $orphanResources = array();

        /** @var \TYPO3\Flow\Resource\Resource $resource */
        foreach($this->resources as $resource) {
            /** @var QueryResultInterface<Asset> $assets */
            $assets = $this->assetRepository->findByResource($resource);

            if(count($assets) === 1) {
                $this->checkForOrphanAsset($resource, $assets->getFirst());
            } elseif (count($assets) === 0) {
                $this->checkResourcesWithSameSah1($resource);
            } else {
                $this->consoleOutput->outputLine('ATTENTION we have found %s assets for the same resource "%s" with the sha1 key %s',
                    array(count($assets), $resource->getFilename(), $resource->getSha1()));
            }
        }

        return $orphanResources;
    }

    /**
     * Search for an orphan asset at the node data repository
     * If a asset is unused return the asset, else return false
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

    /**
     * If a resource has no asset, we can remove this database entry
     * But if we want to delete the source file we have to check if other resources use the same source file (same sha1)
     *
     * ToDo check if resource has a thumbnail, at the moment the resource will be deleted also if a thumbnail exists,
     * that's not a big problem because it will automatically new created by the neos core, if needed, but its not nice
     *
     * @param $resource Resource
     * @return void
     */
    protected function checkResourcesWithSameSah1($resource) {
        $resourcesWithSameSha1 = $this->resourceRepository->findBySha1($resource->getSha1());

        $orphanAssets = 0;
        foreach($resourcesWithSameSha1 as $sameResource) {
            $asset = $this->assetRepository->findByResource($sameResource);

            if(count($asset) === 0) {
                $orphanAssets++;
            } elseif(count($asset) === 1) {
                $orphanAsset = $this->searchForOrphanAsset($asset->getFirst());

                if($orphanAsset) {
                    $orphanAssets++;
                    $this->removeOrphanAsset($orphanAsset);
                }
            }
        }

        // if all resources with the same sah1 hasn't any asset or the asset is unused, we can delete it
        if(count($resourcesWithSameSha1) === intval($orphanAssets)) {
            $this->orphanResources[] = $resource;
            $this->consoleOutput->outputLine('The resource with the sha1 key "%s" and the name "%s" has no asset', array($resource->getSha1(), $resource->getFilename()));
        }
    }

    /**
     * If a resource has a asset, we have to check if this asset is used at a node data
     *
     * @param $resource Resource
     * @param $asset Asset
     * @return void
     */
    public function checkForOrphanAsset($resource, $asset) {
        $orphanAsset = $this->searchForOrphanAsset($asset);
        if($orphanAsset) {
            $this->orphanResources[] = $resource;
            $this->removeOrphanAsset($orphanAsset);
            $this->consoleOutput->outputLine('The resource with the sha1 key "%s" and the name "%s" is no longer used at any node data', array($resource->getSha1(), $resource->getFilename()));
        }
    }

    /**
     * This method is for a testing mode in the future
     * ToDo implement testing mode
     *
     * @param $orphanAsset
     */
    protected function removeOrphanAsset($orphanAsset) {
        $this->assetRepository->remove($orphanAsset);
    }
}