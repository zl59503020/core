<?php
/**
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

namespace Test;

use OC\Group\BackendGroup;
use OC\Group\GroupMapper;
use OC\User\Account;
use OC\User\AccountMapper;
use OC\MembershipManager;
use OCP\IConfig;
use OCP\IDBConnection;
use Test\TestCase;
use OCP\AppFramework\Db\DoesNotExistException;
use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

/**
 * Class MembershipManagerTest
 *
 * @group DB
 *
 * @package Test
 */
class MembershipManagerTest extends TestCase {

	/** @var IConfig | \PHPUnit_Framework_MockObject_MockObject */
	protected $config;

	/** @var IDBConnection */
	protected $connection;

	/** @var MembershipManager */
	protected $manager;

	public static function setUpBeforeClass() {
		parent::setUpBeforeClass();
		self::clearDB();

		// create test groups and accounts
		for ($i = 1; $i <= 4; $i++) {
			self::createBackendGroup($i);
			self::createAccount($i);
		}
	}

	private static function createBackendGroup($i) {
		$groupMapper = \OC::$server->getGroupMapper();

		$backendGroup = new BackendGroup();
		$backendGroup->setGroupId("testgroup$i");
		$backendGroup->setDisplayName("TestGroup$i");
		$backendGroup->setBackend(self::class);

		$groupMapper->insert($backendGroup);
	}

	private static function getAccount($i) {
		$accountMapper = \OC::$server->getAccountMapper();
		return $accountMapper->getByUid("testaccount$i");
	}

	private static function getBackendGroup($i) {
		$groupMapper = \OC::$server->getGroupMapper();
		return $groupMapper->getGroup("testgroup$i");
	}

	private static function createAccount($i) {
		$accountMapper = \OC::$server->getAccountMapper();

		$account = new Account();
		$account->setUserId("testaccount$i");
		$account->setDisplayName("Test User $i");
		$account->setEmail("test$i@user.com");
		$account->setBackend(self::class);
		$account->setHome("/foo/testaccount$i");
		$account->setQuota($i);
		$account->setState(Account::STATE_ENABLED);
		$account->setLastLogin($i);

		$accountMapper->insert($account);

		$accountMapper->setTermsForAccount($account->getId(), ["Term $i A","Term $i B","Term $i C"]);
	}

	public function setUp() {
		parent::setUp();

		$this->config = $this->createMock(IConfig::class);

		$this->connection = \OC::$server->getDatabaseConnection();

		$this->manager = new MembershipManager(
			$this->connection,
			$this->config
		);
	}

	private static function clearDB() {
		\OC::$server->getDatabaseConnection()->executeQuery('DELETE FROM `*PREFIX*memberships`');
		\OC::$server->getDatabaseConnection()->executeQuery('DELETE FROM `*PREFIX*backend_groups`');
		\OC::$server->getDatabaseConnection()->executeQuery('DELETE FROM `*PREFIX*accounts`');
	}

	public static function tearDownAfterClass () {
		self::clearDB();
		parent::tearDownAfterClass();
	}

	public function tearDown () {
		// Cleanup groups
		$this->cleanupGroups();
		parent::tearDown();
	}

