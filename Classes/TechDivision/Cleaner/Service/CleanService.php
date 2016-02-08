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
use \TYPO3\Flow\Resource\Resource;

class CleanService
{

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
     * Amount orphans
     *
     * @var integer
     */
    protected $amountOrphans;


    /**
     * Initialize clean service
     *
     * Get default file storage
     */
    public function initializeObject()
    {
        $this->storage = $this->resourceManager->getStorage("defaultPersistentResourcesStorage");
    }

    /**
     * Remove all orphan resource files and database entries, but ask therefor
     *
     * @param array $orphans
     * @return bool|array
     */
    public function removeOrphans(array $orphans)
    {
        $orphanPersistentResources = $orphans['orphanPersistentResources'];
        $orphanResources = $orphans['orphanResources'];
        $orphanAssets = $orphans['orphanAssets'];

        $response = $this->askBeforeCleaning($orphanPersistentResources, $orphanResources, $orphanAssets);

        if ($response === TRUE) {
            $response = array(
                'amountDeletedPersistentResources' => count($orphanPersistentResources),
                'amountDeletedResources' => count($orphanResources),
                'amountDeletedAssets' => count($orphanAssets),
            );
        }

        return $response;

    }

    /**
     * Remove all orphan resource files and database entries
     *
     * @param array $orphanPersistentResources
     * @param array $orphanResources
     * @param array $orphanAssets
     * @return bool
     */
    protected function cleanOrphans(array $orphanPersistentResources, array $orphanResources, array $orphanAssets)
    {
        $response = TRUE;
        $this->consoleOutput->outputLine();
        $this->consoleOutput->progressStart($this->amountOrphans);

        /** @var Resource $resource */
        foreach ($orphanPersistentResources as $resource) {
            $this->consoleOutput->progressAdvance(1);
            $this->removeResourceAndThumbnailsByResource($resource);

            // delete from file system
            $this->storage->deleteResource($resource);
        }

        /** @var Resource $orphanResource */
        foreach ($orphanResources as $orphanResource) {
            $this->consoleOutput->progressAdvance(1);
            $this->removeResourceAndThumbnailsByResource($orphanResource);
        }

        /** @var Asset $asset */
        foreach ($orphanAssets as $asset) {
            $this->consoleOutput->progressAdvance(1);
            $this->assetRepository->remove($asset);
        }

        $this->consoleOutput->progressFinish();
        $this->consoleOutput->outputLine();

        return $response;
    }

    /**
     * Remove the given resource and his thumbnails
     *
     * @param Resource $resource
     * @return void
     */
    protected function removeResourceAndThumbnailsByResource(Resource $resource)
    {
        // delete from database
        $this->deleteThumbnailsByResource($resource);

        $resource->disableLifecycleEvents();
        $this->persistenceManager->remove($resource);
    }

    /**
     * Delete thumbnails by resource
     *
     * @param Resource $resource
     * @return void
     */
    protected function deleteThumbnailsByResource(Resource $resource)
    {
        $thumbnails = $this->thumbnailRepository->findByResource($resource);

        foreach ($thumbnails as $thumbnail) {
            $this->thumbnailRepository->remove($thumbnail);
        }
    }

    /**
     * Ask user, before all orphans will be deleted
     *
     * @param array $orphanPersistentResources
     * @param array $orphanResources
     * @param array $orphanAssets
     * @return bool
     */
    protected function askBeforeCleaning(array $orphanPersistentResources, array $orphanResources, array $orphanAssets)
    {
        $userInput = NULL;
        $response = FALSE;
        $orphansCounter = 0;

        /** @var $asset Asset */
        foreach ($orphanAssets as $asset) {
            $orphansCounter++;
            $this->consoleOutput->outputLine(sprintf('Found orphan asset with identifier "%s" and label "%s"',
                $asset->getIdentifier(), $asset->getLabel()));
        }

        /** @var $resource Resource */
        foreach ($orphanResources as $resource) {
            $orphansCounter++;
            $this->consoleOutput->outputLine(sprintf('Found orphan resource with sha1 "%s" and label "%s"', $resource->getSha1(), $resource->getFilename()));
        }

        /** @var $resource Resource */
        foreach ($orphanPersistentResources as $persistentResource) {
            $orphansCounter++;
            $this->consoleOutput->outputLine(sprintf('Found orphan persistent resource with sha1 "%s" and filename "%s"', $persistentResource->getSha1(), $persistentResource->getFilename()));
        }

        $this->amountOrphans = $orphansCounter;

        if ($orphansCounter > 0) {
            while (!in_array($userInput, array('y', 'n'))) {
                $userInput = $this->consoleOutput->ask(sprintf("<comment>Do you want to remove %s orphans? (y/n) </comment>", $orphansCounter));
            }

            switch ($userInput) {
                case 'y':
                    $response = $this->cleanOrphans($orphanPersistentResources, $orphanResources, $orphanAssets);
                    break;
                case 'n':
                    $this->consoleOutput->outputLine(sprintf("Did not delete any orphans.\nExit"));
                    break;
            }
        } else {
            $this->consoleOutput->outputLine(sprintf("No orphans given to remove.\nExit"));
        }

        return $response;
    }
}