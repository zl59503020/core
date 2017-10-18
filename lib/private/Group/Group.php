<?php
/**
 * @author Arthur Schiwon <blizzz@arthur-schiwon.de>
 * @author Bart Visscher <bartv@thisnet.nl>
 * @author Lukas Reschke <lukas@statuscode.ch>
 * @author Morris Jobke <hey@morrisjobke.de>
 * @author Robin Appelman <icewind@owncloud.com>
 * @author Robin McCorkell <robin@mccorkell.me.uk>
 * @author Roeland Jago Douma <rullzer@owncloud.com>
 * @author Thomas Müller <thomas.mueller@tmit.eu>
 * @author Vincent Petry <pvince81@owncloud.com>
 * @author Piotr Mrowczynski <piotr@owncloud.com>
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

namespace OC\Group;

use OCP\IGroup;
use OCP\IUser;

class Group implements IGroup {

	/**
	 * @var \OC\Group\BackendGroup $backendGroup
	 */
	private $backendGroup;

	/**
	 * @var \OC\Group\GroupMapper $groupMapper
	 */
	private $groupMapper;

	/**
	 * @var \OC\MembershipManager $membershipManager
	 */
	private $membershipManager;

	/**
	 * @var \OC\Group\Manager $groupManager
	 */
	private $groupManager;

	/**
	 * @var \OC\User\Manager $userManager
	 */
	private $userManager;

	/**
	 * @var \OC\User\User[]|null $usersCache
	 */
	private $usersCache = null;

	/**
	 * @param \OC\Group\BackendGroup $backendGroup
	 * @param \OC\Group\GroupMapper $groupMapper
	 * @param \OC\Group\Manager $groupManager
	 * @param \OC\User\Manager $userManager
	 * @param \OC\MembershipManager $membershipManager
	 */
	public function __construct(\OC\Group\BackendGroup $backendGroup, \OC\Group\GroupMapper $groupMapper,
								\OC\Group\Manager $groupManager, \OC\User\Manager $userManager,
								\OC\MembershipManager $membershipManager) {
		$this->backendGroup = $backendGroup;
		$this->groupMapper = $groupMapper;
		$this->groupManager = $groupManager;
		$this->userManager = $userManager;
		$this->membershipManager = $membershipManager;
	}

	public function getID() {
		return $this->backendGroup->getId();
	}

	public function getGID() {
		return $this->backendGroup->getGroupId();
	}

	public function getDisplayName() {
		return $this->backendGroup->getDisplayName();
	}

	/**
	 * get all users in the group
	 *
	 * @return \OC\User\User[]
	 */
	public function getUsers() {
		// If users were retrieved already with getUsers, fetch them from cache
		if (!is_null($this->usersCache)) {
			return $this->usersCache;
		}

		$this->usersCache = array_map(function($account) {
			// Get Group object for each backend group and cache
			return $this->userManager->getByAccount($account);
		}, $this->membershipManager->getGroupUserAccountsById($this->backendGroup->getId()));
		return $this->usersCache;
	}

	/**
	 * check if a user is in the group
	 *
	 * @param IUser $user
	 * @return bool
	 */
	public function inGroup($user) {
		$this->membershipManager->isGroupUserById($user->getID(), $this->backendGroup->getId());
	}

	/**
	 * add a user to the group
	 *
	 * @param \OC\User\User $user
	 */
	public function addUser($user) {
		$this->groupManager->emit('\OC\Group', 'preAddUser', [$this, $user]);
		$backendClass = $this->getBackendClassName();
		if ($this->groupManager->getBackend($backendClass)->implementsActions(\OC\Group\Backend::ADD_TO_GROUP)) {
			$this->groupManager->getBackend($backendClass)->addToGroup($user->getUID(), $this->backendGroup->getGroupId());
		}

		$this->membershipManager->addGroupUser($user->getID(), $this->backendGroup->getId());

		// If users were retrieved already with getUsers and cached, we can safely cache this user
		if (!is_null($this->usersCache)) {
			$this->usersCache[$user->getUID()] = $user;
		}
		$this->groupManager->emit('\OC\Group', 'postAddUser', [$this, $user]);
	}

	/**
	 * remove a user from the group
	 *
	 * @param \OC\User\User $user
	 */
	public function removeUser($user) {
		$this->groupManager->emit('\OC\Group', 'preRemoveUser', [$this, $user]);
		$backendClass = $this->getBackendClassName();
		if ($this->groupManager->getBackend($backendClass)->implementsActions(\OC\Group\Backend::REMOVE_FROM_GROUP)) {
			$this->groupManager->getBackend($backendClass)->removeFromGroup($user->getUID(), $this->backendGroup->getGroupId());
		}

		$this->membershipManager->removeGroupUser($user->getID(), $this->backendGroup->getId());

		// If users were retrieved already with getUsers and cached, we can safely remove cache for this user
		if (!is_null($this->usersCache) && isset($this->usersCache[$user->getUID()])) {
			unset($this->usersCache[$user->getUID()]);
		}
		$this->groupManager->emit('\OC\Group', 'postRemoveUser', [$this, $user]);
	}

	/**
	 * search for users in the group by userid
	 *
	 * @param string $search
	 * @param int $limit
	 * @param int $offset
	 * @return \OC\User\User[]
	 */
	public function searchUsers($search, $limit = null, $offset = null) {
		return array_map(function($account) {
			// Get Group object for each backend group and cache
			return $this->userManager->getByAccount($account);
		}, $this->membershipManager->find($this->backendGroup->getGroupId(), $search, $limit, $offset));
	}

	/**
	 * returns the number of users matching the search string
	 *
	 * @param string $search
	 * @return int|bool
	 */
	public function count($search = '') {
		return $this->membershipManager->count($this->backendGroup->getGroupId(), $search);
	}

	/**
	 * search for users in the group by displayname
	 *
	 * @param string $search
	 * @param int $limit
	 * @param int $offset
	 * @return \OC\User\User[]
	 */
	public function searchDisplayName($search, $limit = null, $offset = null) {
		return $this->searchUsers($search, $limit, $offset);
	}

	/**
	 * delete the group
	 *
	 * @return bool
	 */
	public function delete() {
		// Prevent users from deleting group admin
		if ($this->getGID() === 'admin') {
			return false;
		}

		// Remove members from the internal group
		$this->membershipManager->removeGroupMembers($this->backendGroup->getId());

		// Remove group from backend if possible
		$this->groupManager->emit('\OC\Group', 'preDelete', [$this]);
		$backendClass = $this->getBackendClassName();
		if ($this->groupManager->getBackend($backendClass)->implementsActions(\OC\Group\Backend::DELETE_GROUP)) {
			$this->groupManager->getBackend($backendClass)->deleteGroup($this->getGID());
		}

		// Remove group internally
		$this->groupMapper->delete($this->backendGroup);

		// Emit post delete
		$this->groupManager->emit('\OC\Group', 'postDelete', [$this]);
		return true;
	}

	/**
	 * Returns the backend for this group (depreciated and returns null)
	 *
	 * @return null
	 * @deprecated 10.0.0 use getBackendClassName() of \OCP\IGroup
	 * @since 10.0.0
	 */
	public function getBackend() {
		return null;
	}

	/**
	 * Returns the backend class for this group
	 *
	 * @return string
	 * @since 10.0.0
	 */
	public function getBackendClassName() {
		return $this->backendGroup->getBackend();
	}
}