	/**
	 * Test will prepare 4 accounts and 2 groups. 2 of them will be added as user and admin to the group,
	 * 1 as admin and 1 as user. Verify integrity and test all retrieval functions
	 *
	 * getAdminAccounts, getGroupUserAccounts, getGroupAdminAccounts, getGroupUserAccounts,
	 * getGroupUserAccountsById, getAdminBackendGroups, getUserBackendGroups, etUserBackendGroupsById,
	 * isGroupAdmin, isGroupUser, isGroupUserById
	 */
	public function testRegularAccountOperations() {
		$result = $this->manager->getGroupAdminAccounts();
		$this->assertEmpty($result);

		$group1 = self::getBackendGroup(1);
		$this->assertInstanceOf(BackendGroup::class, $group1);
		$result = $this->manager->getGroupUserAccounts($group1->getGroupId());
		$this->assertEmpty($result);
		$group2 = self::getBackendGroup(2);
		$this->assertInstanceOf(BackendGroup::class, $group2);
		$result = $this->manager->getGroupUserAccounts($group2->getGroupId());
		$this->assertEmpty($result);

		// Check adding as both ADMIN and USER
		for ($i = 1; $i <= 2; $i++) {
			$account = self::getAccount($i);
			$this->assertInstanceOf(Account::class, $account);

			$this->addUserAndAdmin($account, $group1);

			$result = $this->manager->getGroupAdminAccounts();
			$this->assertCount($i, $result);
		}

		// Check adding as only ADMIN
		$account = self::getAccount(3);
		$this->assertInstanceOf(Account::class, $account);
		$this->addAdmin($account, $group2);

		// Check adding as only USER
		$account = self::getAccount(4);
		$this->assertInstanceOf(Account::class, $account);
		$this->addUser($account, $group2);

		// Check that we have 3 total ADMIN accounts
		$result = $this->manager->getGroupAdminAccounts();
		$this->assertCount(3, $result);

		// Check that we have 2 ADMIN accounts in group 1
		$result = $this->manager->getGroupAdminAccounts($group1->getGroupId());
		$this->assertCount(2, $result);

		// Check that we have 2 USER accounts in group 1
		$result = $this->manager->getGroupUserAccounts($group1->getGroupId());
		$this->assertCount(2, $result);
		$result = $this->manager->getGroupUserAccountsById($group1->getId());
		$this->assertCount(2, $result);

		// Check that we have 1 ADMIN account in group 2
		$result = $this->manager->getGroupAdminAccounts($group2->getGroupId());
		$this->assertCount(1, $result);
		$this->assertEquals("testaccount3", $result[0]->getUserId());
		$this->assertEquals("Test User 3", $result[0]->getDisplayName());

		// Check that we have 1 USER account in group 2
		$result = $this->manager->getGroupUserAccounts($group2->getGroupId());
		$this->assertCount(1, $result);
		$result = $this->manager->getGroupUserAccountsById($group2->getId());
		$this->assertCount(1, $result);
		$this->assertEquals("testaccount4", $result[0]->getUserId());
		$this->assertEquals("Test User 4", $result[0]->getDisplayName());
	}

	/**
	 * Test that get functions return consistent results
	 */
	public function testGetConsistency() {
		$accountId = 1;
		$groupId = 1;
		$account = self::getAccount($accountId);
		$group = self::getBackendGroup($groupId);

		// Add as group user
		$check = $this->manager->addGroupUser($account->getId(), $group->getId());
		$this->assertEquals(true, $check);
		$check = $this->manager->addGroupAdmin($account->getId(), $group->getId());
		$this->assertEquals(true, $check);

		$backendGroups = $this->manager->getUserBackendGroups($account->getUserId());
		$this->checkConsistencyGroup($backendGroups[0], $groupId);

		$backendGroups = $this->manager->getAdminBackendGroups($account->getUserId());
		$this->checkConsistencyGroup($backendGroups[0], $groupId);

		$accounts = $this->manager->getGroupUserAccounts($group->getGroupId());
		$this->checkConsistencyAccount($accounts[0], $accountId);

		$accounts = $this->manager->getGroupAdminAccounts($group->getGroupId());
		$this->checkConsistencyAccount($accounts[0], $accountId);
	}

