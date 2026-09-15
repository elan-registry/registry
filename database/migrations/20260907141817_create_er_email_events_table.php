<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Issue #1887: creates `er_email_events`, the durable record of every
 * inbound Brevo delivery-status webhook event matched to a car.
 *
 * NAMING — follows the `er_` prefix convention established by
 * `er_verification_settings` (20260907120000): every new ElanRegistry-owned
 * table from v2.30.2 onward is `er_*`. See docs/development/DATABASE.md.
 *
 * No FK on `car_id` — this codebase has no FK constraints on car-adjacent
 * tables; `cars.user_id`'s own FK was deliberately dropped in
 * 20260719120000_drop_cars_user_id_fk.php.
 *
 * `brevo_message_id` is `NOT NULL DEFAULT ''`, not nullable — correctness
 * critical, not a style choice. MySQL treats every NULL as distinct in a
 * unique index, so a nullable column would silently defeat
 * `UNIQUE (car_id, brevo_message_id, event)` for exactly the rows that need
 * dedup most: the locally-written `'sent'` row and any inbound payload
 * missing the field.
 *
 * Uses `change()` (pure Table-builder API, per this directory's README —
 * "Strongly prefer change()") since every operation here (createTable,
 * addColumn, addIndex) is auto-reversible and there is no non-reversible
 * operation, unlike the sibling column/trigger migration in this same PR.
 */
final class CreateErEmailEventsTable extends AbstractMigration
{
    public function change(): void
    {
        $table = $this->table('er_email_events', ['id' => true, 'primary_key' => 'id']);

        $table
            ->addColumn('car_id', 'integer', ['null' => false])
            ->addColumn('email', 'string', ['limit' => 155, 'null' => false])
            // Long enough for the longest known Brevo event name
            // ('unique_opened') with headroom for future event types.
            ->addColumn('event', 'string', ['limit' => 32, 'null' => false])
            ->addColumn('reason', 'text', ['null' => true])
            ->addColumn('brevo_message_id', 'string', [
                'limit'   => 255,
                'null'    => false,
                'default' => '',
            ])
            ->addColumn('occurred_at', 'datetime', ['null' => false])
            ->addIndex(['car_id', 'brevo_message_id', 'event'], ['unique' => true])
            ->addIndex(['car_id', 'occurred_at'])
            ->addIndex(['email'])
            ->create();
    }
}
