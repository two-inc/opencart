<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\Two\Core;

/**
 * Brand descriptor (Build Guide section 9). Every brand string the plugin shows
 * comes from here, so a white-label edition is a different descriptor (data
 * and assets), never a fork. The keys match the sibling plugins' descriptors.
 */
final class Brand
{
    private const REQUIRED = ['product_name', 'provider_full_name', 'about_url'];

    public function __construct(
        public readonly string $productName,
        public readonly string $providerFullName,
        public readonly string $aboutUrl,
        public readonly bool $intentApprovedNoticeEnabled = true,
        public readonly bool $intentDeclinedNoticeEnabled = true,
        public readonly ?string $intentApprovedNotice = null,
        public readonly ?string $intentDeclinedNotice = null,
        public readonly ?string $logo = null,
    ) {
    }

    public static function default(): self
    {
        return self::fromArray([
            'product_name' => 'Two',
            'provider_full_name' => 'Two',
            'about_url' => 'https://www.two.inc/what-is-two',
            'logo' => 'catalog/view/image/two_logo.svg',
        ]);
    }

    /**
     * @param array<string, mixed> $descriptor
     */
    public static function fromArray(array $descriptor): self
    {
        foreach (self::REQUIRED as $key) {
            if (!isset($descriptor[$key]) || !is_string($descriptor[$key]) || trim($descriptor[$key]) === '') {
                throw new \InvalidArgumentException('Brand descriptor is missing "' . $key . '".');
            }
        }

        // The order intent notices (section 6.3): each on unless the descriptor
        // says false; a value that is not a boolean keeps the default. The copy
        // is the catalogue's unless the descriptor overrides it.
        $flag = static fn (string $key): bool => is_bool($descriptor[$key] ?? null) ? $descriptor[$key] : true;
        $copy = static fn (string $key): ?string => is_string($descriptor[$key] ?? null) && trim($descriptor[$key]) !== '' ? $descriptor[$key] : null;

        return new self(
            $descriptor['product_name'],
            $descriptor['provider_full_name'],
            $descriptor['about_url'],
            $flag('intent_approved_notice_enabled'),
            $flag('intent_declined_notice_enabled'),
            $copy('intent_approved_notice'),
            $copy('intent_declined_notice'),
            // The brand mark, a path under the extension directory; a brand without one shows its name instead (TWO-27110).
            $copy('logo'),
        );
    }
}
