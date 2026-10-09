<?php

/*
 * Petra — gestion et contrôle de stations-service.
 * Développé par BELEJ-CONSULTING.
 */

declare(strict_types=1);

namespace Petra\Services;

use DateTimeZone;
use Petra\Core\Database;
use Petra\Core\HttpException;
use Petra\Core\Validator;
use Petra\Domain\StationSettings;
use Petra\Repositories\StationRepository;
use Petra\Repositories\StationSettingRepository;
use Petra\Security\Auth;
use Petra\Sync\SyncConfig;

/** Stations : création, modification, activation, horaires des postes, fuseau et seuils. Tout est journalisé. */
final class StationService
{
    private const RULES = [
        'code' => 'required|string|max:20',
        'name' => 'required|string|max:120',
        'city' => 'string|max:120',
        'address' => 'string|max:255',
        'timezone' => 'required|string|max:64',
    ];

    public function __construct(
        private readonly Database $db,
        private readonly StationRepository $stations,
        private readonly StationSettingRepository $settings,
        private readonly AuditLogger $logger,
        private readonly Auth $auth,
        private readonly SyncConfig $sync,
    ) {
    }

    /** @return list<string> fuseaux proposés : Afrique et UTC */
    public static function timezones(): array
    {
        return [...DateTimeZone::listIdentifiers(DateTimeZone::AFRICA), 'UTC'];
    }

    public function create(array $input): string
    {
        $this->assertStationRowEditable();
        $data = $this->validate($input, null);
        $userId = $this->userId();

        return $this->db->transaction(function () use ($data, $userId): string {
            $id = $this->stations->create($data, $userId);
            $this->logger->log('create', 'station', $id, null, null, $data, $data['name']);

            return $id;
        });
    }

    public function update(string $id, array $input): void
    {
        $this->assertStationRowEditable();
        $before = $this->stations->find($id) ?? throw HttpException::notFound();
        $data = $this->validate($input, $id);
        $this->db->transaction(function () use ($id, $before, $data): void {
            $this->stations->update($id, $data);
            $this->logger->log('update', 'station', $id, $id, $before, $data, $data['name']);
        });
    }

    /** Désactivation : refusée tant qu'un poste est ouvert. La station et son historique restent visibles du propriétaire. */
    public function setActive(string $id, bool $active): array
    {
        $this->assertStationRowEditable();
        $station = $this->stations->find($id) ?? throw HttpException::notFound();
        if (!$active && $this->stations->openShiftCount($id) > 0) {
            throw Validator::fail('station', 'station_open_shifts');
        }
        $this->db->transaction(function () use ($id, $station, $active): void {
            $this->stations->setActive($id, $active);
            $this->logger->log($active ? 'activate' : 'deactivate', 'station', $id, $id, ['is_active' => $station['is_active']], ['is_active' => $active ? 1 : 0], $station['name']);
        });

        return $station;
    }

    /** Horaires des postes et fuseau de la station. */
    public function saveSchedule(string $id, array $input): void
    {
        $station = $this->stations->find($id) ?? throw HttpException::notFound();
        $timezone = self::timezone($input['timezone'] ?? null);
        $stored = $this->settings->all($id);
        $before = StationSettings::schedule($stored);
        $modeBefore = StationSettings::shiftMode($stored);
        $mode = in_array($input['shift_mode'] ?? null, StationSettings::MODES, true) ? (string) $input['shift_mode'] : $modeBefore;
        // Horaires libres : les créneaux ne servent plus ; ceux en place sont gardés pour un retour aux horaires fixes.
        $schedule = $mode === 'free' ? $before : StationSettings::validateSchedule($input['slots'] ?? null);

        $this->db->transaction(function () use ($id, $station, $timezone, $schedule, $before, $mode, $modeBefore): void {
            $this->stations->setTimezone($id, $timezone);
            $this->settings->save($id, [StationSettings::SCHEDULE_KEY => json_encode($schedule, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), StationSettings::MODE_KEY => $mode], $this->userId());
            $this->logger->log(
                'update',
                'station_schedule',
                $id,
                $id,
                ['timezone' => $station['timezone'], 'shift_mode' => $modeBefore, 'shift_schedule' => $before],
                ['timezone' => $timezone, 'shift_mode' => $mode, 'shift_schedule' => $schedule],
                $station['name']
            );
        });
    }

    /** Seuils de contrôle de la station. */
    public function saveThresholds(string $id, array $input): void
    {
        $station = $this->stations->find($id) ?? throw HttpException::notFound();
        $values = StationSettings::validateThresholds($input) + StationSettings::validateDelays($input);
        $stored = $this->settings->all($id);
        $before = StationSettings::thresholds($stored) + array_intersect_key(StationSettings::delays($stored), $values) + [StationSettings::USD_RULE_KEY => StationSettings::usdRule($stored)];
        // Règle des dollars non ventilés : facultative, la valeur en place est gardée si elle n'est pas envoyée.
        $rule = $input[StationSettings::USD_RULE_KEY] ?? null;
        if ($rule !== null && $rule !== '') {
            if (!is_string($rule) || !in_array($rule, \Petra\Domain\CashCalculator::USD_RULES, true)) {
                throw Validator::fail(StationSettings::USD_RULE_KEY, 'in');
            }
            $values[StationSettings::USD_RULE_KEY] = $rule;
        }

        $this->db->transaction(function () use ($id, $station, $values, $before): void {
            $this->settings->save($id, $values, $this->userId());
            $this->logger->log('update', 'station_settings', $id, $id, $before, $values, $station['name']);
        });
    }

    /** La fiche d'une station reliée à un central (nom, code, activité) se modifie au central. */
    private function assertStationRowEditable(): void
    {
        if ($this->sync->isSyncedStation()) {
            throw new HttpException(409, 'errors.central_only');
        }
    }

    private function validate(array $input, ?string $id): array
    {
        $data = Validator::validate($input, self::RULES);
        $data['code'] = mb_strtoupper($data['code']);
        if (!preg_match('/^[A-Z0-9][A-Z0-9-]{0,19}$/', $data['code'])) {
            throw Validator::fail('code', 'code_format');
        }
        $data['timezone'] = self::timezone($data['timezone']);
        if ($this->stations->codeExists($data['code'], $id)) {
            throw Validator::fail('code', 'unique');
        }

        return $data;
    }

    private static function timezone(mixed $value): string
    {
        if (!is_string($value) || !in_array($value, self::timezones(), true)) {
            throw Validator::fail('timezone', 'in');
        }

        return $value;
    }

    private function userId(): string
    {
        return $this->auth->user()?->id ?? throw new HttpException(401, 'errors.unauthenticated');
    }
}
