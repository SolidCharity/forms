<?php

declare(strict_types=1);
/**
 * @copyright Copyright (c) 2021 Jonas Rittershofer <jotoeri@users.noreply.github.com>
 *
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
namespace OCA\Forms\Tests\Unit\Activity;

use OCA\Forms\Activity\ActivityManager;
use OCA\Forms\Db\Form;
use OCA\Forms\Service\CirclesService;
use OCP\Activity\IEvent;
use OCP\Activity\IManager;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\Share\IShare;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Test\TestCase;

class ActivityManagerTest extends TestCase {

	/** @var ActivityManager */
	private $activityManager;

	/** @var IManager|MockObject */
	private $manager;

	/** @var IGroupManager|MockObject */
	private $groupManager;

	/** @var LoggerInterface|MockObject */
	private $logger;

	/** @var CirclesService|MockObject */
	private $circlesService;

	public function setUp(): void {
		parent::setUp();
		$this->manager = $this->createMock(IManager::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->logger = $this->getMockBuilder('Psr\Log\LoggerInterface')->getMock();
		$this->circlesService = $this->createMock(CirclesService::class);

		$this->activityManager = new ActivityManager('forms', 'currentUser', $this->manager, $this->groupManager, $this->logger, $this->circlesService);
	}

	public function testPublishNewShare() {
		// Can't mock the DB-Classes, as their Property-Methods are not explicitely defined.
		$form = new Form();
		$form->setId(5);
		$form->setTitle('TestForm-Title');
		$form->setHash('abcdefg12345');
		$shareeId = 'sharedUser';

		$event = $this->createMock(IEvent::class);
		$this->manager->expects($this->once())
			->method('generateEvent')
			->willReturn($event);
		$event->expects($this->once())->method('setApp')->with('forms')->willReturn($event);
		$event->expects($this->once())->method('setType')->with('forms_newshare')->willReturn($event);
		$event->expects($this->once())->method('setAffectedUser')->with($shareeId)->willReturn($event);
		$event->expects($this->once())->method('setAuthor')->with('currentUser')->willReturn($event);
		$event->expects($this->once())->method('setObject')->with('form', 5)->willReturn($event);
		$event->expects($this->once())->method('setSubject')->with('newshare', [
			'userId' => 'currentUser',
			'formTitle' => 'TestForm-Title',
			'formHash' => 'abcdefg12345'
		])->willReturn($event);

		$this->manager->expects($this->once())
			->method('publish')
			->with($event);

		$this->activityManager->publishNewShare($form, $shareeId);
	}

	public function testPublishNewGroupShare() {
		// Can't mock the DB-Classes, as their Property-Methods are not explicitely defined.
		$form = new Form();
		$form->setId(5);
		$form->setTitle('TestForm-Title');
		$form->setHash('abcdefg12345');
		$groupId = 'sharedGroup';
		
		$group = $this->createMock(IGroup::class);
		$user = $this->createMock(IUser::class);

		$user->expects($this->exactly(3))
			->method('getUID')
			->will($this->onConsecutiveCalls('user1', 'user2', 'user3'));
		$group->expects($this->once())
			->method('getUsers')
			->willReturn([$user, $user, $user]);
		$this->groupManager->expects($this->once())
			->method('get')
			->with($groupId)
			->willReturn($group);

		$event = $this->createMock(IEvent::class);
		$this->manager->expects($this->exactly(3))
			->method('generateEvent')
			->willReturn($event);
		$event->expects($this->exactly(3))->method('setApp')->with('forms')->willReturn($event);
		$event->expects($this->exactly(3))->method('setType')->with('forms_newshare')->willReturn($event);
		$event->expects($this->exactly(3))->method('setAffectedUser')->withConsecutive(['user1'], ['user2'], ['user3'])->willReturn($event);
		$event->expects($this->exactly(3))->method('setAuthor')->with('currentUser')->willReturn($event);
		$event->expects($this->exactly(3))->method('setObject')->with('form', 5)->willReturn($event);
		$event->expects($this->exactly(3))->method('setSubject')->with('newgroupshare', [
			'userId' => 'currentUser',
			'formTitle' => 'TestForm-Title',
			'formHash' => 'abcdefg12345',
			'groupId' => 'sharedGroup'
		])->willReturn($event);

		$this->manager->expects($this->exactly(3))
			->method('publish')
			->with($event);

		$this->activityManager->publishNewGroupShare($form, $groupId);
	}

	public function testPublishNewCircleShare() {
		// Can't mock the DB-Classes, as their Property-Methods are not explicitely defined.
		$form = new Form();
		$form->setId(5);
		$form->setTitle('TestForm-Title');
		$form->setHash('abcdefg12345');
		$circleId = 'sharedCircle';

		$this->circlesService->expects($this->once())
			->method('getCircleUsers')
			->with($circleId)
			->willReturn(['userId', 'user1', 'user2', 'user3']);
		$event = $this->createMock(IEvent::class);
		$this->manager->expects($this->exactly(4))
			->method('generateEvent')
			->willReturn($event);
		$event->expects($this->exactly(4))->method('setApp')->with('forms')->willReturn($event);
		$event->expects($this->exactly(4))->method('setType')->with('forms_newshare')->willReturn($event);
		$event->expects($this->exactly(4))->method('setAffectedUser')->withConsecutive(['userId'], ['user1'], ['user2'], ['user3'])->willReturn($event);
		$event->expects($this->exactly(4))->method('setAuthor')->with('currentUser')->willReturn($event);
		$event->expects($this->exactly(4))->method('setObject')->with('form', 5)->willReturn($event);
		$event->expects($this->exactly(4))->method('setSubject')->with('newcircleshare', [
			'userId' => 'currentUser',
			'formTitle' => 'TestForm-Title',
			'formHash' => 'abcdefg12345',
			'circleId' => $circleId
		])->willReturn($event);

		$this->manager->expects($this->exactly(4))
			->method('publish')
			->with($event);

		$this->activityManager->publishNewCircleShare($form, $circleId);
	}

	public function testPublishNewCircleShare_circlesDisabled() {
		$form = $this->createMock(Form::class);
		
		$this->circlesService->expects($this->once())
			->method('getCircleUsers')
			->with('circle')
			->willReturn([]);

		$this->manager->expects($this->never())
			->method('generateEvent');

		$this->activityManager->publishNewCircleShare($form, 'circle');
	}

	public function testPublishNewSubmission() {
		// Can't mock the DB-Classes, as their Property-Methods are not explicitely defined.
		$form = new Form();
		$form->setId(5);
		$form->setTitle('TestForm-Title');
		$form->setHash('abcdefg12345');
		$form->setOwnerId('formOwner');
		$submittorId = 'submittingUser';

		$event = $this->createMock(IEvent::class);
		$this->manager->expects($this->once())
			->method('generateEvent')
			->willReturn($event);
		$event->expects($this->once())->method('setApp')->with('forms')->willReturn($event);
		$event->expects($this->once())->method('setType')->with('forms_newsubmission')->willReturn($event);
		$event->expects($this->once())->method('setAffectedUser')->with('formOwner')->willReturn($event);
		$event->expects($this->once())->method('setAuthor')->with('submittingUser')->willReturn($event);
		$event->expects($this->once())->method('setObject')->with('form', 5)->willReturn($event);
		$event->expects($this->once())->method('setSubject')->with('newsubmission', [
			'userId' => 'submittingUser',
			'formTitle' => 'TestForm-Title',
			'formHash' => 'abcdefg12345'
		])->willReturn($event);

		$this->manager->expects($this->once())
			->method('publish')
			->with($event);

		$this->activityManager->publishNewSubmission($form, $submittorId);
	}

	public function dataPublichNewSharedSubmission() {
		return [
			'user-share' => [
				'shareType' => IShare::TYPE_USER,
				'shareWith' => 'sharedUser',
				'expected' => [['sharedUser']]
			],
			'group-share' => [
				IShare::TYPE_GROUP,
				'sharedGroup',
				[['user1'], ['user2']],
				['user1', 'user2'],
			],
			'circle-share' => [
				IShare::TYPE_CIRCLE,
				'sharedCircle',
				[['user1'], ['user2']],
				['user1', 'user2'],
			],
		];
	}

	/**
	 * Test notify shared results
	 *
	 * @dataProvider dataPublichNewSharedSubmission
	 */
	public function testPublishNewSharedSubmission(int $shareType, string $shareWith, array $expected, ?array $sharedUsers = null) {
		// Can't mock the DB-Classes, as their Property-Methods are not explicitely defined.
		$form = new Form();
		$form->setId(5);
		$form->setTitle('TestForm-Title');
		$form->setHash('abcdefg12345');
		$form->setOwnerId('formOwner');
		$submitterId = 'submittingUser';

		if ($shareType === IShare::TYPE_CIRCLE) {
			$this->circlesService->expects($this->once())
				->method('getCircleUsers')
				->with('sharedCircle')
				->willReturn($sharedUsers);
		} elseif ($shareType === IShare::TYPE_GROUP) {
			$users = array_map(function ($name) {
				$user = $this->createMock(IUser::class);
				$user->expects($this->once())->method('getUID')->willReturn($name);
				return $user;
			}, $sharedUsers);
			$group = $this->createMock(IGroup::class);
			$group->expects($this->once())->method('getUsers')->willReturn($users);
			$this->groupManager->expects($this->once())->method('get')->willReturn($group);
		}

		$event = $this->createMock(IEvent::class);
		$this->manager->expects($this->exactly(count($expected)))
			->method('generateEvent')
			->willReturn($event);
		$event->expects($this->exactly(count($expected)))->method('setApp')->with('forms')->willReturn($event);
		$event->expects($this->exactly(count($expected)))->method('setType')->with('forms_newsharedsubmission')->willReturn($event);
		$event->expects($this->exactly(count($expected)))->method('setAffectedUser')->withConsecutive(...[...$expected])->willReturn($event);
		$event->expects($this->exactly(count($expected)))->method('setAuthor')->with('submittingUser')->willReturn($event);
		$event->expects($this->exactly(count($expected)))->method('setObject')->with('form', 5)->willReturn($event);
		$event->expects($this->exactly(count($expected)))->method('setSubject')->with('newsubmission', [
			'userId' => 'submittingUser',
			'formTitle' => 'TestForm-Title',
			'formHash' => 'abcdefg12345'
		])->willReturn($event);

		$this->manager->expects($this->exactly(count($expected)))
			->method('publish')
			->with($event);

		$this->activityManager->publishNewSharedSubmission($form, $shareType, $shareWith, $submitterId);
	}
}
