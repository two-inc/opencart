<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\Two\Core;

/**
 * The company a buyer captured with the company search, as the session and a
 * saved address keep it (TWO-26721): the rules, with no OpenCart in them.
 *
 * A capture is `company_name`, `organization_number`, `country_code` (ISO-2,
 * upper case) and `source` (`search`, `manual` or `sole_trader`). A saved
 * address keeps one in its own `custom_field`, under KEY, only while its name
 * is still the address's company.
 */
final class CompanyCapture
{
    /** The key a saved address's `custom_field` keeps the capture under. */
    public const KEY = 'two_company';

    public const SOURCES = ['search', 'manual', 'sole_trader'];

    /**
     * A capture from a `save_company` post, or null when the post is not one.
     * Manual entry is the name only, as on the sibling plugins, so a manual
     * post never carries a number.
     *
     * @param array<string, string> $post trimmed and entity-decoded fields: company_name, organization_number, country, source
     * @return array{company_name: string, organization_number: string, country_code: string, source: string}|null
     */
    public static function fromPost(array $post): ?array
    {
        $capture = [
            'company_name' => $post['company_name'] ?? '',
            'organization_number' => ($post['source'] ?? '') === 'manual' ? '' : ($post['organization_number'] ?? ''),
            'country_code' => strtoupper($post['country'] ?? ''),
            'source' => $post['source'] ?? '',
        ];
        if (!in_array($capture['source'], self::SOURCES, true)
            || preg_match('~^[A-Z]{2}\z~', $capture['country_code']) !== 1
            || preg_match('~[\x00-\x1F\x7F]~', $capture['company_name'] . $capture['organization_number']) === 1) {
            return null;
        }
        foreach (OrderRecords::MAX_LENGTHS as $field => $max) {
            if (mb_strlen($capture[$field]) > $max) {
                return null;
            }
        }

        return $capture;
    }

    /**
     * A stored capture in the canonical shape, or null when it holds no name.
     *
     * @return array{company_name: string, organization_number: string, country_code: string, source: string}|null
     */
    public static function normalise(mixed $capture): ?array
    {
        if (!is_array($capture) || !is_scalar($capture['company_name'] ?? null) || trim((string) $capture['company_name']) === '') {
            return null;
        }
        $source = (string) ($capture['source'] ?? 'search');

        return [
            'company_name' => trim((string) $capture['company_name']),
            'organization_number' => $source === 'manual' ? '' : trim((string) ($capture['organization_number'] ?? '')),
            'country_code' => strtoupper(trim((string) ($capture['country_code'] ?? ''))),
            'source' => in_array($source, self::SOURCES, true) ? $source : 'search',
        ];
    }

    /**
     * What a saved address keeps, and nothing else: the address edit form is
     * seeded from this alone, never from a capture made on another address.
     *
     * @return array{company_name: string, organization_number: string, country_code: string, source: string}|null
     */
    public static function ofAddress(mixed $address): ?array
    {
        return self::normalise(is_array($address) && is_array($address['custom_field'] ?? null) ? ($address['custom_field'][self::KEY] ?? null) : null);
    }

    /**
     * The `custom_field` to save with an address. Whatever arrived under KEY
     * is dropped (the form's own post cannot set it). The capture kept is the
     * first of the session's captures, in the roles' order, whose name is the
     * address's company; else the one the address already holds, on the same
     * terms, and only when that address is the customer's own. A kept capture
     * with no number takes the number of a same-named one that has it.
     *
     * @param array<mixed> $customField what OpenCart is about to store
     * @param array<string, mixed> $captures the session's captures by role
     * @param list<string> $roles the roles to try, in order
     * @param array<string, mixed> $stored the address as stored now ([] for a new one), with its customer_id
     * @return array<mixed>
     */
    public static function keep(array $customField, string $company, array $captures, array $roles, array $stored, int $customerId): array
    {
        unset($customField[self::KEY]);
        // OpenCart HTML-escapes every posted value; the capture holds the plain name.
        $name = trim(html_entity_decode($company, ENT_QUOTES, 'UTF-8'));
        if ($name === '') {
            return $customField;
        }
        $same = static fn (?array $capture): bool => $capture !== null && $capture['company_name'] === $name;
        $held = (int) ($stored['customer_id'] ?? 0) === $customerId && $customerId > 0
            ? self::normalise(is_array($stored['custom_field'] ?? null) ? ($stored['custom_field'][self::KEY] ?? null) : null)
            : null;
        $candidates = [];
        foreach ($roles as $role) {
            $candidates[] = self::normalise($captures[$role] ?? null);
        }
        $candidates[] = $held;
        $kept = null;
        foreach ($candidates as $capture) {
            if ($same($capture)) {
                $kept = $kept ?? $capture;
                if ($kept['organization_number'] === '' && $kept['source'] !== 'manual' && $capture['organization_number'] !== '' && self::sameCompany($kept, $capture)) {
                    $kept['organization_number'] = $capture['organization_number'];
                }
            }
        }
        if ($kept !== null) {
            $customField[self::KEY] = $kept;
        }

        return $customField;
    }