	/**
	 * Test find, findById and count method, also with offsets.
	 */
	public function testFind() {
		$result = $this->manager->getGroupAdminAccounts();
		$this->assertEmpty($result);

		$group1 = self::getBackendGroup(1);
		$group2 = self::getBackendGroup(2);

		// Check adding as both ADMIN and USER
		for ($i = 1; $i <= 2; $i++) {
			$account = self::getAccount($i);
			$this->addUserAndAdmin($account, $group1);
		}

		$account = self::getAccount(3);
		$this->addAdmin($account, $group2);

		$account = self::getAccount(4);
		$this->addUser($account, $group2);

		// Test simple find in group 1 by userId
		$result = $this->manager->find($group1->getGroupId(), "testaccount");
		$this->assertEquals(2, count($result));
		$this->assertEquals("testaccount1", array_shift($result)->getUserId());

		// Test simple find in group 1 by account id
		$result = $this->manager->findById($group1->getId(), "testaccount");
		$this->assertEquals(2, count($result));
		$this->assertEquals("testaccount1", array_shift($result)->getUserId());

		// Test simple find in group 1 with offsets by userId
		$result = $this->manager->find($group1->getGroupId(), "testaccount",1, 0);
		$this->assertEquals(1, count($result));
		$this->assertEquals("testaccount1", array_shift($result)->getUserId());
		$result = $this->manager->find($group1->getGroupId(), "testaccount",1, 1);
		$this->assertEquals(1, count($result));
		$this->assertEquals("testaccount2", array_shift($result)->getUserId());

		// Test simple find in group 2 by userId - here we expect only 1 user, since the other is admin
		$result = $this->manager->find($group2->getGroupId(), "testaccount");
		$this->assertEquals(1, count($result));
		$this->assertEquals("testaccount4", array_shift($result)->getUserId());

		// Test finding by display name in group 2
		$result = $this->manager->find($group2->getGroupId(), "Test User 4");
		$this->assertEquals(1, count($result));
		$this->assertEquals("testaccount4", array_shift($result)->getUserId());

		// Test finding by email name in group 2
		$result = $this->manager->find($group2->getGroupId(), "test4@user.com");
		$this->assertEquals(1, count($result));
		$this->assertEquals("testaccount4", array_shift($result)->getUserId());

		// Test finding by email name in group 1, with medial search
		$this->config->expects($this->any())
			->method('getSystemValue')
			->willReturn(true);
		$result = $this->manager->find($group1->getGroupId(), "@user.com");
		$this->assertEquals(2, count($result));

		// Test finding by term in group 2
		$result = $this->manager->find($group2->getGroupId(), "Term 4 A");
		$this->assertEquals(1, count($result));
		$this->assertEquals("testaccount4", array_shift($result)->getUserId());

		// Test finding by term in group 1
		$result = $this->manager->find($group1->getGroupId(), "Term 1 A");
		$this->assertEquals(1, count($result));
		$this->assertEquals("testaccount1", array_shift($result)->getUserId());

		// Test count with empty string in group 1 - will match all users in the group
		$result = $this->manager->count($group1->getGroupId(), '');
		$this->assertEquals(2, $result);

		// Test count by term in both group 1 and 2
		$result = $this->manager->count($group1->getGroupId(), "Term ");
		$this->assertEquals(2, $result);
		$result = $this->manager->count($group2->getGroupId(), "Term ");
		$this->assertEquals(1, $result);
	}

