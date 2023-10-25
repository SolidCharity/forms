<?php
/**
 * @copyright Copyright (c) 2020 John Molakvoæ <skjnldsv@protonmail.com>
 *
 * @author John Molakvoæ (skjnldsv) <skjnldsv@protonmail.com>
 * @author Jonas Rittershofer <jotoeri@users.noreply.github.com>
 *
 * @license AGPL-3.0-or-later
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\Forms\Service;

use OCA\Forms\Activity\ActivityManager;
use OCA\Forms\Constants;
use OCA\Forms\Db\AnswerMapper;
use OCA\Forms\Db\Form;
use OCA\Forms\Db\FormMapper;
use OCA\Forms\Db\OptionMapper;
use OCA\Forms\Db\QuestionMapper;
use OCA\Forms\Db\Share;
use OCA\Forms\Db\ShareMapper;
use OCA\Forms\Db\SubmissionMapper;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\IMapperException;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Security\ISecureRandom;
use OCP\Share\IShare;
use Psr\Log\LoggerInterface;

/**
 * Trait for getting forms information in a service
 */
class FormsService {
	private ?IUser $currentUser;

	public function __construct(
		IUserSession $userSession,
		private ActivityManager $activityManager,
		private FormMapper $formMapper,
		private OptionMapper $optionMapper,
		private QuestionMapper $questionMapper,
		private ShareMapper $shareMapper,
		private SubmissionMapper $submissionMapper,
		private AnswerMapper $answerMapper,
		private ConfigService $configService,
		private IGroupManager $groupManager,
		private LoggerInterface $logger,
		private IUserManager $userManager,
		private ISecureRandom $secureRandom,
		private CirclesService $circlesService,
	) {
		$this->currentUser = $userSession->getUser();
	}

	/**
	 * Create a new Form Hash
	 */
	public function generateFormHash(): string {
		return $this->secureRandom->generate(
			16,
			ISecureRandom::CHAR_HUMAN_READABLE
		);
	}

	/**
	 * Load options corresponding to question
	 *
	 * @param integer $questionId
	 * @return array
	 */
	public function getOptions(int $questionId): array {
		$optionList = [];
		try {
			$optionEntities = $this->optionMapper->findByQuestion($questionId);
			foreach ($optionEntities as $optionEntity) {
				$optionList[] = $optionEntity->read();
			}
		} catch (DoesNotExistException $e) {
			//handle silently
		} finally {
			return $optionList;
		}
	}

	private function getAnswers(int $formId, string $userId): array|false {

		$submissionEntity = null;
		try {
			$submissionEntity = $this->submissionMapper->findByFormAndUser($formId, $userId);
		} catch (DoesNotExistException $e) {
			return false;
		}

		$answerList = [];
		$submission = $submissionEntity->read();
		$answerEntities = $this->answerMapper->findBySubmission($submission['id']);
		foreach ($answerEntities as $answerEntity) {
			$answer = $answerEntity->read();
			$questionId = $answer['questionId'];
			if (!array_key_exists($questionId, $answerList)) {
				$answerList[$questionId] = array();
			}
			$options = $this->getOptions($answer['questionId']);
			if (!empty($options)) {
				// match option text to option index
				foreach ($options as $option) {
					if ($option['text'] == $answer['text']) {
						$answerList[$questionId][] = $option['id'];
					}
				}
			} else {
				// copy the text
				$answerList[$questionId][] = $answer['text'];
			}
		}
		return $answerList;
	}

	/**
	 * Load questions corresponding to form
	 *
	 * @param integer $formId
	 * @return array
	 */
	public function getQuestions(int $formId): array {
		$questionList = [];
		try {
			$questionEntities = $this->questionMapper->findByForm($formId);
			foreach ($questionEntities as $questionEntity) {
				$question = $questionEntity->read();
				$question['options'] = $this->getOptions($question['id']);
				$questionList[] = $question;
			}
		} catch (DoesNotExistException $e) {
			//handle silently
		} finally {
			return $questionList;
		}
	}

	/**
	 * Load shares corresponding to form
	 *
	 * @param integer $formId
	 * @return array
	 */
	public function getShares(int $formId): array {
		$shareList = [];

		$shareEntities = $this->shareMapper->findByForm($formId);
		foreach ($shareEntities as $shareEntity) {
			$share = $shareEntity->read();
			$share['displayName'] = $this->getShareDisplayName($share);
			$shareList[] = $share;
		}

		return $shareList;
	}

