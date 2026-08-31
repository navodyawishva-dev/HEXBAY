<?php
declare(strict_types=1);

namespace Hexbay\Validation;

use Hexbay\Support\HttpException;

final class AdminValidator
{
    private static function boolean(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /** @param array<string, mixed> $input
     *  @return array{status: string, reason: string}
     */
    public static function accountStatus(array $input): array
    {
        $status = strtolower(trim((string) ($input['status'] ?? '')));
        $reason = trim((string) ($input['reason'] ?? ''));
        if (!in_array($status, ['active', 'suspended', 'deactivated'], true)) {
            throw new HttpException(422, 'Account status is invalid.', [
                'status' => ['Choose Active, Suspended, or Deactivated.'],
            ]);
        }
        if ($status !== 'active' && (strlen($reason) < 5 || strlen($reason) > 500)) {
            throw new HttpException(422, 'A reason is required for this account action.', [
                'reason' => ['Use between 5 and 500 characters.'],
            ]);
        }
        return ['status' => $status, 'reason' => $reason];
    }

    /** @param array<string, mixed> $input
     *  @return array{decision: string, reason: string, notes: string}
     */
    public static function shopDecision(array $input): array
    {
        $decision = strtolower(trim((string) ($input['decision'] ?? '')));
        $reason = trim((string) ($input['reason'] ?? ''));
        $notes = trim((string) ($input['notes'] ?? ''));

        if (!in_array($decision, ['approved', 'rejected', 'suspended'], true)) {
            throw new HttpException(422, 'Shop decision is invalid.', [
                'decision' => ['Choose Approved, Rejected, or Suspended.'],
            ]);
        }
        if (
            in_array($decision, ['rejected', 'suspended'], true)
            && (strlen($reason) < 5 || strlen($reason) > 500)
        ) {
            throw new HttpException(422, 'A decision reason is required.', [
                'reason' => ['Use between 5 and 500 characters.'],
            ]);
        }
        if (strlen($notes) > 2000) {
            throw new HttpException(422, 'Review notes are too long.', [
                'notes' => ['Review notes must not exceed 2,000 characters.'],
            ]);
        }

        return ['decision' => $decision, 'reason' => $reason, 'notes' => $notes];
    }

    /** @param array<string, mixed> $input
     *  @return array{percentage: string, reason: string}
     */
    public static function commissionRule(array $input): array
    {
        $raw = trim((string) ($input['percentage'] ?? ''));
        $reason = trim((string) ($input['reason'] ?? ''));
        if (!is_numeric($raw) || (float) $raw < 0 || (float) $raw > 30) {
            throw new HttpException(422, 'Commission percentage is invalid.', [
                'percentage' => ['Use a percentage between 0 and 30.'],
            ]);
        }
        if (strlen($reason) < 5 || strlen($reason) > 255) {
            throw new HttpException(422, 'A reason for the rate is required.', [
                'reason' => ['Use between 5 and 255 characters.'],
            ]);
        }
        return ['percentage' => number_format((float) $raw, 2, '.', ''), 'reason' => $reason];
    }

    /** @param array<string, mixed> $input
     *  @return array{decision: string, reason: string}
     */
    public static function payoutDecision(array $input): array
    {
        $decision = strtolower(trim((string) ($input['decision'] ?? '')));
        $reason = trim((string) ($input['reason'] ?? ''));
        if (!in_array($decision, ['approved', 'paid', 'rejected'], true)) {
            throw new HttpException(422, 'Payout decision is invalid.', [
                'decision' => ['Choose Approved, Paid, or Rejected.'],
            ]);
        }
        if ($decision === 'rejected' && (strlen($reason) < 5 || strlen($reason) > 500)) {
            throw new HttpException(422, 'A payout rejection reason is required.', [
                'reason' => ['Use between 5 and 500 characters.'],
            ]);
        }
        if (strlen($reason) > 500) {
            throw new HttpException(422, 'The payout note is too long.', [
                'reason' => ['Use no more than 500 characters.'],
            ]);
        }
        return ['decision' => $decision, 'reason' => $reason];
    }

    /** @param array<string, mixed> $input
     *  @return array<string, mixed>
     */
    public static function category(array $input): array
    {
        $name = trim((string) ($input['name'] ?? ''));
        $slugSource = trim((string) ($input['slug'] ?? $name));
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($slugSource)), '-');
        $description = trim((string) ($input['description'] ?? ''));
        $parentId = (int) ($input['parent_id'] ?? 0);
        $sortOrder = (int) ($input['sort_order'] ?? 0);

        $errors = [];
        if (strlen($name) < 2 || strlen($name) > 100) {
            $errors['name'] = ['Use between 2 and 100 characters.'];
        }
        if ($slug === '' || strlen($slug) > 120) {
            $errors['slug'] = ['Use a valid URL-friendly category slug.'];
        }
        if (strlen($description) > 500) {
            $errors['description'] = ['Description must not exceed 500 characters.'];
        }
        if ($sortOrder < 0 || $sortOrder > 9999) {
            $errors['sort_order'] = ['Sort order must be between 0 and 9,999.'];
        }
        if ($errors !== []) {
            throw new HttpException(422, 'Category details are invalid.', $errors);
        }

