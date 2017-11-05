<?php
/**
 * @author Arthur Schiwon <blizzz@arthur-schiwon.de>
 * @author Joas Schilling <coding@schilljs.com>
 * @author Jörn Friedrich Dreyer <jfd@butonic.de>
 * @author Lukas Reschke <lukas@statuscode.ch>
 * @author Michael U <mdusher@users.noreply.github.com>
 * @author Morris Jobke <hey@morrisjobke.de>
 * @author Robin Appelman <icewind@owncloud.com>
 * @author Thomas Müller <thomas.mueller@tmit.eu>
 * @author Tom Needham <tom@owncloud.com>
 * @author Victor Dubiniuk <dubiniuk@owncloud.com>
 * @author Vincent Chan <plus.vincchan@gmail.com>
 * @author Vincent Petry <pvince81@owncloud.com>
 * @author Volkan Gezer <volkangezer@gmail.com>
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

namespace OC\User;

use OC\Hooks\PublicEmitter;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\ILogger;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IConfig;
use OCP\User\IProvidesExtendedSearchBackend;
use OCP\User\IProvidesEMailBackend;
use OCP\User\IProvidesQuotaBackend;
use OCP\UserInterface;
use Symfony\Component\EventDispatcher\GenericEvent;
use OC\MembershipManager;

/**
 * Class User Manager. This class is responsible for access to the \OC\User\User
 * classes and their caching, providing optimal access.
 *
 *
 * Hooks available in scope \OC\User:
 * - preSetPassword(\OC\User\User $user, string $password, string $recoverPassword)
 * - postSetPassword(\OC\User\User $user, string $password, string $recoverPassword)
 * - preDelete(\OC\User\User $user)
 * - postDelete(\OC\User\User $user)
 * - preCreateUser(string $uid, string $password)
 * - postCreateUser(\OC\User\User $user, string $password)
 * - change(\OC\User\User $user)
 *
 * @package OC\User
 */
class Manager extends PublicEmitter implements IUserManager {
	/** @var UserInterface[] $backends */
	private $backends = [];

	/** @var User[] $cachedUsers */
	private $cachedUsers = [];

	/** @var IConfig $config */
	private $config;

	/** @var ILogger $logger */
	private $logger;

	/** @var AccountMapper */
	private $accountMapper;

	/** @var MembershipManager */
	private $membershipManager;

	/**
	 * @param IConfig $config
	 * @param ILogger $logger
	 * @param AccountMapper $accountMapper
	 * @param MembershipManager $membershipManager
	 */
	public function __construct(IConfig $config, ILogger $logger, AccountMapper $accountMapper, MembershipManager $membershipManager) {
		$this->config = $config;
		$this->logger = $logger;
		$this->accountMapper = $accountMapper;
		$this->membershipManager = $membershipManager;
		$cachedUsers = &$this->cachedUsers;
		$this->listen('\OC\User', 'postDelete', function ($user) use (&$cachedUsers) {
			/** @var \OC\User\User $user */
			unset($cachedUsers[$user->getUID()]);
		});
	}

	/**
	 * only used for unit testing
	 *
	 * @param AccountMapper $mapper
	 * @param array $backends
	 * @return array
	 */
	public function reset(AccountMapper $mapper, $backends) {
		$return = [$this->accountMapper, $this->backends];
		$this->accountMapper = $mapper;
		$this->backends = $backends;

		return $return;
	}

	/**
	 * Get the active backends
	 * @return \OCP\UserInterface[]
	 */
	public function getBackends() {
		return array_values($this->backends);
	}

	/**
	 * register a user backend
	 *
	 * @param \OCP\UserInterface $backend
	 */
	public function registerBackend($backend) {
		$this->backends[get_class($backend)] = $backend;
	}

	/**
	 * remove a user backend
	 *
	 * @param \OCP\UserInterface $backend
	 */
	public function removeBackend($backend) {
		$this->cachedUsers = [];
		unset($this->backends[get_class($backend)]);
	}

	/**
	 * remove all user backends
	 */
	public function clearBackends() {
		$this->cachedUsers = [];
		$this->backends = [];
	}

	/**
	 * @param string $uid
	 * @return boolean
	 */
	protected function isCached($uid) {
		if (isset($this->cachedUsers[$uid])) {
			return true;
		}
		return false;
	}

	/**
	 * get a user by user id
	 *
	 * @param string $uid
	 * @return \OC\User\User|null Either the user or null if the specified user does not exist
	 */
	public function get($uid) {
		if ($this->isCached($uid)) { //check the cache first to prevent having to loop over the backends
			return $this->cachedUsers[$uid];
		}
		if (is_null($uid)){
			return null;
		}
		try {
			$account = $this->accountMapper->getByUid($uid);
			if (is_null($account)) {
				return null;
			}
			return $this->getByAccount($account);
		} catch (DoesNotExistException $ex) {
			return null;
		}
	}