	/**
	 * Test will prepare 4 accounts which will be added as user and admin to the group
	 *
	 * 1. Remove single admin account
	 * 2. Remove single user account
	 * 3. Remove all memberships from account
	 */
	public function testRemoveAccountOperations() {
		$result = $this->manager->getGroupAdminAccounts();
		$this->assertEmpty($result);
		$group = self::getBackendGroup(1);
		$result = $this->manager->getGroupUserAccounts($group->getGroupId());
		$this->assertEmpty($result);

		// Check adding as both ADMIN and USER
		for ($i = 1; $i <= 4; $i++) {
			$account = self::getAccount($i);
			$this->addUserAndAdmin($account, $group);

			$result = $this->manager->getGroupAdminAccounts();
			$this->assertCount($i, $result);
			$result = $this->manager->getGroupUserAccounts($group->getGroupId());
			$this->assertCount($i, $result);
		}

		// Remove 1 ADMIN account
		$account = self::getAccount(1);
		$this->assertTrue($this->manager->isGroupAdmin($account->getUserId(), $group->getGroupId()));
		$this->assertTrue($this->manager->isGroupUser($account->getUserId(), $group->getGroupId()));
		$result = $this->manager->removeGroupAdmin($account->getId(), $group->getId());
		$this->assertTrue($result);
		$this->assertCount(3, $this->manager->getGroupAdminAccounts());
		$this->assertCount(4, $this->manager->getGroupUserAccounts($group->getGroupId()));
		$this->assertFalse($this->manager->isGroupAdmin($account->getUserId(), $group->getGroupId()));
		$this->assertTrue($this->manager->isGroupUser($account->getUserId(), $group->getGroupId()));

		// Remove 1 USER account
		$account = self::getAccount(2);
		$this->assertTrue($this->manager->isGroupAdmin($account->getUserId(), $group->getGroupId()));
		$this->assertTrue($this->manager->isGroupUser($account->getUserId(), $group->getGroupId()));
		$result = $this->manager->removeGroupUser($account->getId(), $group->getId());
		$this->assertTrue($result);
		$this->assertCount(3, $this->manager->getGroupAdminAccounts());
		$this->assertCount(3, $this->manager->getGroupUserAccounts($group->getGroupId()));
		$this->assertTrue($this->manager->isGroupAdmin($account->getUserId(), $group->getGroupId()));
		$this->assertFalse($this->manager->isGroupUser($account->getUserId(), $group->getGroupId()));

		// Remove account memberships
		$account = self::getAccount(3);
		$this->assertTrue($this->manager->isGroupAdmin($account->getUserId(), $group->getGroupId()));
		$this->assertTrue($this->manager->isGroupUser($account->getUserId(), $group->getGroupId()));
		$result = $this->manager->removeMemberships($account->getId());
		$this->assertTrue($result);
		$this->assertCount(2, $this->manager->getGroupAdminAccounts());
		$this->assertCount(2, $this->manager->getGroupUserAccounts($group->getGroupId()));
		$this->assertFalse($this->manager->isGroupAdmin($account->getUserId(), $group->getGroupId()));
		$this->assertFalse($this->manager->isGroupUser($account->getUserId(), $group->getGroupId()));

		// After removal, remove memberships should return false since no memberships were removed
		$result = $this->manager->removeMemberships($account->getId());
		$this->assertFalse($result);
	}

	/**
	 * Test that deleting group should result in deleting all users, and violating that
	 * should rise exception
	 */
	public function testRemoveIncorrectIds() {
		$result = $this->manager->getGroupAdminAccounts();
		$this->assertEmpty($result);
		$group = self::getBackendGroup(1);
		$result = $this->manager->getGroupUserAccounts($group->getGroupId());
		$this->assertEmpty($result);

		// Check adding as both ADMIN and USER
		for ($i = 1; $i <= 4; $i++) {
			$account = self::getAccount($i);
			$this->addUserAndAdmin($account, $group);
		}

		$this->assertCount(4, $this->manager->getGroupAdminAccounts());
		$this->assertCount(4, $this->manager->getGroupUserAccounts($group->getGroupId()));

		// Test that removal with null instead of integer will fail
		$result = $this->manager->removeMemberships(null);
		$this->assertFalse($result);
		$this->assertCount(4, $this->manager->getGroupAdminAccounts());
		$this->assertCount(4, $this->manager->getGroupUserAccounts($group->getGroupId()));

		$result = $this->manager->removeGroupMembers(null);
		$this->assertFalse($result);
		$this->assertCount(4, $this->manager->getGroupAdminAccounts());
		$this->assertCount(4, $this->manager->getGroupUserAccounts($group->getGroupId()));

		$result = $this->manager->removeGroupUser(null, null);
		$this->assertFalse($result);
		$this->assertCount(4, $this->manager->getGroupAdminAccounts());
		$this->assertCount(4, $this->manager->getGroupUserAccounts($group->getGroupId()));

		$result = $this->manager->removeGroupAdmin(null, null);
		$this->assertFalse($result);
		$this->assertCount(4, $this->manager->getGroupAdminAccounts());
		$this->assertCount(4, $this->manager->getGroupUserAccounts($group->getGroupId()));
	}