    /**
     * The company for an address role: on a saved address, what that address
     * keeps as stored (its row, read for the signed-in customer only; null
     * when there is no such row), its number topped up from the session's same-named capture where
     * it has none (the number lands a moment after the pick), and with none
     * at all where the session's capture is a manual entry of that name; on a
     * new address, what the buyer captured in its form this session.
     *
     * @param mixed $address the session's address for the role
     * @param array<mixed>|null $storedCustomField the stored row's `custom_field`, null when there is no row
     * @return array{company_name: string, organization_number: string, country_code: string, source: string}|null
     */
    public static function forAddress(mixed $address, mixed $sessionCapture, ?array $storedCustomField): ?array
    {
        $session = self::normalise($sessionCapture);
        if (!is_array($address) || (int) ($address['address_id'] ?? 0) <= 0) {
            return $session;
        }
        // The stored row, never the session's copy of the address: OpenCart
        // keeps the posted form there when it registers an account at checkout,
        // without what the address-saving event added.
        $kept = $storedCustomField === null ? null : self::ofAddress(['custom_field' => $storedCustomField]);
        // Enter manually on the address's own company disowns the number the row
        // kept, including one an address save racing the manual save stored (TWO-26775).
        if ($kept !== null && $session !== null && $session['source'] === 'manual' && $session['company_name'] === $kept['company_name']) {
            return $session;
        }
        if ($kept !== null && $kept['organization_number'] === '' && $kept['source'] !== 'manual'
            && $session !== null && self::sameCompany($kept, $session)) {
            $kept['organization_number'] = $session['organization_number'];
        }

        return $kept;
    }

    /**
     * The address's capture with the number a later save supplied, or null
     * when nothing changes: same name, the address had none, and one arrived.
     *
     * @param array<mixed> $customField the address's stored `custom_field`
     * @param array{company_name: string, organization_number: string, country_code: string, source: string} $capture
     * @return array<mixed>|null
     */
    public static function topUp(array $customField, array $capture): ?array
    {
        $held = self::normalise($customField[self::KEY] ?? null);
        if ($held === null || !self::sameCompany($held, $capture) || $held['organization_number'] !== ''
            || $held['source'] === 'manual' || $capture['organization_number'] === '') {
            return null;
        }
        $customField[self::KEY] = array_replace($held, ['organization_number' => $capture['organization_number']]);

        return $customField;
    }

    /**
     * Whether two captures are one company, so one may lend the other its
     * number: the same name, from the same country's register, by the same
     * route (a sole trader's number is never a registered company's).
     *
     * @param array{company_name: string, organization_number: string, country_code: string, source: string} $a
     * @param array{company_name: string, organization_number: string, country_code: string, source: string} $b
     */
    private static function sameCompany(array $a, array $b): bool
    {
        return $a['company_name'] === $b['company_name'] && $a['country_code'] === $b['country_code'] && $a['source'] === $b['source'];
    }

    /**
     * Run a relay call and reload the stored session before anything is
     * kept, so this request's write-back at shutdown can never put back a
     * session older than a save that landed while the call was out.
     *
     * @template T
     * @param \Closure(): T $relay
     * @param \Closure(): void $reload
     * @param (\Closure(T): void)|null $keep
     * @return T
     */
    public static function relay(\Closure $relay, \Closure $reload, ?\Closure $keep = null): mixed
    {
        $answer = $relay();
        $reload();
        if ($keep !== null) {
            $keep($answer);
        }

        return $answer;
    }
}
