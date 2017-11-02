<?php
/**
 * @author Thomas Müller <thomas.mueller@tmit.eu>
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


namespace OCP\App;

use OCP\IUser;

/**
 * Interface IServiceLoader
 *
 * @package OCP\App
 * @since 10.0.5
 */
interface IServiceLoader {

	/**
	 * @param string $xmlTag
	 * @param IUser|null $user
	 * @return \Generator
	 * @since 10.0.5
	 */
	public function load($xmlTag, IUser $user = null);
}
