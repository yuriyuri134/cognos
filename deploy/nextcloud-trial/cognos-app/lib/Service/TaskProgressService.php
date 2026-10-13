<?php
declare(strict_types=1);
namespace OCA\Cognos\Service;
use OCA\Deck\Db\Card;
use OCP\IDBConnection;
/** The additional non-completed phase; native Deck.done remains the completion fact. */
class TaskProgressService {
    public function __construct(private IDBConnection $db) {}
    public function progress(int $cardId): string {
        $q=$this->db->executeQuery('SELECT progress FROM *PREFIX*cognos_task_progress WHERE card_id=?',[$cardId]);
        $value=$q->fetchOne();$q->closeCursor();
        if ($value===false) return 'open';
        if (!in_array($value,['open','in_progress','blocked'],true)) throw new \UnexpectedValueException('Invalid stored task progress');
        return $value;
    }
    public function status(Card $card): string { return $card->getDone()!==null?'done':$this->progress($card->getId()); }
    public function set(int $cardId,string $progress): void {
        if (!$this->db->inTransaction() || !in_array($progress,['open','in_progress','blocked'],true)) throw new \LogicException('Invalid task progress write');
        $this->db->executeStatement('INSERT INTO *PREFIX*cognos_task_progress (card_id,progress) VALUES (?,?) ON CONFLICT (card_id) DO UPDATE SET progress=EXCLUDED.progress',[$cardId,$progress]);
    }
}