	/**
	 * Test that deleting group should result in deleting all users, and violating that
	 * should rise exception
	 */
	public function testRemoveFailed() {
		// Test foreign keys only on databases which fully support it
		if ($this->connection->getDatabasePlatform() instanceof SqlitePlatform) {
			$this->markTestSkipped("Foreign key are not fully supported on SQLite, skip test");
			return;
		}
		$account = self::getAccount(1);
		$this->assertInstanceOf(Account::class, $account);
		$group = self::getBackendGroup(1);
		$this->assertInstanceOf(BackendGroup::class, $group);

		// Add as group user
		$check = $this->manager->addGroupUser($account->getId(), $group->getId());
		$this->assertEquals(true, $check);

		$accountMapper = \OC::$server->getAccountMapper();
		$groupMapper = \OC::$server->getGroupMapper();

		$thrown = false;
		try {
			$accountMapper->delete($account);
		} catch (\Exception $e) {
			$thrown = true;
			$this->assertInstanceOf(ForeignKeyConstraintViolationException::class, $e);
		}
		$this->assertTrue($thrown);

		try {
			$groupMapper->delete($group);
		} catch (\Exception $e) {
			$this->assertInstanceOf(ForeignKeyConstraintViolationException::class, $e);
		}
		$this->assertTrue($thrown);
	}

	/**
	 * Doubled insert of the user as a member should fail
	 */
	public function testInsertFailed() {
		$account = self::getAccount(1);
		$this->assertInstanceOf(Account::class, $account);
		$group1 = self::getBackendGroup(1);
		$this->assertInstanceOf(BackendGroup::class, $group1);

		// Add as group user
		$check = $this->manager->addGroupUser($account->getId(), $group1->getId());
		$this->assertEquals(true, $check);

		// This should fail now
		$thrown = false;
		try {
			$this->manager->addGroupUser($account->getId(), $group1->getId());
		} catch (\Exception $e) {
			$thrown = true;
			$this->assertInstanceOf(UniqueConstraintViolationException::class, $e);
		}
		$this->assertTrue($thrown);

		// Add as group admin
		$check = $this->manager->addGroupAdmin($account->getId(), $group1->getId());
		$this->assertEquals(true, $check);

		// This should fail now
		$thrown = false;
		try {
			$this->manager->addGroupAdmin($account->getId(), $group1->getId());
		} catch (\Exception $e) {
			$thrown = true;
			$this->assertInstanceOf(UniqueConstraintViolationException::class, $e);
		}
		$this->assertTrue($thrown);
	}

	/**
	 * @param BackendGroup $group
	 * @param int $i
	 */
	private function checkConsistencyGroup($group, $i) {
		$this->assertEquals("testgroup$i", $group->getGroupId());
		$this->assertEquals("TestGroup$i", $group->getDisplayName());
		$this->assertEquals(self::class, $group->getBackend());
	}

	/**
	 * @param Account $account
	 * @param int $i
	 */
	private function checkConsistencyAccount($account, $i) {
		$this->assertEquals("testaccount$i", $account->getUserId());
		$this->assertEquals("Test User $i", $account->getDisplayName());
		$this->assertEquals("test$i@user.com", $account->getEmail());
		$this->assertEquals(self::class, $account->getBackend());
		$this->assertEquals("/foo/testaccount$i", $account->getHome());
		$this->assertEquals($i, intval($account->getQuota()));
		$this->assertEquals(Account::STATE_ENABLED, $account->getState());
		$this->assertEquals($i, intval($account->getLastLogin()));
		$account->setUserId("testaccount$i");
	}

