<?php
declare(strict_types=1);
namespace OCA\Cognos\Service;
use OCA\Deck\Db\Card;
use OCA\Deck\Db\Stack;
use OCP\IDBConnection;
use OCP\IUserManager;

class TaskAuditService {
    public function __construct(private IDBConnection $db, private IUserManager $users, private TaskProgressService $progress) {}
    public function snapshot(Card $card, Stack $stack): array {
        $assignees = [];
        foreach ($card->getAssignedUsers() ?? [] as $assignment) {
            $uid = $assignment->getParticipant();
            $type = $assignment->getType();
            $assignees[] = ['uid' => $uid, 'type' => $type,
                'name' => $type === 0 ? ($this->users->get($uid)?->getDisplayName() ?? $uid) : $uid];
        }
        $labels = [];
        foreach ($card->getLabels() ?? [] as $label) $labels[] = ['id' => $label->getId(), 'title' => $label->getTitle()];
        return ['status' => $this->progress->status($card), 'id' => $card->getId(), 'title' => $card->getTitle(),
            'description' => $card->getDescription(), 'owner' => $card->getOwner(),
            'stack' => ['id' => $stack->getId(), 'title' => $stack->getTitle()],
            'order' => $card->getOrder(), 'done' => $card->getDone()?->format(DATE_ATOM),
            'assignees' => $assignees, 'labels' => $labels, 'color' => $card->getColor(),
            'duedate' => $card->getDuedate()?->format(DATE_ATOM),
            'startdate' => $card->getStartdate()?->format(DATE_ATOM), 'archived' => $card->getArchived()];
    }
    public function append(int $boardId, int $cardId, string $uid, string $name, string $kind,
        ?array $before, array $after): void {
        $q = $this->db->getQueryBuilder();
        $q->insert('cognos_task_events')->values([
            'board_id' => $q->createNamedParameter($boardId), 'card_id' => $q->createNamedParameter($cardId),
            'actor_uid' => $q->createNamedParameter($uid), 'actor_name' => $q->createNamedParameter($name),
            'kind' => $q->createNamedParameter($kind),
            'before_state' => $q->createNamedParameter($before === null ? null : json_encode($before, JSON_THROW_ON_ERROR)),
            'after_state' => $q->createNamedParameter(json_encode($after, JSON_THROW_ON_ERROR)),
            'created_at' => $q->createNamedParameter(time()),
        ])->executeStatement();
    }
}
