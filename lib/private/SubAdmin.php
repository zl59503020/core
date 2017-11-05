<?php
/**
 * @author Arthur Schiwon <blizzz@arthur-schiwon.de>
 * @author Bart Visscher <bartv@thisnet.nl>
 * @author Georg Ehrke <georg@owncloud.com>
 * @author Lukas Reschke <lukas@statuscode.ch>
 * @author Morris Jobke <hey@morrisjobke.de>
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

namespace OC;

use OC\Hooks\PublicEmitter;
use OCP\IUser;
use OC\User\Manager as UserManager;
use OCP\IGroup;
use OC\Group\Manager as GroupManager;
use OCP\IDBConnection;
use OCP\ISubAdminManager;
use OC\MembershipManager;

class SubAdmin extends PublicEmitter implements ISubAdminManager {

	/** @var UserManager */
	private $userManager;

	/** @var GroupManager */
	private $groupManager;

	/** @var MembershipManager */
	private $membershipManager;

	/** @var IDBConnection */
	private $dbConn;

	/**
	 * @param UserManager $userManager
	 * @param GroupManager $groupManager
	 * @param MembershipManager $membershipManager
	 * @param IDBConnection $dbConn
	 */
	public function __construct(UserManager $userManager,
								GroupManager $groupManager,
								MembershipManager $membershipManager,
								IDBConnection $dbConn) {
		$this->userManager = $userManager;
		$this->groupManager = $groupManager;
		$this->membershipManager = $membershipManager;
		$this->dbConn = $dbConn;
	}

	/**
	 * add a SubAdmin
	 * @param IUser $user user to be SubAdmin
	 * @param IGroup $group group $user becomes subadmin of
	 * @return bool
	 */
	public function createSubAdmin(IUser $user, IGroup $group) {
		$result = $this->membershipManager->addGroupAdmin($user->getID(), $group->getID());

		if ($result) {
			$this->emit('\OC\SubAdmin', 'postCreateSubAdmin', [$user, $group]);
			\OC_Hook::emit("OC_SubAdmin", "post_createSubAdmin", ["gid" => $group->getGID()]);
		}
		return $result;
	}

	/**
	 * delete a SubAdmin
	 * @param IUser $user the user that is the SubAdmin
	 * @param IGroup $group the group
	 * @return bool
	 */
	public function deleteSubAdmin(IUser $user, IGroup $group) {
		$result = $this->membershipManager->removeGroupAdmin($user->getID(), $group->getID());

		if ($result) {
			$this->emit('\OC\SubAdmin', 'postDeleteSubAdmin', [$user, $group]);
			\OC_Hook::emit("OC_SubAdmin", "post_deleteSubAdmin", ["gid" => $group->getGID()]);
		}
		return $result;
	}

	/**
	 * get groups of a SubAdmin
	 * @param IUser $user the SubAdmin
	 * @return IGroup[]
	 */
	public function getSubAdminsGroups(IUser $user) {
		return array_map(function($backendGroup) {
			// Get \OCP\IGroup object for each backend group and cache
			return $this->groupManager->getByBackendGroup($backendGroup);
		}, $this->membershipManager->getAdminBackendGroups($user->getUID()));
	}

	/**
	 * get SubAdmins of a group
	 * @param IGroup $group the group
	 * @return IUser[]
	 */
	public function getGroupsSubAdmins(IGroup $group) {
		return array_map(function($account) {
			// Get \OCP\IGroup object for each backend group and cache
			return $this->userManager->getByAccount($account);
		}, $this->membershipManager->getGroupAdminAccounts($group->getGID()));
	}

	/**
	 * get all SubAdmins
	 * @return array
	 */
	public function getAllSubAdmins() {
		$subAdmins =  array_map(function($account) {
			// Get \OCP\IGroup object for each backend group and cache
			return $this->userManager->getByAccount($account);
		}, $this->membershipManager->getGroupAdminAccounts());

		$subAdminsGroups = [];
		foreach($subAdmins as $subAdmin) {
			/** @var \OC\User\User $subAdmin */
			$subAdminBackendGroups = $this->membershipManager->getAdminBackendGroups($subAdmin->getUID());
			foreach($subAdminBackendGroups as $backendGroup) {
				$group = $this->groupManager->getByBackendGroup($backendGroup);
				$subAdminsGroups[] = [
					'user'  => $subAdmin,
					'group' => $group
				];
			}
		}
		return $subAdminsGroups;
	}

	/**
	 * checks if a user is a SubAdmin of a group
	 * @param IUser $user
	 * @param IGroup $group
	 * @return bool
	 */
	public function isSubAdminofGroup(IUser $user, IGroup $group) {
		return $this->membershipManager->isGroupAdmin($user->getUID(), $group->getGID());
	}

	/**
	 * checks if a user is a SubAdmin
	 * @param IUser $user
	 * @return bool
	 */
	public function isSubAdmin(IUser $user) {
		return $this->membershipManager->isGroupAdmin($user->getUID());
	}

	/**
	 * checks if a user is a accessible by a subadmin
	 * @param IUser $subadmin
	 * @param IUser $user
	 * @return bool
	 */
	public function isUserAccessible($subadmin, $user) {
		if($this->groupManager->isAdmin($user->getUID())) {
			return false;
		}

		// Get all subadmin groups of the user $subadmin
		// Check if user $subadmin has subadmin groups or
		// whether user $user is in one of $subadmin groups
		$accessibleGroups = $this->getSubAdminsGroups($subadmin);
		foreach($accessibleGroups as $accessibleGroup) {
			if($accessibleGroup->inGroup($user)) {
				return true;
			}
		}
		return false;
	}
}