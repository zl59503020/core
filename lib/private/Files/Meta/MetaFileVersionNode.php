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


namespace OC\Files\Meta;


use OC\Files\Node\AbstractFile;
use OC\Files\Node\File;
use OCP\Files\IRootFolder;
use OCP\Files\Storage\IVersionedStorage;
use OCP\Files\NotPermittedException;
use OCP\Files\Storage;

/**
 * Class MetaFileVersionNode - this class represents a version of a file in the
 * meta endpoint
 *
 * @package OC\Files\Meta
 */
class MetaFileVersionNode extends AbstractFile {

	/** @var string */
	private $versionId;
	/** @var MetaVersionCollection */
	private $parent;
	/** @var IVersionedStorage */
	private $storage;
	/** @var string */
	private $internalPath;
	/** @var IRootFolder */
	private $root;

	/**
	 * MetaFileVersionNode constructor.
	 *
	 * @param MetaVersionCollection $parent
	 * @param IRootFolder $root
	 * @param string $versionId
	 * @param Storage $storage
	 * @param string $internalPath
	 */
	public function __construct(MetaVersionCollection $parent,
								IRootFolder $root,
								$versionId, Storage $storage, $internalPath) {
		$this->parent = $parent;
		$this->versionId = $versionId;
		$this->storage = $storage;
		$this->internalPath = $internalPath;
		$this->root = $root;
	}

	/**
	 * @inheritdoc
	 */
	public function getName() {
		return $this->versionId;
	}

	/**
	 * @inheritdoc
	 */
	public function getContent() {
		return $this->storage->getContentOfVersion($this->internalPath, $this->versionId);
	}

	/**
	 * @inheritdoc
	 */
	public function copy($targetPath) {
		$target = $this->root->get($targetPath);
		if ($target instanceof File && $target->getId() === $this->parent->getId()) {
			$this->storage->restoreVersion($this->internalPath, $this->versionId);
			return;
		}

		// for now we only allow restoring of a version
		throw new NotPermittedException();
	}
}