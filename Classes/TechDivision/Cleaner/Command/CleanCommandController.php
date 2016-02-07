<?php
namespace TechDivision\Cleaner\Command;

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

/**
 * @Flow\Scope("singleton")
 */
class CleanCommandController extends \TYPO3\Flow\Cli\CommandController
{

    /**
     * Clean service
     *
     * @var \TechDivision\Cleaner\Service\CleanService
     * @Flow\Inject
     */
    protected $cleanService;

    /**
     * Orphan finder service
     *
     * @var \TechDivision\Cleaner\Service\OrphanFinderService
     * @Flow\Inject
     */
    protected $orphanFinderService;

    /**
     * Removes all orphan/unused resources from database and filesystem
     *
     * @return void
     */
    public function resourcesCommand()
    {
        $orphans = $this->orphanFinderService->findOrphans();

        $amountDeletedOrphans = $this->cleanService->removeOrphans($orphans);

        $this->outputLine('%s persistent resources were deleted from filesystem, %s resource and %s asset entries has been removed from database.',
            array($amountDeletedOrphans['amountDeletedPersistentResources'], $amountDeletedOrphans['amountDeletedResources'], $amountDeletedOrphans['amountDeletedAssets']));
        $this->outputLine("SUCCESS");
    }

}