	/**
	 * Get or construct the user object for given account.
	 *
	 * NOTE: This function is not defined in the interface and is only available in the core scope
	 *
	 * @param Account $account
	 * @return \OC\User\User
	 */
	public function getByAccount(Account $account) {
		if ($this->isCached($account->getUserId())) {
			return $this->cachedUsers[$account->getUserId()];
		}

		$user = new User($account, $this->accountMapper, $this->membershipManager, $this, $this->config, null, \OC::$server->getEventDispatcher() );
		$this->cachedUsers[$account->getUserId()] = $user;
		return $user;
	}

	/**
	 * check if a user exists
	 *
	 * @param string $uid
	 * @return bool
	 */
	public function userExists($uid) {
		$user = $this->get($uid);
		return ($user !== null);
	}

	/**
	 * Check if the password is valid for the user
	 *
	 * @param string $loginName
	 * @param string $password
	 * @return mixed the User object on success, false otherwise
	 */
	public function checkPassword($loginName, $password) {
		$loginName = str_replace("\0", '', $loginName);
		$password = str_replace("\0", '', $password);

		if (empty($this->backends)) {
			$this->registerBackend(new Database());
		}

		foreach ($this->backends as $backend) {
			if ($backend->implementsActions(Backend::CHECK_PASSWORD)) {
				$uid = $backend->checkPassword($loginName, $password);
				if ($uid !== false) {
					try {
						$account = $this->accountMapper->getByUid($uid);
					} catch(DoesNotExistException $ex) {
						$account = $this->newAccount($uid, $backend);
					}
					// TODO always sync account with backend here to update displayname, email, search terms, home etc. user_ldap currently updates user metadata on login, core should take care of updating accounts on a successful login
					return $this->getByAccount($account);
				}
			}
		}

		$this->logger->warning('Login failed: \''. $loginName .'\' (Remote IP: \''. \OC::$server->getRequest()->getRemoteAddress(). '\')', ['app' => 'core']);
		return false;
	}

	/**
	 * search by user id
	 *
	 * @param string $pattern
	 * @param int $limit
	 * @param int $offset
	 * @return \OC\User\User[]
	 */
	public function search($pattern, $limit = null, $offset = null) {
		$accounts = $this->accountMapper->search('user_id', $pattern, $limit, $offset);
		$users = [];
		foreach ($accounts as $account) {
			$user = $this->getByAccount($account);
			$users[$user->getUID()] = $user;
		}

		return $users;
	}

	/**
	 * find a user account by checking user_id, display name and email fields
	 *
	 * @param string $pattern
	 * @param int $limit
	 * @param int $offset
	 * @return \OC\User\User[]
	 */
	public function find($pattern, $limit = null, $offset = null) {
		$accounts = $this->accountMapper->find($pattern, $limit, $offset);
		$users = [];
		foreach ($accounts as $account) {
			$user = $this->getByAccount($account);
			$users[$user->getUID()] = $user;
		}
		return $users;
	}

	/**
	 * search by displayName
	 *
	 * @param string $pattern
	 * @param int $limit
	 * @param int $offset
	 * @return \OC\User\User[]
	 */
	public function searchDisplayName($pattern, $limit = null, $offset = null) {
		$accounts = $this->accountMapper->search('display_name', $pattern, $limit, $offset);
		return array_map(function(Account $account) {
			return $this->getByAccount($account);
		}, $accounts);
	}

	/**
	 * @param string $uid
	 * @param string $password
	 * @throws \Exception
	 * @return bool|\OC\User\User the created user or false
	 */
	public function createUser($uid, $password) {
		$l = \OC::$server->getL10N('lib');

		// Check the name for bad characters
		// Allowed are: "a-z", "A-Z", "0-9" and "_.@-'"
		if (preg_match('/[^a-zA-Z0-9 _\.@\-\']/', $uid)) {
			throw new \Exception($l->t('Only the following characters are allowed in a username:'
				. ' "a-z", "A-Z", "0-9", and "_.@-\'"'));
		}
		// No empty username
		if (trim($uid) == '') {
			throw new \Exception($l->t('A valid username must be provided'));
		}
		// No whitespace at the beginning or at the end
		if (strlen(trim($uid, "\t\n\r\0\x0B\xe2\x80\x8b")) !== strlen(trim($uid))) {
			throw new \Exception($l->t('Username contains whitespace at the beginning or at the end'));
		}

		// Username must be at least 3 characters long
		if(strlen($uid) < 3) {
			throw new \Exception($l->t('The username must be at least 3 characters long'));
		}

		// No empty password
		if (trim($password) == '') {
			throw new \Exception($l->t('A valid password must be provided'));
		}

		// Check if user already exists
		if ($this->userExists($uid)) {
			throw new \Exception($l->t('The username is already being used'));
		}

		$this->emit('\OC\User', 'preCreateUser', [$uid, $password]);
		\OC::$server->getEventDispatcher()->dispatch(
			'OCP\User::validatePassword',
			new GenericEvent(null, ['password' => $password])
		);

		if (empty($this->backends)) {
			$this->registerBackend(new Database());
		}
		foreach ($this->backends as $backend) {
			if ($backend->implementsActions(Backend::CREATE_USER)) {
				$backend->createUser($uid, $password);
				$account = $this->newAccount($uid, $backend);
				$user = $this->getByAccount($account);
				$this->emit('\OC\User', 'postCreateUser', [$user, $password]);
				return $user;
			}
		}
		return false;
	}

