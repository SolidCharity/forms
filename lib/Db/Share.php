<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2021 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Forms\Db;

use OCA\Forms\Constants;
use OCP\AppFramework\Db\Entity;

/**
 * @method int getFormId()
 * @method void setFormId(integer $value)
 * @method int getShareType()
 * @method void setShareType(integer $value)
 * @method string getShareWith()
 * @method void setShareWith(string $value)
 */
class Share extends Entity {
	/** @var int */
	protected $formId;
	/** @var int */
	protected $shareType;
	/** @var string */
	protected $shareWith;
	/** @var string */
	protected $permissionsJson;

	/**
	 * Option constructor.
	 */
	public function __construct() {
		$this->addType('formId', 'integer');
		$this->addType('shareType', 'integer');
		$this->addType('shareWith', 'string');
	}

	public function getPermissions(): array {
		// Fallback to submit permission
		return json_decode($this->getPermissionsJson() ?: 'null') ?? [ Constants::PERMISSION_SUBMIT ];
	}

	/**
	 * @param array $permissions
	 */
	public function setPermissions(array $permissions) {
		$this->setPermissionsJson(
			// Make sure to only encode array values as the indices might be non consecutively so it would be encoded as a json object
			json_encode(array_values($permissions))
		);
	}

	public function read(): array {
		return [
			'id' => $this->getId(),
			'formId' => $this->getFormId(),
			'shareType' => (int)$this->getShareType(),
			'shareWith' => $this->getShareWith(),
			'permissions' => $this->getPermissions(),
		];
	}
}
