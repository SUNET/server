<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2023, Micke Nordin <kano@sunet.se>
 *
 * @author Micke Nordin <kano@sunet.se>
 *
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
 * along with this program. If not, see <http://www.gnu.org/licenses/>
 *
 */

namespace OCP\Authentication\TwoFactorAuth;

use OC\Authentication\TwoFactorAuth\Manager as PrivateManager;
use OCP\IUser;

class Manager
{

	/** @var Manager */
	private $manager;

	public function __construct(PrivateManager $manager)
	{
		$this->manager = $manager;
	}

	/**
	 * Prepare the 2FA login
	 *
	 * @param IUser $user
	 * @param boolean $rememberMe
	 */
	public function prepareTwoFactorLogin(IUser $user, bool $rememberMe)
	{
		$this->manager->prepareTwoFactorLogin($user, $rememberMe);
	}
}