	/**
	 * Get a form data
	 *
	 * @param Form $form
	 * @return array
	 * @throws IMapperException
	 */
	public function getForm(Form $form): array {
		$result = $form->read();
		$result['questions'] = $this->getQuestions($form->getId());

		if ($this->currentUser->getUID()) {
			$answers = $this->getAnswers($form->getId(), $this->currentUser->getUID());
			if ($answers !== false) {
				$result['answers'] = $answers;
				$result['newSubmission'] = false;
			}
		}

		$result['shares'] = $this->getShares($form->getId());

		// Append permissions for current user.
		$result['permissions'] = $this->getPermissions($form);
		// Append canSubmit, to be able to show proper EmptyContent on internal view.
		$result['canSubmit'] = $this->canSubmit($form);

		// Append submissionCount if currentUser has permissions to see results
		if (in_array(Constants::PERMISSION_RESULTS, $result['permissions'])) {
			$result['submissionCount'] = $this->submissionMapper->countSubmissions($form->getId());
		}

		return $result;
	}

	/**
	 * Create partial form, as returned by Forms-Lists.
	 *
	 * @param Form $form
	 * @return array
	 * @throws IMapperException
	 */
	public function getPartialFormArray(Form $form): array {
		$result = [
			'id' => $form->getId(),
			'hash' => $form->getHash(),
			'title' => $form->getTitle(),
			'expires' => $form->getExpires(),
			'lastUpdated' => $form->getLastUpdated(),
			'permissions' => $this->getPermissions($form),
			'partial' => true
		];

		// Append submissionCount if currentUser has permissions to see results
		if (in_array(Constants::PERMISSION_RESULTS, $result['permissions'])) {
			$result['submissionCount'] = $this->submissionMapper->countSubmissions($form->getId());
		}

		return $result;
	}

	/**
	 * Get a form data without sensitive informations
	 *
	 * @param Form $form
	 * @return array
	 * @throws IMapperException
	 */
	public function getPublicForm(Form $form): array {
		$formData = $this->getForm($form);

		// Remove sensitive data
		unset($formData['access']);
		unset($formData['ownerId']);
		unset($formData['shares']);

		return $formData;
	}

	/**
	 * Get current users permissions on a form
	 *
	 * @param Form $form
	 * @return array
	 */
	public function getPermissions(Form $form): array {
		if (!$this->currentUser) {
			return [];
		}

		// Owner is allowed to do everything
		if ($this->currentUser->getUID() === $form->getOwnerId()) {
			return Constants::PERMISSION_ALL;
		}

		$permissions = [];
		$shares = $this->getSharesWithUser($form->getId(), $this->currentUser->getUID());
		foreach ($shares as $share) {
			$permissions = array_merge($permissions, $share->getPermissions());
		}

		// Fall back to submit permission if access is granted to all users
		if (count($permissions) === 0) {
			$access = $form->getAccess();
			if ($access['permitAllUsers'] && $this->configService->getAllowPermitAll()) {
				$permissions = [Constants::PERMISSION_SUBMIT];
			}
		}

		return array_values(array_unique($permissions));
	}

	/**
	 * Can the current user see results of a form
	 *
	 * @param Form $form
	 * @return boolean
	 */
	public function canSeeResults(Form $form): bool {
		return in_array(Constants::PERMISSION_RESULTS, $this->getPermissions($form));
	}

	/**
	 * Can the user submit a form
	 *
	 * @param Form $form
	 * @return boolean
	 */
	public function canSubmit(Form $form): bool {
		// We cannot control how many time users can submit if public link / legacyLink available
		if ($this->hasPublicLink($form)) {
			return true;
		}

		// Owner is always allowed to submit
		if ($this->currentUser->getUID() === $form->getOwnerId()) {
			return true;
		}

		// Refuse access, if SubmitMultiple is not set and AllowEdit is not set and user already has taken part.
		if (!$form->getSubmitMultiple() && !$form->getAllowEdit()) {
			$participants = $this->submissionMapper->findParticipantsByForm($form->getId());
			foreach ($participants as $participant) {
				if ($participant === $this->currentUser->getUID()) {
					return false;
				}
			}
		}

		return true;
	}

