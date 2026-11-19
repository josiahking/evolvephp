<?php

declare(strict_types=1);

namespace Evolve\AuditLog;

use DateTimeImmutable;
use DateTimeZone;
use Evolve\AuditLog\Exception\DuplicateAuditEvent;
use Evolve\Database\Contracts\DatabaseConnection;
use Evolve\Database\Contracts\DatabaseFailureCategory;
use Evolve\Database\Contracts\DatabaseStatement;
use Evolve\Database\Contracts\Exception\DatabaseException;
use JsonException;
use RuntimeException;

/** Portable SQL implementation. The application runs the module migration explicitly. */
final readonly class SqlAuditStore implements AuditStore
{
    public function __construct(private DatabaseConnection $connection) {}

    public function append(AuditEvent $event, array $safePayload): void
    {
        $json = json_encode($safePayload, JSON_THROW_ON_ERROR);
        $statement = new DatabaseStatement(
            'INSERT INTO evolve_audit_events (event_id, event_type, occurred_at, actor_id, tenant_id, subject_id, correlation_id, payload_json) '
            . 'VALUES (:id, :type, :occurred, :actor, :tenant, :subject, :correlation, :payload)',
            ['id' => $event->id, 'type' => $event->type, 'occurred' => $event->timestamp(),
                'actor' => $event->actorId, 'tenant' => $event->tenantId, 'subject' => $event->subjectId,
                'correlation' => $event->correlationId, 'payload' => $json],
            'audit-log.append',
        );
        try {
            $this->connection->transaction(static function (DatabaseConnection $connection) use ($statement): void {
                if ($connection->execute($statement) !== 1) {
                    throw new RuntimeException('Audit write did not insert exactly one event.');
                }
            });
        } catch (DatabaseException $failure) {
            if ($failure->category() === DatabaseFailureCategory::Constraint) {
                throw new DuplicateAuditEvent('Audit event identifier already exists.', 0, $failure);
            }
            throw $failure;
        }
    }

    public function read(AuditReadRequest $request): array
    {
        $filter = $request->tenantId === null ? 'tenant_id IS NULL' : 'tenant_id = :tenant';
        $parameters = $request->tenantId === null ? [] : ['tenant' => $request->tenantId];
        $parameters['limit'] = $request->limit;
        $parameters['offset'] = $request->offset;
        $statement = new DatabaseStatement(
            'SELECT event_id, event_type, occurred_at, actor_id, tenant_id, subject_id, correlation_id, payload_json '
            . 'FROM evolve_audit_events WHERE ' . $filter
            . ' ORDER BY occurred_at DESC, event_id DESC LIMIT :limit OFFSET :offset',
            $parameters,
            'audit-log.read',
        );
        $events = [];
        foreach ($this->connection->query($statement) as $row) {
            if (!is_array($row) || ($row['tenant_id'] ?? null) !== $request->tenantId) {
                throw new RuntimeException('Audit storage returned a row outside the requested tenant.');
            }
            try {
                $payload = json_decode((string) $row['payload_json'], true, 16, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                throw new RuntimeException('Audit storage returned invalid event data.');
            }
            if (!is_array($payload)) {
                throw new RuntimeException('Audit storage returned invalid event data.');
            }
            $events[] = new AuditEvent((string) $row['event_id'], (string) $row['event_type'],
                new DateTimeImmutable((string) $row['occurred_at']), (string) $row['actor_id'],
                $row['tenant_id'], $row['subject_id'], $row['correlation_id'], $payload);
            if (count($events) > $request->limit) {
                throw new RuntimeException('Audit storage exceeded the requested page bound.');
            }
        }
        return $events;
    }

    public function purge(AuditRetentionRequest $request): int
    {
        $filter = $request->tenantId === null ? 'tenant_id IS NULL' : 'tenant_id = :tenant';
        $parameters = $request->tenantId === null ? [] : ['tenant' => $request->tenantId];
        $parameters['cutoff'] = $request->cutoff->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
        $statement = new DatabaseStatement(
            'DELETE FROM evolve_audit_events WHERE ' . $filter . ' AND occurred_at < :cutoff',
            $parameters,
            'audit-log.purge',
        );
        return $this->connection->transaction(
            static fn(DatabaseConnection $connection): int => $connection->execute($statement),
        );
    }
}