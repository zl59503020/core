<?php
/**
 * @author Joas Schilling <coding@schilljs.com>
 * @author Lukas Reschke <lukas@statuscode.ch>
 * @author Roeland Jago Douma <rullzer@owncloud.com>
 *
 * @copyright Copyright (c) 2017, ownCloud GmbH
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program.  If not, see <http://www.gnu.org/licenses/>
 *
 */

namespace OCA\Files_Sharing\API;

use OC\Files\Filesystem;
use OCA\FederatedFileSharing\DiscoveryManager;
use OCA\Files_Sharing\External\Manager;
use Symfony\Component\EventDispatcher\GenericEvent;

class Remote {

	/**
	 * Get list of pending remote shares
	 *
	 * @param array $params empty
	 * @return \OC_OCS_Result
	 */
	public static function getOpenShares($params) {
		$externalManager = new Manager(
			\OC::$server->getDatabaseConnection(),
			Filesystem::getMountManager(),
			Filesystem::getLoader(),
			\OC::$server->getNotificationManager(),
			\OC::$server->getEventDispatcher(),
			\OC_User::getUser()
		);

		return new \OC_OCS_Result($externalManager->getOpenShares());
	}

	/**
	 * Accept a remote share
	 *
	 * @param array $params contains the shareID 'id' which should be accepted
	 * @return \OC_OCS_Result
	 */
	public static function acceptShare($params) {
		$externalManager = new Manager(
			\OC::$server->getDatabaseConnection(),
			Filesystem::getMountManager(),
			Filesystem::getLoader(),
			\OC::$server->getNotificationManager(),
			\OC::$server->getEventDispatcher(),
			\OC_User::getUser()
		);

		$shareInfo = $externalManager->getShare($params['id']);

		if ($externalManager->acceptShare((int) $params['id'])) {
			$dispatcher = \OC::$server->getEventDispatcher();
			$event = new GenericEvent(null,
				['shareAcceptedFrom' => $shareInfo['owner'],
					'sharedAcceptedBy' => $shareInfo['user'],
					'sharedItem' => $shareInfo['name'],
					'remoteUrl' => $shareInfo['remote']
				]
			);
			$dispatcher->dispatch('remoteshare.accepted', $event);
			return new \OC_OCS_Result();
		}

		// Make sure the user has no notification for something that does not exist anymore.
		$externalManager->processNotification((int) $params['id']);

		return new \OC_OCS_Result(null, 404, "wrong share ID, share doesn't exist.");
	}

	/**
	 * Decline a remote share
	 *
	 * @param array $params contains the shareID 'id' which should be declined
	 * @return \OC_OCS_Result
	 */
	public static function declineShare($params) {
		$externalManager = new Manager(
			\OC::$server->getDatabaseConnection(),
			Filesystem::getMountManager(),
			Filesystem::getLoader(),
			\OC::$server->getNotificationManager(),
			\OC::$server->getEventDispatcher(),
			\OC_User::getUser()
		);

		$shareInfo = $externalManager->getShare($params['id']);

		if ($externalManager->declineShare((int) $params['id'])) {
			$dispatcher = \OC::$server->getEventDispatcher();
			$event = new GenericEvent(null,
				['shareAcceptedFrom' => $shareInfo['owner'],
					'sharedAcceptedBy' => $shareInfo['user'],
					'sharedItem' => $shareInfo['name'],
					'remoteUrl' => $shareInfo['remote']
				]
			);
			$dispatcher->dispatch('remoteshare.declined', $event);
			return new \OC_OCS_Result();
		}

		// Make sure the user has no notification for something that does not exist anymore.
		$externalManager->processNotification((int) $params['id']);

		return new \OC_OCS_Result(null, 404, "wrong share ID, share doesn't exist.");
	}

	/**
	 * @param array $share Share with info from the share_external table
	 * @return array enriched share info with data from the filecache
	 */
	private static function extendShareInfo($share) {
		$view = new \OC\Files\View('/' . \OC_User::getUser() . '/files/');
		$info = $view->getFileInfo($share['mountpoint']);

		$share['mimetype'] = $info->getMimetype();
		$share['mtime'] = $info->getMtime();
		$share['permissions'] = $info->getPermissions();
		$share['type'] = $info->getType();
		$share['file_id'] = $info->getId();

		return $share;
	}

	/**
	 * List accepted remote shares
	 *
	 * @param array $params 
	 * @return \OC_OCS_Result
	 */
	public static function getShares($params) {
		$externalManager = new Manager(
			\OC::$server->getDatabaseConnection(),
			Filesystem::getMountManager(),
			Filesystem::getLoader(),
			\OC::$server->getNotificationManager(),
			\OC::$server->getEventDispatcher(),
			\OC_User::getUser()
		);

		$shares = $externalManager->getAcceptedShares();

		$shares = array_map('self::extendShareInfo', $shares);
	
		return new \OC_OCS_Result($shares);
	}

	/**
	 * Get info of a remote share
	 *
	 * @param array $params contains the shareID 'id'
	 * @return \OC_OCS_Result
	 */
	public static function getShare($params) {
		$externalManager = new Manager(
			\OC::$server->getDatabaseConnection(),
			Filesystem::getMountManager(),
			Filesystem::getLoader(),
			\OC::$server->getNotificationManager(),
			\OC::$server->getEventDispatcher(),
			\OC_User::getUser()
		);

		$shareInfo = $externalManager->getShare($params['id']);

		if ($shareInfo === false) {
			return new \OC_OCS_Result(null, 404, 'share does not exist');
		} else {
			$shareInfo = self::extendShareInfo($shareInfo);
			return new \OC_OCS_Result($shareInfo);
		}
	}

	/**
	 * Unshare a remote share
	 *
	 * @param array $params contains the shareID 'id' which should be unshared
	 * @return \OC_OCS_Result
	 */
	public static function unshare($params) {
		$externalManager = new Manager(
			\OC::$server->getDatabaseConnection(),
			Filesystem::getMountManager(),
			Filesystem::getLoader(),
			\OC::$server->getNotificationManager(),
			\OC::$server->getEventDispatcher(),
			\OC_User::getUser()
		);

		$shareInfo = $externalManager->getShare($params['id']);

		if ($shareInfo === false) {
			return new \OC_OCS_Result(null, 404, 'Share does not exist');
		}

		$mountPoint = '/' . \OC_User::getUser() . '/files' . $shareInfo['mountpoint'];

		if ($externalManager->removeShare($mountPoint) === true) {
			return new \OC_OCS_Result(null);
		} else {
			return new \OC_OCS_Result(null, 403, 'Could not unshare');
		}
	}
}
