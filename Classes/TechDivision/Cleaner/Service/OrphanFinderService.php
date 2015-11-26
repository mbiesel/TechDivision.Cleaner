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
use TYPO3\Flow\Utility\TypeHandling;

class OrphanFinderService {

	/**
	 * Resource repository
	 *
	 * @var \TYPO3\Flow\Resource\ResourceRepository
	 * @Flow\Inject
	 */
	protected $resourceRepository;

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
	 * Console output
	 *
	 * @Flow\Inject
	 * @var \TYPO3\Flow\Cli\ConsoleOutput
	 */
	protected $consoleOutput;

	/**
	 * Persistence manager
	 *
	 * @Flow\Inject
	 * @var \TYPO3\Flow\Persistence\PersistenceManagerInterface
	 */
	protected $persistenceManager;

	/**
	 * All orphan persistent resources
	 *
	 * @var Array<Resource>
	 */
	protected $orphanPersistentResources = array();

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
	protected $orphanResources = array();

	/**
	 * Find orphan assets, resources and persistent resources
	 *
	 * @return array
	 */
	public function findOrphanResources() {
		$resourcesGroupedBySha1 = $this->groupAllResourcesBySha1();

		foreach($resourcesGroupedBySha1 as $sha1Group) {
			$this->searchForOrphanResource($sha1Group);
		}
		
		return array(
			'orphanPersistentResources' => $this->orphanPersistentResources,
			'orphanResources' => $this->orphanResources,
			'orphanAssets' => $this->orphanAssets
		);
	}

	/**
	 * Group all resources by same sha1,
	 * to decide which persistent resources can be deleted
	 *
	 * @return array
	 */
	protected function groupAllResourcesBySha1() {
		$resourcesGroupedBySha1 = array();
		$resources = $this->resourceRepository->findAll();

		/** @var Resource $resource */
		foreach($resources as $resource) {
			$resourcesGroupedBySha1[$resource->getSha1()][] = $resource;
		}

		return $resourcesGroupedBySha1;
	}

	/**
	 * Search for orphan assets, resources and persistent resources
	 *
	 * @param $resourcesWithSameSha1
	 * @return void
	 */
	protected function searchForOrphanResource($resourcesWithSameSha1) {
		$orphanResourceCounter = 0;

		/** @var Resource $resource */
		foreach($resourcesWithSameSha1 as $resource) {

			/** @var QueryResultInterface<Asset> $assets */
			$assets = $this->assetRepository->findByResource($resource);

			// resource has one asset
			if(count($assets) === 1) {
				$asset = $this->searchForOrphanAsset($assets->getFirst());

				// true if asset is orphan
				if($asset) {
					$this->orphanAssets[] = $asset;
					$this->orphanResources[] = $resource;
					$orphanResourceCounter++;
					$this->consoleOutput->outputLine('Found orphan asset with identifier "%s" and label "%s", therefore his resource with sha1 "%s" is also orphan',
						array($asset->getIdentifier(), $asset->getLabel(), $resource->getSha1()));
				}
			// resource has no asset
			} elseif (count($assets) === 0) {
				$orphanResourceCounter++;
				$this->orphanResources[] = $resource;
				$this->consoleOutput->outputLine('Found orphan resource with sha1 "%s" and label "%s"', array($resource->getSha1(), $resource->getFilename()));
			} else {
				$this->consoleOutput->outputLine('ERROR: we have found %s assets for the same resource "%s" with the sha1 "%s". We have to abort this!',
					array(count($assets), $resource->getFilename(), $resource->getSha1()));
				die;
			}
		}

		// if all resources with the same sah1 hasn't any asset or the asset is orphan, we can delete it
		if(count($resourcesWithSameSha1) === $orphanResourceCounter) {
			$this->orphanPersistentResources[] = $resource;
			$this->consoleOutput->outputLine('Found orphan persistent resource with sha1 "%s" and filename "%s"', array($resource->getSha1(), $resource->getFilename()));
		}
	}

	/**
	 * Search for an orphan asset at the node data repository
	 * If a asset is orphan return the asset, otherwise return false
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

		// if the asset is needed return false, otherwise return the orphan asset
		if (count($relatedNodes) > 0) {
			return false;
		} else {
			return $asset;
		}
	}

}