        return [
            'name' => $name,
            'slug' => $slug,
            'description' => $description,
            'parent_id' => $parentId > 0 ? $parentId : null,
            'sort_order' => $sortOrder,
            'is_active' => self::boolean($input['is_active'] ?? true),
            'requires_listing_approval' => self::boolean(
                $input['requires_listing_approval'] ?? true
            ),
        ];
    }

    /** @param array<string, mixed> $input
     *  @return array<string, mixed>
     */
    public static function specification(array $input): array
    {
        $codeSource = trim((string) ($input['code'] ?? ''));
        $code = trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower($codeSource)), '_');
        $displayName = trim((string) ($input['display_name'] ?? ''));
        $dataType = strtolower(trim((string) ($input['data_type'] ?? '')));
        $unit = trim((string) ($input['unit'] ?? ''));
        $minimum = trim((string) ($input['minimum_value'] ?? ''));
        $maximum = trim((string) ($input['maximum_value'] ?? ''));
        $sortOrder = (int) ($input['sort_order'] ?? 0);
        $allowedTypes = ['text', 'integer', 'decimal', 'boolean', 'option', 'multi_option'];
        $errors = [];

        if ($code === '' || strlen($code) > 100) {
            $errors['code'] = ['Use a short code such as cpu_socket.'];
        }
        if (strlen($displayName) < 2 || strlen($displayName) > 120) {
            $errors['display_name'] = ['Use between 2 and 120 characters.'];
        }
        if (!in_array($dataType, $allowedTypes, true)) {
            $errors['data_type'] = ['Choose a supported specification type.'];
        }
        if (strlen($unit) > 30) {
            $errors['unit'] = ['Unit must not exceed 30 characters.'];
        }
        if ($minimum !== '' && !is_numeric($minimum)) {
            $errors['minimum_value'] = ['Minimum value must be numeric.'];
        }
        if ($maximum !== '' && !is_numeric($maximum)) {
            $errors['maximum_value'] = ['Maximum value must be numeric.'];
        }
        if (
            $minimum !== ''
            && $maximum !== ''
            && is_numeric($minimum)
            && is_numeric($maximum)
            && (float) $minimum > (float) $maximum
        ) {
            $errors['maximum_value'] = ['Maximum value must be greater than the minimum.'];
        }
        if ($sortOrder < 0 || $sortOrder > 9999) {
            $errors['sort_order'] = ['Sort order must be between 0 and 9,999.'];
        }

        $options = [];
        foreach ((array) ($input['options'] ?? []) as $option) {
            $display = trim((string) (is_array($option) ? ($option['display_value'] ?? '') : $option));
            $valueSource = trim((string) (
                is_array($option) ? ($option['value_code'] ?? $display) : $display
            ));
            $valueCode = trim(
                (string) preg_replace('/[^a-z0-9]+/', '_', strtolower($valueSource)),
                '_'
            );
            if ($display === '' || strlen($display) > 120 || $valueCode === '') {
                $errors['options'] = ['Every option needs a valid display value.'];
                break;
            }
            $options[$valueCode] = $display;
        }
        if (in_array($dataType, ['option', 'multi_option'], true) && $options === []) {
            $errors['options'] = ['Add at least one controlled option.'];
        }
        if ($errors !== []) {
            throw new HttpException(422, 'Specification details are invalid.', $errors);
        }

        return [
            'code' => $code,
            'display_name' => $displayName,
            'data_type' => $dataType,
            'unit' => $unit,
            'is_required' => self::boolean($input['is_required'] ?? false),
            'is_filterable' => self::boolean($input['is_filterable'] ?? true),
            'is_compatibility_field' => self::boolean(
                $input['is_compatibility_field'] ?? false
            ),
            'minimum_value' => $minimum === '' ? null : $minimum,
            'maximum_value' => $maximum === '' ? null : $maximum,
            'sort_order' => $sortOrder,
            'is_active' => self::boolean($input['is_active'] ?? true),
            'options' => $options,
        ];
    }

    /** @param array<string, mixed> $input
     *  @return array{status: string, reason: string}
     */
    public static function listingDecision(array $input): array
    {
        $status = strtolower(trim((string) ($input['status'] ?? '')));
        $reason = trim((string) ($input['reason'] ?? ''));
        $allowed = ['active', 'rejected', 'hidden', 'flagged', 'inactive'];
        if (!in_array($status, $allowed, true)) {
            throw new HttpException(422, 'Listing decision is invalid.', [
                'status' => ['Choose Active, Rejected, Hidden, Flagged, or Inactive.'],
            ]);
        }
        if ($status !== 'active' && (strlen($reason) < 5 || strlen($reason) > 500)) {
            throw new HttpException(422, 'A listing decision reason is required.', [
                'reason' => ['Use between 5 and 500 characters.'],
            ]);
        }
        return ['status' => $status, 'reason' => $reason];
    }

    /** @param array<string, mixed> $input
     *  @return array{status: string, note: string}
     */
    public static function queueDecision(array $input, string $queue): array
    {
        $status = strtolower(trim((string) ($input['status'] ?? '')));
        $note = trim((string) ($input['note'] ?? ''));
        $allowed = match ($queue) {
            'complaint' => ['under_review', 'resolved', 'dismissed'],
            'report' => ['under_review', 'dismissed', 'actioned'],
            'flag' => ['dismissed', 'actioned'],
            default => [],
        };
        if (!in_array($status, $allowed, true)) {
            throw new HttpException(422, 'Queue decision is invalid.', [
                'status' => ['Choose a supported decision.'],
            ]);
        }
        $needsNote = !in_array($status, ['under_review'], true);
        if ($needsNote && (strlen($note) < 5 || strlen($note) > 2000)) {
            throw new HttpException(422, 'A review note is required.', [
                'note' => ['Use between 5 and 2,000 characters.'],
            ]);
        }
        return ['status' => $status, 'note' => $note];
    }
}