	/**
	 * @param string $uid
	 * @param UserInterface $backend
	 * @return IUser | null
	 */
	public function createUserFromBackend($uid, $password, $backend) {
		$this->emit('\OC\User', 'preCreateUser', [$uid, '']);
		$account = $this->newAccount($uid, $backend);
		$user = $this->getByAccount($account);
		$this->emit('\OC\User', 'postCreateUser', [$user, $password]);
		return $user;
	}

	/**
	 * returns how many users per backend exist (if supported by backend)
	 *
	 * @param boolean $hasLoggedIn when true only users that have a lastLogin
	 *                entry in the preferences table will be affected
	 * @return array|int an array of backend class as key and count number as value
	 *                if $hasLoggedIn is true only an int is returned
	 */
	public function countUsers($hasLoggedIn = false) {
		if ($hasLoggedIn) {
			return $this->accountMapper->getUserCount($hasLoggedIn);
		}
		return $this->accountMapper->getUserCountPerBackend($hasLoggedIn);
	}

	/**
	 * The callback is executed for each user on each backend.
	 * If the callback returns false no further users will be retrieved.
	 *
	 * @param \Closure $callback
	 * @param string $search
	 * @param boolean $onlySeen when true only users that have a lastLogin entry
	 *                in the preferences table will be affected
	 * @since 9.0.0
	 */
	public function callForAllUsers(\Closure $callback, $search = '', $onlySeen = false) {
		$this->accountMapper->callForAllUsers(function (Account $account) use ($callback) {
			$user = $this->getByAccount($account);
			return $callback($user);
		}, $search, $onlySeen);
	}

	/**
	 * returns how many users have logged in once
	 *
	 * @return int
	 * @since 10.0
	 */
	public function countSeenUsers() {
		return $this->accountMapper->getUserCount(true);
	}

	/**
	 * @param \Closure $callback
	 * @since 10.0
	 */
	public function callForSeenUsers (\Closure $callback) {
		$this->callForAllUsers($callback, '', true);
	}

	/**
	 * @param string $email
	 * @return IUser[]
	 * @since 9.1.0
	 */
	public function getByEmail($email) {
		if ($email === null || trim($email) === '') {
			throw new \InvalidArgumentException('$email cannot be empty');
		}
		$accounts = $this->accountMapper->getByEmail($email);
		return array_map(function(Account $account) {
			return $this->getByAccount($account);
		}, $accounts);
	}

	/**
	 * TODO inject OC\User\SyncService to deduplicate Account creation code
	 * @param string $uid
	 * @param UserInterface $backend
	 * @return Account|\OCP\AppFramework\Db\Entity
	 */
	private function newAccount($uid, $backend) {
		$account = new Account();
		$account->setUserId($uid);
		$account->setBackend(get_class($backend));
		$account->setState(Account::STATE_ENABLED);
		$account->setLastLogin(0);
		if ($backend->implementsActions(Backend::GET_DISPLAYNAME)) {
			$account->setDisplayName($backend->getDisplayName($uid));
		}
		if ($backend instanceof IProvidesEMailBackend) {
			$email = $backend->getEMailAddress($uid);
			if ($email !== null) {
				$account->setEmail($email);
			}
		}
		if ($backend instanceof IProvidesQuotaBackend) {
			$quota = $backend->getQuota($uid);
			if ($quota !== null) {
				$account->setQuota($quota);
			}
		}
		if ($backend instanceof IProvidesExtendedSearchBackend) {
			$terms = $backend->getSearchTerms($uid);
			if (!empty($terms)) {
				$account->setSearchTerms($terms);
			}
		}
		$home = false;
		if ($backend->implementsActions(Backend::GET_HOME)) {
			$home = $backend->getHome($uid);
		}
		if (!is_string($home) || substr($home, 0, 1) !== '/') {
			$home = $this->config->getSystemValue('datadirectory', \OC::$SERVERROOT . '/data') . "/$uid";
			$this->logger->warning(
				"User backend ".get_class($backend)." provided no home for <$uid>, using <$home>.",
				['app' => self::class]
			);
		}
		$account->setHome($home);
		$account = $this->accountMapper->insert($account);
		return $account;
	}

	public function getBackend($backendClass) {
		if (isset($this->backends[$backendClass])) {
			return $this->backends[$backendClass];
		}
		return null;
	}

}