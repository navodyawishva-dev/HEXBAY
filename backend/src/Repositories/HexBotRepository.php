<?php
declare(strict_types=1);

namespace Hexbay\Repositories;

use PDO;

final class HexBotRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    /** @return array<string, mixed>|null */
    public function activeBySessionKey(string $sessionKey): ?array
    {
        $statement = $this->db->prepare(
            'SELECT * FROM chatbot_sessions
             WHERE session_key=:session_key
               AND status="active"
               AND expires_at>CURRENT_TIMESTAMP
             ORDER BY updated_at DESC
             LIMIT 1'
        );
        $statement->execute(['session_key' => $sessionKey]);
        return $this->sessionRow($statement->fetch());
    }

    /** @return array<string, mixed>|null */
    public function ownedSession(string $publicId, string $sessionKey): ?array
    {
        $statement = $this->db->prepare(
            'SELECT * FROM chatbot_sessions
             WHERE public_id=:public_id
               AND session_key=:session_key
               AND status="active"
               AND expires_at>CURRENT_TIMESTAMP
             LIMIT 1'
        );
        $statement->execute([
            'public_id' => $publicId,
            'session_key' => $sessionKey,
        ]);
        return $this->sessionRow($statement->fetch());
    }

    /** @param array<string, mixed> $context */
    public function create(
        string $publicId,
        string $sessionKey,
        string $stateCode,
        array $context
    ): int {
        $statement = $this->db->prepare(
            'INSERT INTO chatbot_sessions
                (public_id, session_key, state_code, context_json, expires_at)
             VALUES
                (:public_id, :session_key, :state_code, :context_json,
                 DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 24 HOUR))'
        );
        $statement->execute([
            'public_id' => $publicId,
            'session_key' => $sessionKey,
            'state_code' => $stateCode,
            'context_json' => json_encode(
                $context,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
            ),
        ]);
        return (int) $this->db->lastInsertId();
    }

    /** @param array<string, mixed> $context */
    public function update(
        int $id,
        ?string $intent,
        string $stateCode,
        array $context,
        string $status = 'active'
    ): void {
        $statement = $this->db->prepare(
            'UPDATE chatbot_sessions
             SET active_intent=:intent,
                 state_code=:state_code,
                 context_json=:context_json,
                 status=:status,
                 expires_at=DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 24 HOUR)
             WHERE id=:id'
        );
        $statement->execute([
            'intent' => $intent,
            'state_code' => $stateCode,
            'context_json' => json_encode(
                $context,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
            ),
            'status' => $status,
            'id' => $id,
        ]);
    }

    /** @param array<string, mixed> $entities */
    public function addMessage(
        int $sessionId,
        string $sender,
        string $text,
        ?string $intent = null,
        ?float $confidence = null,
        array $entities = []
    ): int {
        $statement = $this->db->prepare(
            'INSERT INTO chatbot_messages
                (chatbot_session_id, sender, message_text, detected_intent,
                 confidence_score, extracted_entities_json)
             VALUES
                (:session_id, :sender, :message_text, :intent, :confidence,
                 :entities_json)'
        );
        $statement->execute([
            'session_id' => $sessionId,
            'sender' => $sender,
            'message_text' => mb_substr($text, 0, 2000),
            'intent' => $intent,
            'confidence' => $confidence,
            'entities_json' => $entities === []
                ? null
                : json_encode(
                    $entities,
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
                ),
        ]);
        return (int) $this->db->lastInsertId();
    }

    /** @return array<int, array<string, mixed>> */
    public function messages(int $sessionId, int $limit = 80): array
    {
        $statement = $this->db->prepare(
            'SELECT id, sender, message_text, detected_intent,
                    confidence_score, created_at
             FROM chatbot_messages
             WHERE chatbot_session_id=:session_id
             ORDER BY id
             LIMIT :limit'
        );
        $statement->bindValue(':session_id', $sessionId, PDO::PARAM_INT);
        $statement->bindValue(':limit', max(1, min($limit, 100)), PDO::PARAM_INT);
        $statement->execute();
        return $statement->fetchAll();
    }

    public function messageCount(int $sessionId): int
    {
        $statement = $this->db->prepare(
            'SELECT COUNT(*) FROM chatbot_messages
             WHERE chatbot_session_id=:session_id'
        );
        $statement->execute(['session_id' => $sessionId]);
        return (int) $statement->fetchColumn();
    }

    /** @return array<string, mixed>|null */
    private function sessionRow(array|false $row): ?array
    {
        if ($row === false) {
            return null;
        }
        $context = json_decode((string) $row['context_json'], true);
        $row['id'] = (int) $row['id'];
        $row['context'] = is_array($context) ? $context : [];
        unset($row['context_json']);
        return $row;
    }
}