	/**
	 * @param Account $account
	 * @param BackendGroup $group
	 */
	private function addUserAndAdmin($account, $group) {
		// Add as both group user and group admin
		$check = $this->manager->addGroupUser($account->getId(), $group->getId());
		$this->assertEquals(true, $check);
		$check = $this->manager->addGroupAdmin($account->getId(), $group->getId());
		$this->assertEquals(true, $check);

		$result = $this->manager->getAdminBackendGroups($account->getUserId());
		$this->assertCount(1, $result);
		$result = $this->manager->getUserBackendGroups($account->getUserId());
		$this->assertCount(1, $result);
		$result = $this->manager->getUserBackendGroupsById($account->getId());
		$this->assertCount(1, $result);

		$result = $this->manager->isGroupAdmin($account->getUserId(), $group->getGroupId());
		$this->assertEquals(true, $result);
		$result = $this->manager->isGroupUser($account->getUserId(), $group->getGroupId());
		$this->assertEquals(true, $result);
		$result = $this->manager->isGroupUserById($account->getId(), $group->getId());
		$this->assertEquals(true, $result);
	}

	/**
	 * @param Account $account
	 * @param BackendGroup $group
	 */
	private function addAdmin($account, $group) {
		// Add only as group admin in another group
		$check = $this->manager->addGroupAdmin($account->getId(), $group->getId());
		$this->assertEquals(true, $check);

		// After adding
		$result = $this->manager->getAdminBackendGroups($account->getUserId());
		$this->assertCount(1, $result);
		$result = $this->manager->getUserBackendGroups($account->getUserId());
		$this->assertCount(0, $result);
		$result = $this->manager->getUserBackendGroupsById($account->getId());
		$this->assertCount(0, $result);
		$result = $this->manager->isGroupAdmin($account->getUserId(), $group->getGroupId());
		$this->assertEquals(true, $result);
		$result = $this->manager->isGroupUser($account->getUserId(), $group->getGroupId());
		$this->assertEquals(false, $result);
		$result = $this->manager->isGroupUserById($account->getId(), $group->getId());
		$this->assertEquals(false, $result);
	}

	/**
	 * @param Account $account
	 * @param BackendGroup $group
	 */
	private function addUser($account, $group) {
		// Add only as group user in another group
		$check = $this->manager->addGroupUser($account->getId(), $group->getId());
		$this->assertEquals(true, $check);

		// After adding
		$result = $this->manager->getAdminBackendGroups($account->getUserId());
		$this->assertCount(0, $result);
		$result = $this->manager->getUserBackendGroups($account->getUserId());
		$this->assertCount(1, $result);
		$result = $this->manager->getUserBackendGroupsById($account->getId());
		$this->assertCount(1, $result);
		$result = $this->manager->isGroupAdmin($account->getUserId(), $group->getGroupId());
		$this->assertEquals(false, $result);
		$result = $this->manager->isGroupUser($account->getUserId(), $group->getGroupId());
		$this->assertEquals(true, $result);
		$result = $this->manager->isGroupUserById($account->getId(), $group->getId());
		$this->assertEquals(true, $result);
	}

	private function cleanupGroups() {
		// Remove using group operations
		for ($i = 1; $i <= 4; $i++) {
			$group = self::getBackendGroup($i);
			$this->manager->removeGroupMembers($group->getId());
			$result = $this->manager->getGroupUserAccounts($group->getGroupId());
			$this->assertCount(0, $result);
			$result = $this->manager->getGroupUserAccountsById($group->getId());
			$this->assertCount(0, $result);
			$result = $this->manager->getGroupAdminAccounts($group->getGroupId());
			$this->assertCount(0, $result);
		}

		// Verify empty
		$result = $this->manager->getGroupAdminAccounts();
		$this->assertCount(0, $result);

		// Verify empty
		for ($i = 1; $i <= 4; $i++) {
			$account = self::getAccount($i);
			$result = $this->manager->getAdminBackendGroups($account->getUserId());
			$this->assertCount(0, $result);
			$result = $this->manager->getUserBackendGroups($account->getUserId());
			$this->assertCount(0, $result);
			$result = $this->manager->getUserBackendGroupsById($account->getId());
			$this->assertCount(0, $result);
		}
	}
}