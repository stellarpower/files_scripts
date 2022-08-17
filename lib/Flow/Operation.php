<?php

namespace OCA\FilesScripts\Flow;

use Lua;
use OC\User\NoUserException;
use OCA\FilesScripts\Db\ScriptMapper;
use OCA\FilesScripts\Interpreter\AbortException;
use OCA\FilesScripts\Interpreter\Context;
use OCA\FilesScripts\Service\ScriptService;
use OCA\WorkflowEngine\Entity\File;
use OCP\EventDispatcher\GenericEvent;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\SystemTag\MapperEvent;
use OCP\WorkflowEngine\IManager;
use OCP\WorkflowEngine\ISpecificOperation;
use Psr\Log\LoggerInterface;
use OCP\WorkflowEngine\IRuleMatcher;
use OCP\EventDispatcher\Event;
use Symfony\Component\EventDispatcher\GenericEvent as LegacyGenericEvent;
use UnexpectedValueException;

class Operation implements ISpecificOperation {
	private IURLGenerator $urlGenerator;
	private IL10N $l;
	private ScriptMapper $scriptMapper;
	private LoggerInterface $logger;
	private ScriptService $scriptService;
	private IUserSession $session;
	private IRootFolder $rootFolder;

	public function __construct(
		IURLGenerator $urlGenerator,
		IL10N $l,
		ScriptMapper $scriptMapper,
		ScriptService $scriptService,
		IRootFolder $rootFolder,
		IUserSession $session,
		LoggerInterface $logger
	) {
		$this->urlGenerator = $urlGenerator;
		$this->l = $l;
		$this->scriptMapper = $scriptMapper;
		$this->scriptService = $scriptService;
		$this->rootFolder = $rootFolder;
		$this->session = $session;
		$this->logger = $logger;
	}

	/**
	 * @throws UnexpectedValueException
	 * @since 9.1
	 */
	public function validateOperation(string $name, array $checks, string $scriptId): void {
		if (!class_exists(Lua::class)) {
			throw new UnexpectedValueException($this->l->t('Lua extension not installed on the server.'));
		}

		$script = $this->scriptMapper->find((int) $scriptId);
		if (!$script) {
			throw new UnexpectedValueException($this->l->t('No script was chosen.'));
		}
	}

	public function getDisplayName(): string {
		return $this->l->t('Run file action');
	}

	public function getDescription(): string {
		return $this->l->t('Pass files to a file action script and run it.');
	}

	public function getIcon(): string {
		return $this->urlGenerator->imagePath('files_scripts', 'files_scripts.svg');
	}

	public function isAvailableForScope(int $scope): bool {
		return $scope === IManager::SCOPE_ADMIN;
	}

	public function onEvent(string $eventName, Event $event, IRuleMatcher $ruleMatcher): void {
		if (!$event instanceof GenericEvent
			&& !$event instanceof LegacyGenericEvent
			&& !$event instanceof MapperEvent) {
			return;
		}

		$matches = $ruleMatcher->getFlows(false);

		$this->logger->error('onEvent rule matcher matches: ' . count($matches));
		foreach ($matches as $match) {
			$scriptId = $match['operation'] ?? -1;
			$script = $this->scriptMapper->find((int) $scriptId);
			$context = $this->createContext($eventName, $event);

			$eventData = [
				'script_id' => $scriptId,
				'flow_id' => $match['id'] ?? "",
				'match' => $match,
			];

			if (!$script) {
				$this->logger->info('Could not run the file action flow. File action possibly deleted', $eventData);
				continue;
			}

			if (!$context) {
				$this->logger->info('Could not run the file action flow. Could not get file context.', $eventData);
				continue;
			}

			try {
				$this->scriptService->runScript($script, $context);
			} catch (AbortException $e) {
				$this->logger->error('File action flow failed', ['error' => $e->getMessage(), 'info' => $eventData]);
			}
		}
	}

	public function getEntityId(): string {
		return File::class;
	}

	private function createContext(string $eventName, Event $event): ?Context {
		/**
		 * @var Node|null $oldNode
		 * @var Node $node
		 */
		$oldNode = null;
		if ($eventName === '\OCP\Files::postRename' || $eventName === '\OCP\Files::postCopy') {
			[$oldNode, $node] = $event->getSubject();
		} elseif ($event instanceof MapperEvent) {
			if ($event->getObjectType() !== 'files') {
				return null;
			}
			$nodes = $this->rootFolder->getById($event->getObjectId());
			if (!isset($nodes[0])) {
				return null;
			}
			$node = $nodes[0];
			unset($nodes);
		} else {
			$node = $event->getSubject();
		}

		try {
			$user = $this->session->getUser();
			$rootFolder = $user ? $this->rootFolder->getUserFolder($user->getUID()) : $this->rootFolder;

			$inputs = ['old_node_path' => $oldNode ? $oldNode->getPath() : null];
			return new Context($rootFolder, $inputs, [1 => $node], $rootFolder->getRelativePath($node->getParent()->getPath()));
		} catch (NotPermittedException|NoUserException|NotFoundException $e) {
			$this->logger->info('Could not create context due to unexpected exception.', ['error'=>$e->getMessage()]);
		}
		return null;
	}
}