	/**
	 * Searching Shares for public link
	 *
	 * @param Form $form
	 * @return boolean
	 */
	public function hasPublicLink(Form $form): bool {
		$access = $form->getAccess();

		if (isset($access['legacyLink'])) {
			return true;
		}

		$shareEntities = $this->shareMapper->findByForm($form->getId());
		foreach ($shareEntities as $shareEntity) {
			if ($shareEntity->getShareType() === IShare::TYPE_LINK) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check if current user has access to this form
	 *
	 * @param Form $form
	 * @return boolean
	 */
	public function hasUserAccess(Form $form): bool {
		$access = $form->getAccess();
		$ownerId = $form->getOwnerId();

		// Refuse access, if no user logged in.
		if (!$this->currentUser) {
			return false;
		}

		// Always grant access to owner.
		if ($ownerId === $this->currentUser->getUID()) {
			return true;
		}

		// Now all remaining users are allowed, if permitAll is set.
		if ($access['permitAllUsers'] && $this->configService->getAllowPermitAll()) {
			return true;
		}

		// Selected Access remains.
		if ($this->isSharedToUser($form->getId())) {
			return true;
		}

		// None of the possible access-options matched.
		return false;
	}

	/**
	 * Is the form shown on sidebar to the user.
	 *
	 * @param Form $form
	 * @return bool
	 */
	public function isSharedFormShown(Form $form): bool {
		$access = $form->getAccess();

		// Dont show here to owner, as its in the owned list anyways.
		if ($form->getOwnerId() === $this->currentUser->getUID()) {
			return false;
		}

		// Dont show expired forms.
		if ($this->hasFormExpired($form)) {
			return false;
		}

		// Shown if permitall and showntoall are both set.
		if ($access['permitAllUsers'] &&
			$access['showToAllUsers'] &&
			$this->configService->getAllowPermitAll()) {
			return true;
		}

		// Shown if user in List of Shared Users/Groups
		if ($this->isSharedToUser($form->getId())) {
			return true;
		}

		// No Reason found to show form.
		return false;
	}

	/**
	 * Checking all selected shares
	 *
	 * @param int $formId
	 * @return bool
	 */
	public function isSharedToUser(int $formId): bool {
		$shareEntities = $this->getSharesWithUser($formId, $this->currentUser->getUID());
		return count($shareEntities) > 0;
	}

	/*
	 * Has the form expired?
	 *
	 * @param Form $form
	 * @return boolean
	 */
	public function hasFormExpired(Form $form): bool {
		return ($form->getExpires() !== 0 && $form->getExpires() < time());
	}

	/**
	 * Get DisplayNames to Shares
	 *
	 * @param array $share
	 * @return string
	 */
	public function getShareDisplayName(array $share): string {
		$displayName = '';

		switch ($share['shareType']) {
			case IShare::TYPE_USER:
				$user = $this->userManager->get($share['shareWith']);
				if ($user instanceof IUser) {
					$displayName = $user->getDisplayName();
				}
				break;
			case IShare::TYPE_GROUP:
				$group = $this->groupManager->get($share['shareWith']);
				if ($group instanceof IGroup) {
					$displayName = $group->getDisplayName();
				}
				break;
			case IShare::TYPE_CIRCLE:
				$circle = $this->circlesService->getCircle($share['shareWith']);
				if (!is_null($circle)) {
					$displayName = $circle->getDisplayName();
				}
				break;
			default:
				// Preset Empty.
		}

		return $displayName;
	}

	/**
	 * Creates activities for sharing to users.
	 * @param Form $form Related Form
	 * @param Share $share The new Share
	 */
	public function notifyNewShares(Form $form, Share $share): void {
		switch ($share->getShareType()) {
			case IShare::TYPE_USER:
				$this->activityManager->publishNewShare($form, $share->getShareWith());
				break;
			case IShare::TYPE_GROUP:
				$this->activityManager->publishNewGroupShare($form, $share->getShareWith());
				break;
			case IShare::TYPE_CIRCLE:
				$this->activityManager->publishNewCircleShare($form, $share->getShareWith());
				break;
			default:
				// Do nothing.
		}
	}

	/**
	 * Creates activities for new submissions on a form
	 *
	 * @param Form $form Related Form
	 * @param string $submitter The ID of the user who submitted the form. Can also be our 'anon-user-'-ID
	 */
	public function notifyNewSubmission(Form $form, string $submitter): void {
		$shares = $this->getShares($form->getId());
		$this->activityManager->publishNewSubmission($form, $submitter);

		foreach ($shares as $share) {
			if (!in_array(Constants::PERMISSION_RESULTS, $share['permissions'])) {
				continue;
			}

			$this->activityManager->publishNewSharedSubmission($form, $share['shareType'], $share['shareWith'], $submitter);
		}
	}

	/**
	 * Return shares of a form shared with given user
	 *
	 * @param int $formId The form to query shares for
	 * @param string $userId The user to check if shared with
	 * @return array
	 */
	protected function getSharesWithUser(int $formId, string $userId): array {
		$shareEntities = $this->shareMapper->findByForm($formId);

		return array_filter($shareEntities, function ($shareEntity) use ($userId) {
			$share = $shareEntity->read();

			// Needs different handling for shareTypes
			switch ($share['shareType']) {
				case IShare::TYPE_USER:
					if ($share['shareWith'] === $userId) {
						return true;
					}
					break;
				case IShare::TYPE_GROUP:
					if ($this->groupManager->isInGroup($userId, $share['shareWith'])) {
						return true;
					}
					break;
				case IShare::TYPE_CIRCLE:
					if ($this->circlesService->isUserInCircle($share['shareWith'], $userId)) {
						return true;
					}
					break;
				default:
					return false;
			}
		});
	}

	/**
	 * Update lastUpdated timestamp for the given form
	 *
	 * @param int $formId The form to update
	 */
	public function setLastUpdatedTimestamp(int $formId): void {
		$form = $this->formMapper->findById($formId);
		$form->setLastUpdated(time());
		$this->formMapper->update($form);
	}
}
