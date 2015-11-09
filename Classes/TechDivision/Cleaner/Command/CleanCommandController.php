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
class CleanCommandController extends \TYPO3\Flow\Cli\CommandController {

	/**
	* Clean service
	*
	* @var \TechDivision\Cleaner\Service\CleanService
	* @Flow\Inject
	*/
	protected $cleanService;

	/**
	 * Removes all orphan/unused resources from database and filesystem
	 *
	 * @return void
	 */
	public function resourcesCommand() {
		$count = $this->cleanService->removeOrphanResources();
		$this->outputLine('%s resources has been removed from database and filesystem.', array($count));
	}

}